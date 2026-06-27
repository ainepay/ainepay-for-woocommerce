<?php
/**
 * Tests for the manual two-step refund closure (full refunds only). The gateway
 * has no process_refund(), so a merchant refunds in WooCommerce first and then in
 * the AinePay dashboard; on_wc_fully_refunded() flags the order and verify_refund()
 * confirms out of band that AinePay reaches REFUND, escalating to a stuck alert if
 * it never does.
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
class OrderSyncRefundTest extends TestCase {

	protected function setUp(): void {
		Ainepay_Test_Env::reset();
	}

	/**
	 * @param array<string,mixed> $meta   Order meta.
	 * @param string              $status WC status.
	 * @param string              $pm     Payment method.
	 * @return WC_Order
	 */
	private function order( array $meta = array( '_ainepay_order_id' => 'OID' ), $status = 'refunded', $pm = 'ainepay' ) {
		static $id = 4000;
		$id++;
		return Ainepay_Test_Env::add_order(
			new WC_Order(
				array(
					'id'             => $id,
					'status'         => $status,
					'payment_method' => $pm,
					'meta'           => $meta,
				)
			)
		);
	}

	/**
	 * @param string $status Authoritative status.
	 * @return array
	 */
	private function payload( $status ) {
		return array( 'orders' => array( array( 'orderId' => 'OID', 'status' => $status, 'updated' => 'u1' ) ) );
	}

	/* --- on_wc_fully_refunded --------------------------------------------- */

	public function test_full_refund_flags_pending_and_schedules_verify() {
		$order = $this->order( array( '_ainepay_order_id' => 'OID', '_ainepay_status' => 'PAID' ) );
		Ainepay_Order_Sync::on_wc_fully_refunded( $order->get_id(), 99 );
		$this->assertSame( '1', $order->get_meta( '_ainepay_refund_pending' ) );
		$this->assertSame( 0, (int) $order->get_meta( '_ainepay_refund_merchant_attempts' ) );
		$this->assertSame( 0, (int) $order->get_meta( '_ainepay_refund_outage_attempts' ) );
		$this->assertNotEmpty( $order->notes );
		$this->assertCount( 1, Ainepay_Test_Env::$scheduled );
		$this->assertSame( Ainepay_Order_Sync::REFUND_VERIFY_HOOK, Ainepay_Test_Env::$scheduled[0]['hook'] );
		$this->assertSame( array( 'OID' ), Ainepay_Test_Env::$scheduled[0]['args'] );
	}

	public function test_full_refund_on_never_paid_order_notes_without_verify() {
		// Refunding an order AinePay never confirmed as PAID (here: INIT/no status)
		// must NOT start a verify chain — it could never converge to REFUND and would
		// falsely alert as refund-stuck. Record a note instead.
		$order = $this->order( array( '_ainepay_order_id' => 'OID' ), 'refunded' );
		Ainepay_Order_Sync::on_wc_fully_refunded( $order->get_id(), 99 );
		$this->assertSame( '', $order->get_meta( '_ainepay_refund_pending' ) );
		$this->assertNotEmpty( $order->notes );
		$this->assertSame( array(), Ainepay_Test_Env::$scheduled );
	}

	public function test_full_refund_ignores_non_ainepay_order() {
		$order = $this->order( array( '_ainepay_order_id' => 'OID' ), 'refunded', 'stripe' );
		Ainepay_Order_Sync::on_wc_fully_refunded( $order->get_id() );
		$this->assertSame( '', $order->get_meta( '_ainepay_refund_pending' ) );
		$this->assertSame( array(), Ainepay_Test_Env::$scheduled );
	}

	/* --- backend-first REFUND (settled-guard exception) ------------------- */

	public function test_backend_first_refund_notification_converges_paid_backed_order() {
		// Merchant refunded directly at AinePay, bypassing the WooCommerce-first flow:
		// WC is still processing + meta=PAID. A signature-verified REFUND notification
		// must NOT be short-circuited by the PAID-backed settled guard; the order is
		// re-queried and converges to refunded.
		$order = $this->order(
			array( '_ainepay_order_id' => 'OID', '_ainepay_status' => 'PAID' ),
			'processing'
		);
		$client = Ainepay_Test_Env::set_gateway( $this->payload( 'REFUND' ) );
		$result = Ainepay_Order_Sync::handle_notification( array( 'orderId' => 'OID', 'status' => 'REFUND' ) );
		$this->assertSame( Ainepay_Order_Sync::RESULT_OK, $result );
		$this->assertTrue( $order->has_status( 'refunded' ) );
		$this->assertSame( 'REFUND', $order->get_meta( '_ainepay_status' ) );
		$this->assertSame( 1, $client->get_orders_calls );
	}

	public function test_paid_backed_order_short_circuits_when_notice_is_not_refund() {
		// A PAID-backed order whose notification is not REFUND (or the poller's
		// bodyless refresh) must still short-circuit: no query, no status change.
		$order = $this->order(
			array( '_ainepay_order_id' => 'OID', '_ainepay_status' => 'PAID' ),
			'processing'
		);
		$client = Ainepay_Test_Env::set_gateway( $this->payload( 'PAID' ) );
		$result = Ainepay_Order_Sync::handle_notification( array( 'orderId' => 'OID', 'status' => 'PAID' ) );
		$this->assertSame( Ainepay_Order_Sync::RESULT_OK, $result );
		$this->assertTrue( $order->has_status( 'processing' ) );
		$this->assertSame( 0, $client->get_orders_calls );
	}

	public function test_duplicate_refund_notification_is_idempotent_after_convergence() {
		// Once converged (refunded + meta=REFUND), a repeat REFUND notification is a
		// plain settled-and-backed short-circuit (not paid-backed any more).
		$order = $this->order(
			array( '_ainepay_order_id' => 'OID', '_ainepay_status' => 'REFUND' ),
			'refunded'
		);
		$client = Ainepay_Test_Env::set_gateway( $this->payload( 'REFUND' ) );
		$result = Ainepay_Order_Sync::handle_notification( array( 'orderId' => 'OID', 'status' => 'REFUND' ) );
		$this->assertSame( Ainepay_Order_Sync::RESULT_OK, $result );
		$this->assertTrue( $order->has_status( 'refunded' ) );
		$this->assertSame( 0, $client->get_orders_calls );
	}

	public function test_full_refund_ignores_order_without_ainepay_id() {
		$order = $this->order( array() );
		Ainepay_Order_Sync::on_wc_fully_refunded( $order->get_id() );
		$this->assertSame( array(), Ainepay_Test_Env::$scheduled );
	}

	public function test_full_refund_skips_when_already_backend_refunded() {
		$order = $this->order( array( '_ainepay_order_id' => 'OID', '_ainepay_status' => 'REFUND' ) );
		Ainepay_Order_Sync::on_wc_fully_refunded( $order->get_id() );
		$this->assertSame( '', $order->get_meta( '_ainepay_refund_pending' ) );
		$this->assertSame( array(), Ainepay_Test_Env::$scheduled );
	}

	public function test_full_refund_respects_tracking_filter_off() {
		Ainepay_Test_Env::$filter_overrides['ainepay_track_manual_refund'] = false;
		$order = $this->order();
		Ainepay_Order_Sync::on_wc_fully_refunded( $order->get_id() );
		$this->assertSame( '', $order->get_meta( '_ainepay_refund_pending' ) );
		$this->assertSame( array(), Ainepay_Test_Env::$scheduled );
	}

	/* --- on_wc_partially_refunded (unsupported: warn only) ---------------- */

	public function test_partial_refund_notes_unsupported_without_scheduling_verify() {
		// AinePay is full-refund only. A partial WC refund must NOT start a verify
		// chain (it could never converge to REFUND), set the refund-pending flag,
		// only record a one-time warning note for the merchant.
		$order = $this->order( array( '_ainepay_order_id' => 'OID' ), 'processing' );
		Ainepay_Order_Sync::on_wc_partially_refunded( $order->get_id(), 99 );
		$this->assertSame( '', $order->get_meta( '_ainepay_refund_pending' ) );
		$this->assertSame( '1', $order->get_meta( '_ainepay_partial_refund_noted' ) );
		$this->assertCount( 1, $order->notes );
		$this->assertSame( array(), Ainepay_Test_Env::$scheduled );
	}

	public function test_partial_refund_notes_only_once() {
		$order = $this->order( array( '_ainepay_order_id' => 'OID' ), 'processing' );
		Ainepay_Order_Sync::on_wc_partially_refunded( $order->get_id(), 99 );
		Ainepay_Order_Sync::on_wc_partially_refunded( $order->get_id(), 100 );
		$this->assertCount( 1, $order->notes );
	}

	public function test_partial_refund_ignores_non_ainepay_order() {
		$order = $this->order( array( '_ainepay_order_id' => 'OID' ), 'processing', 'stripe' );
		Ainepay_Order_Sync::on_wc_partially_refunded( $order->get_id(), 99 );
		$this->assertSame( '', $order->get_meta( '_ainepay_partial_refund_noted' ) );
		$this->assertSame( array(), $order->notes );
	}

	public function test_partial_refund_ignores_order_without_ainepay_id() {
		$order = $this->order( array(), 'processing' );
		Ainepay_Order_Sync::on_wc_partially_refunded( $order->get_id(), 99 );
		$this->assertSame( '', $order->get_meta( '_ainepay_partial_refund_noted' ) );
		$this->assertSame( array(), $order->notes );
	}

	/* --- verify_refund ---------------------------------------------------- */

	public function test_verify_noop_when_order_missing() {
		Ainepay_Order_Sync::verify_refund( 'NOPE' );
		$this->assertSame( array(), Ainepay_Test_Env::$scheduled );
	}

	public function test_verify_noop_when_not_pending() {
		$this->order( array( '_ainepay_order_id' => 'OID' ) );
		$client = Ainepay_Test_Env::set_gateway( $this->payload( 'REFUND' ) );
		Ainepay_Order_Sync::verify_refund( 'OID' );
		$this->assertSame( 0, $client->get_orders_calls );
	}

	public function test_verify_clears_pending_when_already_backend_refunded() {
		$order = $this->order(
			array( '_ainepay_order_id' => 'OID', '_ainepay_status' => 'REFUND', '_ainepay_refund_pending' => '1' )
		);
		Ainepay_Order_Sync::verify_refund( 'OID' );
		$this->assertSame( '', $order->get_meta( '_ainepay_refund_pending' ) );
	}

	public function test_verify_reschedules_on_backend_outage() {
		$order = $this->order(
			array(
				'_ainepay_order_id'                => 'OID',
				'_ainepay_refund_pending'          => '1',
				'_ainepay_refund_merchant_attempts' => 5,
				'_ainepay_refund_attempts'         => 8, // Legacy mixed counter is ignored.
			)
		);
		// No gateway => query_status() null.
		Ainepay_Order_Sync::verify_refund( 'OID' );
		$this->assertSame( '1', $order->get_meta( '_ainepay_refund_pending' ) );
		$this->assertSame( 5, (int) $order->get_meta( '_ainepay_refund_merchant_attempts' ) );
		$this->assertSame( 1, (int) $order->get_meta( '_ainepay_refund_outage_attempts' ) );
		$this->assertCount( 1, Ainepay_Test_Env::$scheduled );
		$this->assertSame( Ainepay_Order_Sync::REFUND_VERIFY_HOOK, Ainepay_Test_Env::$scheduled[0]['hook'] );
	}

	public function test_verify_closes_loop_when_backend_confirms_refund() {
		$order = $this->order( array( '_ainepay_order_id' => 'OID', '_ainepay_refund_pending' => '1' ) );
		Ainepay_Test_Env::set_gateway( $this->payload( 'REFUND' ) );
		Ainepay_Order_Sync::verify_refund( 'OID' );
		$this->assertSame( '', $order->get_meta( '_ainepay_refund_pending' ) );
		$this->assertSame( 'REFUND', $order->get_meta( '_ainepay_status' ) );
		$this->assertTrue( $order->has_status( 'refunded' ) );
	}

	public function test_verify_keeps_waiting_when_backend_not_yet_refunded() {
		$order = $this->order(
			array(
				'_ainepay_order_id'              => 'OID',
				'_ainepay_refund_pending'        => '1',
				'_ainepay_refund_outage_attempts' => 3,
				'_ainepay_refund_outage_failed'   => '1',
			)
		);
		set_transient( 'ainepay_refund_unreachable_' . $order->get_id(), 'OID', WEEK_IN_SECONDS );
		Ainepay_Test_Env::set_gateway( $this->payload( 'PAID' ) );
		Ainepay_Order_Sync::verify_refund( 'OID' );
		$this->assertSame( '1', $order->get_meta( '_ainepay_refund_pending' ) );
		$this->assertSame( 1, (int) $order->get_meta( '_ainepay_refund_merchant_attempts' ) );
		$this->assertSame( 0, (int) $order->get_meta( '_ainepay_refund_outage_attempts' ) );
		$this->assertSame( '', $order->get_meta( '_ainepay_refund_outage_failed' ) );
		$this->assertFalse( get_transient( 'ainepay_refund_unreachable_' . $order->get_id() ) );
		$this->assertCount( 1, Ainepay_Test_Env::$scheduled );
	}

	/* --- stuck escalation ------------------------------------------------- */

	public function test_verify_escalates_to_stuck_alert_after_max_attempts() {
		$order = $this->order(
			array(
				'_ainepay_order_id'                => 'OID',
				'_ainepay_refund_pending'          => '1',
				'_ainepay_refund_merchant_attempts' => Ainepay_Order_Sync::REFUND_MAX_ATTEMPTS,
			)
		);
		Ainepay_Test_Env::set_gateway( $this->payload( 'PAID' ) ); // never reaches REFUND
		Ainepay_Order_Sync::verify_refund( 'OID' );

		$this->assertSame( '1', $order->get_meta( '_ainepay_refund_failed' ) );
		$this->assertSame( 'OID', get_transient( 'ainepay_refund_stuck_' . $order->get_id() ) );
		$hooks = array_column( Ainepay_Test_Env::$actions, 'hook' );
		$this->assertContains( 'ainepay_refund_stuck', $hooks );
		// No further verify scheduled once stuck.
		$this->assertSame( array(), Ainepay_Test_Env::$scheduled );
	}

	public function test_outage_budget_escalates_separately_without_consuming_merchant_grace() {
		$order = $this->order(
			array(
				'_ainepay_order_id'                 => 'OID',
				'_ainepay_refund_pending'           => '1',
				'_ainepay_refund_merchant_attempts' => 7,
				'_ainepay_refund_outage_attempts'   => Ainepay_Order_Sync::REFUND_OUTAGE_MAX_ATTEMPTS,
			)
		);
		Ainepay_Order_Sync::verify_refund( 'OID' ); // No gateway: outage.

		$this->assertSame( 7, (int) $order->get_meta( '_ainepay_refund_merchant_attempts' ) );
		$this->assertSame( '1', $order->get_meta( '_ainepay_refund_outage_failed' ) );
		$this->assertSame( '', $order->get_meta( '_ainepay_refund_failed' ) );
		$this->assertSame( 'OID', get_transient( 'ainepay_refund_unreachable_' . $order->get_id() ) );
		$hooks = array_column( Ainepay_Test_Env::$actions, 'hook' );
		$this->assertContains( 'ainepay_refund_unreachable', $hooks );
		$this->assertNotContains( 'ainepay_refund_stuck', $hooks );
		$this->assertSame( array(), Ainepay_Test_Env::$scheduled );
	}

	public function test_schedule_failure_uses_connectivity_alert_not_merchant_stuck() {
		$order = $this->order(
			array(
				'_ainepay_order_id'       => 'OID',
				'_ainepay_refund_pending' => '1',
			)
		);
		Ainepay_Test_Env::set_gateway( $this->payload( 'PAID' ) );
		Ainepay_Test_Env::$schedule_fails = true;

		Ainepay_Order_Sync::verify_refund( 'OID' );

		$this->assertSame( '1', $order->get_meta( '_ainepay_refund_outage_failed' ) );
		$this->assertSame( '', $order->get_meta( '_ainepay_refund_failed' ) );
		$hooks = array_column( Ainepay_Test_Env::$actions, 'hook' );
		$this->assertContains( 'ainepay_refund_unreachable', $hooks );
		$this->assertNotContains( 'ainepay_refund_stuck', $hooks );
	}

	/* --- apply_status REFUND clears the pending flag ---------------------- */

	public function test_apply_refund_status_clears_pending_flag() {
		$order = $this->order(
			array(
				'_ainepay_order_id'                 => 'OID',
				'_ainepay_refund_pending'           => '1',
				'_ainepay_refund_merchant_attempts' => 4,
				'_ainepay_refund_attempts'         => 6,
				'_ainepay_refund_outage_attempts'   => 2,
				'_ainepay_refund_outage_failed'     => '1',
			),
			'on-hold'
		);
		set_transient( 'ainepay_refund_stuck_' . $order->get_id(), 'OID', WEEK_IN_SECONDS );
		set_transient( 'ainepay_refund_unreachable_' . $order->get_id(), 'OID', WEEK_IN_SECONDS );
		Ainepay_Test_Env::set_gateway( $this->payload( 'REFUND' ) );
		Ainepay_Order_Sync::handle_notification( array( 'orderId' => 'OID' ) );
		$this->assertTrue( $order->has_status( 'refunded' ) );
		$this->assertSame( '', $order->get_meta( '_ainepay_refund_pending' ) );
		$this->assertSame( 0, (int) $order->get_meta( '_ainepay_refund_merchant_attempts' ) );
		$this->assertSame( 0, (int) $order->get_meta( '_ainepay_refund_attempts' ) );
		$this->assertSame( 0, (int) $order->get_meta( '_ainepay_refund_outage_attempts' ) );
		$this->assertSame( '', $order->get_meta( '_ainepay_refund_outage_failed' ) );
		$this->assertFalse( get_transient( 'ainepay_refund_stuck_' . $order->get_id() ) );
		$this->assertFalse( get_transient( 'ainepay_refund_unreachable_' . $order->get_id() ) );
	}
}
