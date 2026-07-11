<?php
/**
 * Tests for recurring poller repair of states that otherwise rely only on
 * bounded Action Scheduler verification chains.
 *
 * @package AinePay\WooCommerce
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/wp-stubs.php';
require_once dirname( __DIR__ ) . '/includes/class-ainepay-order-helper.php';
require_once dirname( __DIR__ ) . '/includes/class-ainepay-order-sync.php';
require_once dirname( __DIR__ ) . '/includes/class-ainepay-poller.php';

/**
 * @covers Ainepay_Poller
 * @covers Ainepay_Order_Sync
 */
class PollerRepairTest extends TestCase {

	protected function setUp(): void {
		Ainepay_Test_Env::reset();
	}

	private function order( $id, $status, $ainepay_status, $oid ) {
		return Ainepay_Test_Env::add_order(
			new WC_Order(
				array(
					'id'     => $id,
					'status' => $status,
					'meta'   => array(
						'_ainepay_order_id' => $oid,
						'_ainepay_status'   => $ainepay_status,
					),
				)
			)
		);
	}

	private function payload( $oid, $status ) {
		return array(
			'orders' => array(
				array(
					'orderId' => $oid,
					'status'  => $status,
					'updated' => 'u1',
				),
			),
		);
	}

	public function test_poller_queries_all_repair_batches() {
		( new Ainepay_Poller() )->run();

		// Batches: [0] primary on-hold, [1] cancelled repair, [2] failed repair,
		// [3] unbacked success repair, [4] Woo-first refund repair.
		$this->assertCount( 5, Ainepay_Test_Env::$order_queries );

		// Cancelled repair has NO creation-age cutoff so an old native cancel whose
		// async action was dropped is still recovered (the High finding).
		$this->assertSame( array( 'cancelled' ), Ainepay_Test_Env::$order_queries[1]['status'] );
		$this->assertArrayNotHasKey( 'date_created', Ainepay_Test_Env::$order_queries[1] );

		// Failed repair keeps the age cutoff: nothing drives a failed order, so an
		// unbounded scan would re-query historical failed orders forever.
		$this->assertSame( array( 'failed' ), Ainepay_Test_Env::$order_queries[2]['status'] );
		$this->assertArrayHasKey( 'date_created', Ainepay_Test_Env::$order_queries[2] );

		$this->assertSame( array( 'processing', 'completed' ), Ainepay_Test_Env::$order_queries[3]['status'] );
		$this->assertSame( 'PAID', Ainepay_Test_Env::$order_queries[3]['meta_query'][1]['value'] );
		$this->assertArrayNotHasKey( 'date_created', Ainepay_Test_Env::$order_queries[3] );
		$this->assertSame( array( 'refunded' ), Ainepay_Test_Env::$order_queries[4]['status'] );
		$this->assertSame( 'REFUND', Ainepay_Test_Env::$order_queries[4]['meta_query'][1]['value'] );
		$this->assertArrayNotHasKey( 'date_created', Ainepay_Test_Env::$order_queries[4] );

		// Every batch is paged by a rotating cursor (starts at offset 0).
		foreach ( Ainepay_Test_Env::$order_queries as $q ) {
			$this->assertSame( 50, $q['limit'] );
			$this->assertSame( 0, $q['offset'] );
		}
	}

	/* --- rotating cursor: a stuck head must not starve the tail ----------- */

	public function test_refund_repair_cursor_advances_and_wraps_past_stuck_head() {
		// A full page (>= BATCH_LIMIT) of long-stuck refunded+PAID orders. The cursor
		// must advance each round so newer records behind the head are eventually
		// reached, then wrap back to the head once the tail is passed.
		for ( $i = 0; $i < 50; $i++ ) {
			$this->order( 7000 + $i, 'refunded', 'PAID', 'OID-STUCK-' . $i );
		}
		// Backend still PAID for everything: refresh_order preserves the Woo refund and
		// reschedules verify, so the head never drains — exactly the starvation risk.
		Ainepay_Test_Env::set_gateway( $this->payload( 'ignored', 'PAID' ) );

		$option = Ainepay_Poller::CURSOR_OPTION_PREFIX . 'refund';

		$this->assertSame( 0, (int) get_option( $option, 0 ) );
		( new Ainepay_Poller() )->run();
		// Full page returned => cursor advanced past it for the next round.
		$this->assertSame( 50, (int) get_option( $option, 0 ) );

		( new Ainepay_Poller() )->run();
		// Offset 50 is now past the tail (only 50 rows) => short page => wrap to 0.
		$this->assertSame( 0, (int) get_option( $option, 0 ) );
	}

	public function test_cursor_is_per_batch_independent() {
		// One refunded order (short page) keeps the refund cursor at 0; the cancelled
		// cursor is tracked separately and is unaffected.
		$this->order( 7100, 'refunded', 'PAID', 'OID-R' );
		Ainepay_Test_Env::set_gateway( $this->payload( 'OID-R', 'PAID' ) );

		( new Ainepay_Poller() )->run();

		$this->assertSame( 0, (int) get_option( Ainepay_Poller::CURSOR_OPTION_PREFIX . 'refund', 0 ) );
		$this->assertSame( 0, (int) get_option( Ainepay_Poller::CURSOR_OPTION_PREFIX . 'cancelled', 0 ) );
	}

	public function test_poller_reverts_unbacked_processing_when_backend_is_init() {
		$order = $this->order( 4101, 'processing', 'INIT', 'OID-INIT' );
		Ainepay_Test_Env::set_gateway( $this->payload( 'OID-INIT', 'INIT' ) );

		( new Ainepay_Poller() )->run();

		$this->assertTrue( $order->has_status( 'on-hold' ) );
	}

	public function test_terminal_repair_clears_paid_verify_bookkeeping_once() {
		$order = $this->order( 4109, 'processing', 'INIT', 'OID-CLEAR-ONCE' );
		$order->update_meta_data( '_ainepay_paid_verify_attempts', 2 );
		$order->update_meta_data( '_ainepay_paid_verify_failed', '1' );
		$before = $order->save_calls;
		Ainepay_Test_Env::set_gateway( $this->payload( 'OID-CLEAR-ONCE', 'INIT' ) );

		( new Ainepay_Poller() )->run();

		$this->assertSame( 0, $order->get_meta( '_ainepay_paid_verify_attempts' ) );
		$this->assertSame( '', $order->get_meta( '_ainepay_paid_verify_failed' ) );
		$this->assertSame( 2, $order->save_calls - $before, 'one apply_status save plus exactly one clear save' );
	}

	public function test_poller_reverts_unbacked_processing_when_backend_is_pending() {
		$order = $this->order( 4107, 'processing', 'INIT', 'OID-PENDING' );
		Ainepay_Test_Env::set_gateway( $this->payload( 'OID-PENDING', 'PENDING' ) );

		( new Ainepay_Poller() )->run();

		$this->assertTrue( $order->has_status( 'on-hold' ) );
		$this->assertSame( 'PENDING', $order->get_meta( '_ainepay_status' ) );
	}

	public function test_poller_backs_processing_when_backend_is_paid() {
		$order = $this->order( 4102, 'processing', 'INIT', 'OID-PAID' );
		Ainepay_Test_Env::set_gateway( $this->payload( 'OID-PAID', 'PAID' ) );

		( new Ainepay_Poller() )->run();

		$this->assertTrue( $order->has_status( 'processing' ) );
		$this->assertSame( 'PAID', $order->get_meta( '_ainepay_status' ) );
	}

	public function test_poller_preserves_woo_refund_while_backend_is_still_paid() {
		$order = $this->order( 4103, 'refunded', 'PAID', 'OID-REFUND-PENDING' );
		$order->update_meta_data( '_ainepay_refund_pending', '1' );
		Ainepay_Test_Env::set_gateway( $this->payload( 'OID-REFUND-PENDING', 'PAID' ) );

		( new Ainepay_Poller() )->run();

		$this->assertTrue( $order->has_status( 'refunded' ) );
		$this->assertSame( 'PAID', $order->get_meta( '_ainepay_status' ) );
		$this->assertSame( 1, (int) $order->get_meta( '_ainepay_refund_merchant_attempts' ) );
		$this->assertCount( 1, Ainepay_Test_Env::$scheduled );
	}

	public function test_poller_does_not_consume_merchant_budget_when_verify_is_already_scheduled() {
		$order = $this->order( 4108, 'refunded', 'PAID', 'OID-REFUND-QUEUED' );
		$order->update_meta_data( '_ainepay_refund_pending', '1' );
		$order->update_meta_data( '_ainepay_refund_merchant_attempts', 4 );
		Ainepay_Test_Env::set_gateway( $this->payload( 'OID-REFUND-QUEUED', 'PAID' ) );
		as_schedule_single_action(
			time() + HOUR_IN_SECONDS,
			Ainepay_Order_Sync::REFUND_VERIFY_HOOK,
			array( 'OID-REFUND-QUEUED' ),
			'ainepay',
			true
		);

		( new Ainepay_Poller() )->run();

		$this->assertSame( 4, (int) $order->get_meta( '_ainepay_refund_merchant_attempts' ) );
		$this->assertCount( 1, Ainepay_Test_Env::$scheduled );
	}

	public function test_poller_closes_woo_refund_when_backend_confirms_refund() {
		$order = $this->order( 4104, 'refunded', 'PAID', 'OID-REFUNDED' );
		Ainepay_Test_Env::set_gateway( $this->payload( 'OID-REFUNDED', 'REFUND' ) );

		( new Ainepay_Poller() )->run();

		$this->assertTrue( $order->has_status( 'refunded' ) );
		$this->assertSame( 'REFUND', $order->get_meta( '_ainepay_status' ) );
	}

	public function test_poller_skips_already_backed_success_and_refund() {
		$this->order( 4105, 'processing', 'PAID', 'OID-BACKED-PAID' );
		$this->order( 4106, 'refunded', 'REFUND', 'OID-BACKED-REFUND' );
		$client = Ainepay_Test_Env::set_gateway( $this->payload( 'OID-BACKED-PAID', 'PAID' ) );

		( new Ainepay_Poller() )->run();

		$this->assertSame( 0, $client->get_orders_calls );
	}

	/* --- cancelled re-drive: native cancel whose async reconcile was dropped - */

	public function test_poller_redrives_cancel_for_unbacked_cancelled_init_order() {
		// WC=cancelled but backend still INIT (the immediate reconcile action was
		// dropped). The poller must drive the cancel-first coordinator, not a plain
		// status refresh whose INIT branch is a no-op for a cancelled order.
		$order  = $this->order( 4109, 'cancelled', '', 'OID-CANCEL-INIT' );
		$client = Ainepay_Test_Env::set_gateway( null, array( 'orderId' => 'OID-CANCEL-INIT', 'status' => 'CANCEL' ) );

		( new Ainepay_Poller() )->run();

		$this->assertSame( 1, $client->cancel_calls );
		$this->assertTrue( $order->has_status( 'cancelled' ) );
		$this->assertSame( 'CANCEL', $order->get_meta( '_ainepay_status' ) );
	}

	public function test_poller_repairs_cancelled_order_the_backend_reports_paid() {
		// A settle race left the order WC=cancelled while the backend is really PAID.
		// Re-driving cancel hits NOT_INIT (code 26) and reconciles to PAID, repairing
		// the order back to processing instead of losing the payment as cancelled.
		// Make the order non-virtual so WooCommerce's paid target is processing;
		// an empty-item fixture is intentionally treated as virtual/completable.
		$order = Ainepay_Test_Env::add_order(
			new WC_Order(
				array(
					'id'     => 4110,
					'status' => 'cancelled',
					'meta'   => array(
						'_ainepay_order_id' => 'OID-CANCEL-PAID',
						'_ainepay_status'   => '',
					),
					'items'  => array( new Ainepay_Fake_Item( new Ainepay_Fake_Product( false ) ) ),
				)
			)
		);
		$client = Ainepay_Test_Env::set_gateway(
			$this->payload( 'OID-CANCEL-PAID', 'PAID' ),
			new WP_Error( 'ainepay_api_error', 'invalid', array( 'code' => 26 ) )
		);

		( new Ainepay_Poller() )->run();

		$this->assertSame( 1, $client->cancel_calls );
		$this->assertTrue( $order->has_status( 'processing' ) );
		$this->assertSame( 'PAID', $order->get_meta( '_ainepay_status' ) );
	}

	public function test_poller_does_not_redrive_cancel_when_cancel_sync_is_already_scheduled() {
		// The dedicated cancel-sync worker still owns the retry: the poller must not
		// re-drive (and consume budget) in parallel.
		$this->order( 4111, 'cancelled', '', 'OID-CANCEL-QUEUED' );
		$client = Ainepay_Test_Env::set_gateway( null, array( 'orderId' => 'OID-CANCEL-QUEUED', 'status' => 'CANCEL' ) );
		as_schedule_single_action(
			time() + HOUR_IN_SECONDS,
			Ainepay_Order_Sync::CANCEL_SYNC_HOOK,
			array( 'OID-CANCEL-QUEUED' ),
			'ainepay',
			true
		);

		( new Ainepay_Poller() )->run();

		$this->assertSame( 0, $client->cancel_calls );
	}
}
