<?php
/**
 * Tests for the cancel-first state machine: request_cancel()'s outcome codes and
 * reconcile_via_query()'s authoritative repair, the woocommerce_order_status_
 * cancelled safety net, and the persistent retry worker. The governing rule is
 * that the plugin never marks an order cancelled unless the backend confirms it,
 * and a settle race that left the order PAID is repaired rather than lost.
 *
 * @package AinePay\WooCommerce
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/wp-stubs.php';
require_once dirname( __DIR__ ) . '/includes/class-ainepay-order-helper.php';
require_once dirname( __DIR__ ) . '/includes/class-ainepay-order-sync.php';

/**
 * @covers Ainepay_Order_Sync
 */
class OrderSyncCancelTest extends TestCase {

	protected function setUp(): void {
		Ainepay_Test_Env::reset();
	}

	/**
	 * @param array<string,mixed> $meta   Order meta.
	 * @param string              $status WC status.
	 * @param string              $pm     Payment method.
	 * @return WC_Order
	 */
	private function order( array $meta = array( '_ainepay_order_id' => 'OID' ), $status = 'on-hold', $pm = 'ainepay', array $items = array() ) {
		static $id = 2000;
		$id++;
		return Ainepay_Test_Env::add_order(
			new WC_Order(
				array(
					'id'             => $id,
					'status'         => $status,
					'payment_method' => $pm,
					'meta'           => $meta,
					'items'          => $items,
				)
			)
		);
	}

	/**
	 * A line item WooCommerce marks as having reduced stock (physical ground truth).
	 *
	 * @return Ainepay_Fake_Item
	 */
	private function reduced_item() {
		return new Ainepay_Fake_Item( new Ainepay_Fake_Product( false ), array( '_reduced_stock' => 1 ) );
	}

	/**
	 * @param string $status Authoritative status to return from get_orders().
	 * @return array
	 */
	private function orders_payload( $status, $oid = 'OID' ) {
		return array( 'orders' => array( array( 'orderId' => $oid, 'status' => $status, 'updated' => 'u1' ) ) );
	}

	/* --- guard clauses ---------------------------------------------------- */

	public function test_skips_non_ainepay_order() {
		$order = $this->order( array( '_ainepay_order_id' => 'OID' ), 'on-hold', 'stripe' );
		$this->assertSame( Ainepay_Order_Sync::CANCEL_SKIPPED, Ainepay_Order_Sync::request_cancel( $order ) );
	}

	public function test_skips_order_without_ainepay_id() {
		$order = $this->order( array() );
		$this->assertSame( Ainepay_Order_Sync::CANCEL_SKIPPED, Ainepay_Order_Sync::request_cancel( $order ) );
	}

	public function test_retries_when_gateway_unavailable() {
		$order = $this->order();
		// No gateway installed.
		$this->assertSame( Ainepay_Order_Sync::CANCEL_RETRY, Ainepay_Order_Sync::request_cancel( $order ) );
		$this->assertCount( 1, Ainepay_Test_Env::$scheduled );
		$this->assertSame( Ainepay_Order_Sync::CANCEL_SYNC_HOOK, Ainepay_Test_Env::$scheduled[0]['hook'] );
		$this->assertSame( '1', $order->get_meta( '_ainepay_cancel_pending' ) );
	}

	public function test_retries_on_lock_contention() {
		$order = $this->order();
		Ainepay_Test_Env::set_gateway( null, array( 'orderId' => 'OID', 'status' => 'CANCEL' ) );
		Ainepay_Test_Env::$lock_result = '0';
		$this->assertSame( Ainepay_Order_Sync::CANCEL_RETRY, Ainepay_Order_Sync::request_cancel( $order ) );
		$this->assertCount( 1, Ainepay_Test_Env::$scheduled );
	}

	/* --- trusted CANCEL success ------------------------------------------- */

	public function test_confirmed_cancel_marks_order_cancelled() {
		$order  = $this->order( array( '_ainepay_order_id' => 'OID', '_ainepay_cancel_pending' => '1' ) );
		Ainepay_Test_Env::set_gateway( null, array( 'orderId' => 'OID', 'status' => 'CANCEL' ) );
		$this->assertSame( Ainepay_Order_Sync::CANCEL_DONE, Ainepay_Order_Sync::request_cancel( $order ) );
		$this->assertTrue( $order->has_status( 'cancelled' ) );
		$this->assertSame( 'CANCEL', $order->get_meta( '_ainepay_status' ) );
		$this->assertSame( '', $order->get_meta( '_ainepay_cancel_pending' ) );
	}

	public function test_successful_cancel_clears_stale_cancel_failed_flag() {
		// A prior attempt flagged the order failed (permanent error / retry-cap /
		// schedule drop). A later retry or poll that finally confirms CANCEL must
		// clear the stale flag so the order is not surfaced as "cancel failed" forever.
		$order = $this->order(
			array(
				'_ainepay_order_id'      => 'OID',
				'_ainepay_cancel_failed' => '1',
				'_ainepay_cancel_pending' => '1',
			)
		);
		Ainepay_Test_Env::set_gateway( null, array( 'orderId' => 'OID', 'status' => 'CANCEL' ) );
		$this->assertSame( Ainepay_Order_Sync::CANCEL_DONE, Ainepay_Order_Sync::request_cancel( $order ) );
		$this->assertSame( '', $order->get_meta( '_ainepay_cancel_failed' ) );
		$this->assertSame( '', $order->get_meta( '_ainepay_cancel_pending' ) );
	}

	public function test_cross_order_success_is_not_trusted_and_reconciles() {
		// Backend returns success for a DIFFERENT orderId: must not blindly cancel.
		$order = $this->order();
		Ainepay_Test_Env::set_gateway(
			$this->orders_payload( 'PAID' ),
			array( 'orderId' => 'SOMEONE_ELSE', 'status' => 'CANCEL' )
		);
		$this->assertSame( Ainepay_Order_Sync::CANCEL_PAID, Ainepay_Order_Sync::request_cancel( $order ) );
		$this->assertSame( 'PAID', $order->get_meta( '_ainepay_status' ) );
	}

	public function test_matching_order_with_non_cancel_success_is_not_trusted() {
		$order = $this->order();
		Ainepay_Test_Env::set_gateway(
			$this->orders_payload( 'PAID' ),
			array( 'orderId' => 'OID', 'status' => 'PAID' )
		);

		$this->assertSame( Ainepay_Order_Sync::CANCEL_PAID, Ainepay_Order_Sync::request_cancel( $order ) );
		$this->assertTrue( $order->has_status( array( 'processing', 'completed' ) ) );
		$this->assertSame( 'PAID', $order->get_meta( '_ainepay_status' ) );
	}

	public function test_empty_success_body_falls_back_to_authoritative_query() {
		$order = $this->order();
		Ainepay_Test_Env::set_gateway(
			$this->orders_payload( 'CANCEL' ),
			array()
		);

		$this->assertSame( Ainepay_Order_Sync::CANCEL_DONE, Ainepay_Order_Sync::request_cancel( $order ) );
		$this->assertTrue( $order->has_status( 'cancelled' ) );
		$this->assertSame( 'CANCEL', $order->get_meta( '_ainepay_status' ) );
	}

	/* --- reconcile_via_query (via NOT_INIT code 26) ----------------------- */

	public function test_not_init_with_backend_paid_repairs_to_paid() {
		$order = $this->order();
		Ainepay_Test_Env::set_gateway(
			$this->orders_payload( 'PAID' ),
			new WP_Error( 'ainepay_api_error', 'invalid', array( 'code' => 26 ) )
		);
		$this->assertSame( Ainepay_Order_Sync::CANCEL_PAID, Ainepay_Order_Sync::request_cancel( $order ) );
		$this->assertTrue( $order->has_status( array( 'processing', 'completed' ) ) );
	}

	public function test_not_init_with_backend_cancel_reports_done() {
		$order = $this->order();
		Ainepay_Test_Env::set_gateway(
			$this->orders_payload( 'CANCEL' ),
			new WP_Error( 'ainepay_api_error', 'invalid', array( 'code' => 26 ) )
		);
		$this->assertSame( Ainepay_Order_Sync::CANCEL_DONE, Ainepay_Order_Sync::request_cancel( $order ) );
		$this->assertTrue( $order->has_status( 'cancelled' ) );
	}

	public function test_not_init_with_backend_pending_keeps_on_hold() {
		$order = $this->order();
		Ainepay_Test_Env::set_gateway(
			$this->orders_payload( 'PENDING' ),
			new WP_Error( 'ainepay_api_error', 'invalid', array( 'code' => 26 ) )
		);
		$this->assertSame( Ainepay_Order_Sync::CANCEL_PENDING, Ainepay_Order_Sync::request_cancel( $order ) );
		$this->assertTrue( $order->has_status( 'on-hold' ) );
	}

	public function test_not_init_with_backend_expired_reports_reconciled() {
		$order = $this->order();
		Ainepay_Test_Env::set_gateway(
			$this->orders_payload( 'EXPIRED' ),
			new WP_Error( 'ainepay_api_error', 'invalid', array( 'code' => 26 ) )
		);
		$this->assertSame( Ainepay_Order_Sync::CANCEL_RECONCILED, Ainepay_Order_Sync::request_cancel( $order ) );
		$this->assertTrue( $order->has_status( 'failed' ) );
	}

	public function test_reconcile_retries_when_status_unknown() {
		$order = $this->order();
		Ainepay_Test_Env::set_gateway(
			array( 'orders' => array() ), // empty => query_status null => unknown.
			new WP_Error( 'ainepay_api_error', 'invalid', array( 'code' => 26 ) )
		);
		$this->assertSame( Ainepay_Order_Sync::CANCEL_RETRY, Ainepay_Order_Sync::request_cancel( $order ) );
		$this->assertCount( 1, Ainepay_Test_Env::$scheduled );
		$this->assertTrue( $order->has_status( 'on-hold' ) );
	}

	/* --- error classification --------------------------------------------- */

	public function test_transient_error_keeps_on_hold_and_retries() {
		$order = $this->order();
		Ainepay_Test_Env::set_gateway(
			null,
			new WP_Error( 'ainepay_api_error', 'boom', array( 'status' => 500 ) )
		);
		$this->assertSame( Ainepay_Order_Sync::CANCEL_RETRY, Ainepay_Order_Sync::request_cancel( $order ) );
		$this->assertTrue( $order->has_status( 'on-hold' ) );
		$this->assertCount( 1, Ainepay_Test_Env::$scheduled );
	}

	public function test_rate_limited_error_is_transient() {
		$order = $this->order();
		Ainepay_Test_Env::set_gateway(
			null,
			new WP_Error( 'ainepay_api_error', 'slow down', array( 'code' => 19 ) )
		);
		$this->assertSame( Ainepay_Order_Sync::CANCEL_RETRY, Ainepay_Order_Sync::request_cancel( $order ) );
	}

	public function test_permanent_error_flags_for_manual_review() {
		$order = $this->order();
		Ainepay_Test_Env::set_gateway(
			null,
			new WP_Error( 'ainepay_bad_request', 'bad params', array( 'code' => 18 ) )
		);
		$this->assertSame( Ainepay_Order_Sync::CANCEL_FAILED, Ainepay_Order_Sync::request_cancel( $order ) );
		$this->assertSame( '1', $order->get_meta( '_ainepay_cancel_failed' ) );
		$this->assertTrue( $order->has_status( 'on-hold' ) );
	}

	/* --- woocommerce_order_status_cancelled safety net -------------------- */

	public function test_safety_net_enqueues_reconcile_for_unbacked_cancel() {
		$order = $this->order( array( '_ainepay_order_id' => 'OID' ), 'cancelled' );
		Ainepay_Order_Sync::on_wc_cancelled( $order->get_id() );
		$this->assertCount( 1, Ainepay_Test_Env::$scheduled );
		$this->assertSame( Ainepay_Order_Sync::CANCEL_SYNC_HOOK, Ainepay_Test_Env::$scheduled[0]['hook'] );
		$this->assertSame( array( 'OID' ), Ainepay_Test_Env::$scheduled[0]['args'] );
		// The cancel intent is persisted so a later dropped action stays recoverable.
		$this->assertSame( '1', $order->get_meta( '_ainepay_cancel_pending' ) );
	}

	public function test_safety_net_enqueue_failure_falls_back_to_loud_scheduled_retry() {
		// Native admin cancel already moved WC to cancelled; the immediate async
		// enqueue fails. This must NOT silently strand the cancel: it falls back to
		// schedule_cancel_sync, which (also unable to schedule here) fails loud.
		$order = $this->order( array( '_ainepay_order_id' => 'OID' ), 'cancelled' );
		Ainepay_Test_Env::$schedule_fails = true;
		Ainepay_Order_Sync::on_wc_cancelled( $order->get_id() );
		$this->assertSame( array(), Ainepay_Test_Env::$scheduled );
		$this->assertSame( '1', $order->get_meta( '_ainepay_cancel_failed' ) );
		$this->assertSame( 'OID', get_transient( 'ainepay_cancel_stuck_' . $order->get_id() ) );
		$this->assertContains( 'ainepay_cancel_stuck', array_column( Ainepay_Test_Env::$actions, 'hook' ) );
	}

	public function test_safety_net_skips_already_backend_cancelled() {
		$order = $this->order(
			array( '_ainepay_order_id' => 'OID', '_ainepay_status' => 'CANCEL' ),
			'cancelled'
		);
		Ainepay_Order_Sync::on_wc_cancelled( $order->get_id() );
		$this->assertSame( array(), Ainepay_Test_Env::$scheduled );
	}

	public function test_safety_net_skips_non_ainepay_order() {
		$order = $this->order( array( '_ainepay_order_id' => 'OID' ), 'cancelled', 'stripe' );
		Ainepay_Order_Sync::on_wc_cancelled( $order->get_id() );
		$this->assertSame( array(), Ainepay_Test_Env::$scheduled );
	}

	/* --- persistent retry worker ------------------------------------------ */

	public function test_retry_worker_stops_when_already_backed() {
		$order = $this->order(
			array(
				'_ainepay_order_id'       => 'OID',
				'_ainepay_status'         => 'CANCEL',
				'_ainepay_cancel_pending' => '1',
			),
			'cancelled'
		);
		Ainepay_Test_Env::set_gateway( null, array( 'orderId' => 'OID', 'status' => 'CANCEL' ) );
		Ainepay_Order_Sync::handle_cancel_sync( 'OID' );
		// No cancel call made; pending cleared.
		$this->assertSame( 0, Ainepay_Test_Env::$gateway->get_api_client()->cancel_calls );
		$this->assertSame( '', $order->get_meta( '_ainepay_cancel_pending' ) );
	}

	public function test_retry_worker_drives_cancel_when_unbacked() {
		$this->order( array( '_ainepay_order_id' => 'OID' ), 'on-hold' );
		$client = Ainepay_Test_Env::set_gateway( null, array( 'orderId' => 'OID', 'status' => 'CANCEL' ) );
		Ainepay_Order_Sync::handle_cancel_sync( 'OID' );
		$this->assertSame( 1, $client->cancel_calls );
	}

	/* --- premature-restock marker re-assertion ---------------------------- */

	public function test_reassert_restores_marker_wc_core_cleared_for_gate_held_cancel() {
		// WC core's wc_maybe_increase_stock_levels clears the stock-reduced marker on
		// the cancelled transition even though our gate blocked the physical restore
		// (the item still carries _reduced_stock). The priority-20 re-assert must set
		// it back to true so the held stock is not later treated as already released.
		$order = $this->order(
			array( '_ainepay_order_id' => 'OID', '_order_stock_reduced' => '' ),
			'cancelled',
			'ainepay',
			array( $this->reduced_item() )
		);
		Ainepay_Order_Sync::reassert_held_stock_marker( $order->get_id() );
		$this->assertSame( 'yes', $order->get_meta( '_order_stock_reduced' ) );
	}

	public function test_reassert_restores_marker_for_fractional_reduced_quantity() {
		// Stores running decimal-quantity plugins reduce fractional amounts; WC stores
		// the raw float in _reduced_stock and its restore path treats any truthy value
		// as reduced. The physical-reduction guard must not truncate 0.5 to 0 and skip
		// the re-assert, or the held half unit would leak exactly like Bug1.
		$order = $this->order(
			array( '_ainepay_order_id' => 'OID', '_order_stock_reduced' => '' ),
			'cancelled',
			'ainepay',
			array( new Ainepay_Fake_Item( new Ainepay_Fake_Product( false ), array( '_reduced_stock' => '0.5' ) ) )
		);
		Ainepay_Order_Sync::reassert_held_stock_marker( $order->get_id() );
		$this->assertSame( 'yes', $order->get_meta( '_order_stock_reduced' ) );
	}

	public function test_reassert_is_noop_when_stock_already_physically_restored() {
		// The order was legitimately restocked before this cancel (e.g. an admin
		// on-hold -> pending transition), so no item carries _reduced_stock. Re-marking
		// it reduced would make a later PAID repair skip re-reduction and oversell, so
		// the re-assert must not fire despite the gate holding the cancel.
		$order = $this->order(
			array( '_ainepay_order_id' => 'OID', '_order_stock_reduced' => '' ),
			'cancelled',
			'ainepay',
			array( new Ainepay_Fake_Item( new Ainepay_Fake_Product( false ) ) )
		);
		Ainepay_Order_Sync::reassert_held_stock_marker( $order->get_id() );
		$this->assertSame( '', $order->get_meta( '_order_stock_reduced' ) );
	}

	public function test_reassert_is_noop_once_backend_cancel_is_confirmed() {
		// With _ainepay_status=CANCEL the gate no longer holds, so WC's own restock is
		// authoritative and the marker must not be re-asserted.
		$order = $this->order(
			array(
				'_ainepay_order_id'    => 'OID',
				'_ainepay_status'      => 'CANCEL',
				'_order_stock_reduced' => '',
			),
			'cancelled',
			'ainepay',
			array( $this->reduced_item() )
		);
		Ainepay_Order_Sync::reassert_held_stock_marker( $order->get_id() );
		$this->assertSame( '', $order->get_meta( '_order_stock_reduced' ) );
	}

	public function test_reassert_skips_non_ainepay_order() {
		$order = $this->order(
			array( '_ainepay_order_id' => 'OID', '_order_stock_reduced' => '' ),
			'cancelled',
			'stripe',
			array( $this->reduced_item() )
		);
		Ainepay_Order_Sync::reassert_held_stock_marker( $order->get_id() );
		$this->assertSame( '', $order->get_meta( '_order_stock_reduced' ) );
	}

	/* --- premature-restock release on confirmed native cancel ------------- */

	public function test_confirmed_cancel_releases_held_stock_for_already_cancelled_order() {
		// A native admin cancel already moved WC to cancelled and held the stock
		// (gate kept _order_stock_reduced=yes). The backend now confirms CANCEL via
		// the async reconcile (NOT_INIT -> re-query), so the held stock is released.
		$order = $this->order(
			array( '_ainepay_order_id' => 'OID', '_order_stock_reduced' => 'yes' ),
			'cancelled'
		);
		Ainepay_Test_Env::set_gateway(
			$this->orders_payload( 'CANCEL' ),
			new WP_Error( 'ainepay_api_error', 'invalid', array( 'code' => 26 ) )
		);
		$this->assertSame( Ainepay_Order_Sync::CANCEL_DONE, Ainepay_Order_Sync::request_cancel( $order ) );
		$this->assertSame( 1, $order->stock_increase_calls );
		$this->assertSame( '', $order->get_meta( '_order_stock_reduced' ) );
	}

	public function test_reconcile_to_expired_releases_held_stock_for_native_cancel() {
		// A native admin cancel held the stock (gate kept _order_stock_reduced=yes),
		// but the backend order turned out EXPIRED rather than INIT/CANCEL. The
		// cancelled->failed transition does not restock, and the gate had blocked the
		// cancelled-transition restock, so the held stock must be released here too.
		$order = $this->order(
			array( '_ainepay_order_id' => 'OID', '_order_stock_reduced' => 'yes' ),
			'cancelled'
		);
		Ainepay_Test_Env::set_gateway(
			$this->orders_payload( 'EXPIRED' ),
			new WP_Error( 'ainepay_api_error', 'invalid', array( 'code' => 26 ) )
		);
		$this->assertSame( Ainepay_Order_Sync::CANCEL_RECONCILED, Ainepay_Order_Sync::request_cancel( $order ) );
		$this->assertTrue( $order->has_status( 'failed' ) );
		$this->assertSame( 1, $order->stock_increase_calls );
		$this->assertSame( '', $order->get_meta( '_order_stock_reduced' ) );
	}

	public function test_normal_expiry_from_on_hold_does_not_release_stock() {
		// A plain on-hold->failed expiry was never gate-held, so the EXPIRED branch
		// must NOT change WooCommerce's own stock semantics for it.
		$order = $this->order(
			array( '_ainepay_order_id' => 'OID', '_order_stock_reduced' => 'yes' ),
			'on-hold'
		);
		Ainepay_Test_Env::set_gateway( $this->orders_payload( 'EXPIRED' ) );
		Ainepay_Order_Sync::handle_notification( array( 'orderId' => 'OID' ) );
		$this->assertTrue( $order->has_status( 'failed' ) );
		$this->assertSame( 0, $order->stock_increase_calls );
		$this->assertSame( 'yes', $order->get_meta( '_order_stock_reduced' ) );
	}

	public function test_cancel_of_on_hold_order_does_not_explicitly_release_stock() {
		// Cancel-first path: the cancelled transition fires, so WC restocks itself;
		// release_held_stock() must NOT also fire.
		$order = $this->order(
			array( '_ainepay_order_id' => 'OID', '_order_stock_reduced' => 'yes' ),
			'on-hold'
		);
		Ainepay_Test_Env::set_gateway( null, array( 'orderId' => 'OID', 'status' => 'CANCEL' ) );
		$this->assertSame( Ainepay_Order_Sync::CANCEL_DONE, Ainepay_Order_Sync::request_cancel( $order ) );
		$this->assertTrue( $order->has_status( 'cancelled' ) );
		$this->assertSame( 0, $order->stock_increase_calls );
	}

	/* --- scheduling: no fork, no silent drop ------------------------------ */

	public function test_repeated_transient_cancels_do_not_fork_retry_chains() {
		$order = $this->order();
		Ainepay_Test_Env::set_gateway(
			null,
			new WP_Error( 'ainepay_api_error', 'boom', array( 'status' => 500 ) )
		);
		Ainepay_Order_Sync::request_cancel( $order );
		Ainepay_Order_Sync::request_cancel( $order );
		// $unique de-duplicates: a single pending retry, not two parallel chains.
		$this->assertCount( 1, Ainepay_Test_Env::$scheduled );
		// And a deduped duplicate is NOT mistaken for a scheduling failure.
		$this->assertSame( '', $order->get_meta( '_ainepay_cancel_failed' ) );
		$this->assertNotContains( 'ainepay_cancel_stuck', array_column( Ainepay_Test_Env::$actions, 'hook' ) );
	}

	public function test_safety_net_enqueue_is_idempotent() {
		$order = $this->order( array( '_ainepay_order_id' => 'OID' ), 'cancelled' );
		Ainepay_Order_Sync::on_wc_cancelled( $order->get_id() );
		Ainepay_Order_Sync::on_wc_cancelled( $order->get_id() );
		$this->assertCount( 1, Ainepay_Test_Env::$scheduled );
	}

	public function test_failed_schedule_fails_loud_instead_of_silently_dropping() {
		$order = $this->order();
		Ainepay_Test_Env::set_gateway(
			null,
			new WP_Error( 'ainepay_api_error', 'boom', array( 'status' => 500 ) )
		);
		Ainepay_Test_Env::$schedule_fails = true;
		Ainepay_Order_Sync::request_cancel( $order );

		$this->assertSame( array(), Ainepay_Test_Env::$scheduled );
		$this->assertSame( '1', $order->get_meta( '_ainepay_cancel_failed' ) );
		$this->assertSame( 'OID', get_transient( 'ainepay_cancel_stuck_' . $order->get_id() ) );
		$this->assertContains( 'ainepay_cancel_stuck', array_column( Ainepay_Test_Env::$actions, 'hook' ) );
	}

	/* --- is_locally_cancellable predicate (UI / entry gate) --------------- */

	public function test_locally_cancellable_for_awaiting_init_order() {
		$order = $this->order( array( '_ainepay_order_id' => 'OID', '_ainepay_status' => 'INIT' ), 'on-hold' );
		$this->assertTrue( Ainepay_Order_Sync::is_locally_cancellable( $order ) );
	}

	public function test_locally_cancellable_for_awaiting_order_without_backing_yet() {
		// Order placed but no status query has run yet: empty backing is still INIT-ish.
		$order = $this->order( array( '_ainepay_order_id' => 'OID' ), 'on-hold' );
		$this->assertTrue( Ainepay_Order_Sync::is_locally_cancellable( $order ) );
	}

	public function test_not_locally_cancellable_when_backing_is_pending() {
		// The finding: poll/webhook recorded PENDING (on-chain payment seen) while WC
		// is still on-hold. A cancel would be rejected by the backend (code 26), so the
		// UI/entry must not offer it.
		$order = $this->order( array( '_ainepay_order_id' => 'OID', '_ainepay_status' => 'PENDING' ), 'on-hold' );
		$this->assertFalse( Ainepay_Order_Sync::is_locally_cancellable( $order ) );
	}

	public function test_not_locally_cancellable_for_settled_backings() {
		foreach ( array( 'PAID', 'EXPIRED', 'CANCEL', 'REFUND' ) as $settled ) {
			$order = $this->order( array( '_ainepay_order_id' => 'OID', '_ainepay_status' => $settled ), 'on-hold' );
			$this->assertFalse(
				Ainepay_Order_Sync::is_locally_cancellable( $order ),
				"backing $settled must not be locally cancellable"
			);
		}
	}

	public function test_not_locally_cancellable_for_non_awaiting_status() {
		$order = $this->order( array( '_ainepay_order_id' => 'OID', '_ainepay_status' => 'INIT' ), 'processing' );
		$this->assertFalse( Ainepay_Order_Sync::is_locally_cancellable( $order ) );
	}

	public function test_not_locally_cancellable_for_non_ainepay_or_unkeyed_order() {
		$foreign = $this->order( array( '_ainepay_order_id' => 'OID' ), 'on-hold', 'stripe' );
		$this->assertFalse( Ainepay_Order_Sync::is_locally_cancellable( $foreign ) );

		$unkeyed = $this->order( array(), 'on-hold' );
		$this->assertFalse( Ainepay_Order_Sync::is_locally_cancellable( $unkeyed ) );
	}
}
