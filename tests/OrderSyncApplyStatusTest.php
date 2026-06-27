<?php
/**
 * Tests for the authoritative status-application path shared by the webhook and
 * the polling fallback: handle_notification() -> apply_status(). Covers the
 * PAID/EXPIRED/CANCEL/REFUND/PENDING mapping, the settled-state short-circuit
 * (which must NOT re-run fulfilment) and the lock/query failure outcomes.
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
class OrderSyncApplyStatusTest extends TestCase {

	protected function setUp(): void {
		Ainepay_Test_Env::reset();
	}

	/**
	 * @param string              $status WC status.
	 * @param array<string,mixed> $meta   Order meta.
	 * @param array               $items  Line items.
	 * @return WC_Order
	 */
	private function order( $status = 'on-hold', array $meta = array( '_ainepay_order_id' => 'OID' ), array $items = array() ) {
		static $id = 3000;
		$id++;
		return Ainepay_Test_Env::add_order(
			new WC_Order(
				array(
					'id'     => $id,
					'status' => $status,
					'meta'   => $meta,
					'items'  => $items,
				)
			)
		);
	}

	/**
	 * @param string $status Authoritative status.
	 * @return array
	 */
	private function payload( $status ) {
		return array( 'orders' => array( array( 'orderId' => 'OID', 'status' => $status, 'updated' => 'u1', 'id' => 'tx1' ) ) );
	}

	private function physical_item() {
		return new Ainepay_Fake_Item( new Ainepay_Fake_Product( false ) );
	}

	private function virtual_item() {
		return new Ainepay_Fake_Item( new Ainepay_Fake_Product( true ) );
	}

	/* --- guard / failure outcomes ----------------------------------------- */

	public function test_empty_order_id_is_not_found() {
		$this->assertSame( Ainepay_Order_Sync::RESULT_NOT_FOUND, Ainepay_Order_Sync::handle_notification( array() ) );
	}

	public function test_unknown_order_is_not_found() {
		$this->assertSame(
			Ainepay_Order_Sync::RESULT_NOT_FOUND,
			Ainepay_Order_Sync::handle_notification( array( 'orderId' => 'NOPE' ) )
		);
	}

	public function test_lock_contention_returns_busy() {
		$this->order();
		Ainepay_Test_Env::$lock_result = '0';
		$this->assertSame(
			Ainepay_Order_Sync::RESULT_BUSY,
			Ainepay_Order_Sync::handle_notification( array( 'orderId' => 'OID' ) )
		);
	}

	public function test_query_failure_returns_retry() {
		$this->order();
		Ainepay_Test_Env::set_gateway( new WP_Error( 'ainepay_bad_response', 'down' ) );
		$this->assertSame(
			Ainepay_Order_Sync::RESULT_RETRY,
			Ainepay_Order_Sync::handle_notification( array( 'orderId' => 'OID' ) )
		);
	}

	public function test_settled_and_backed_order_short_circuits_without_query() {
		$this->order( 'processing', array( '_ainepay_order_id' => 'OID', '_ainepay_status' => 'PAID' ) );
		$client = Ainepay_Test_Env::set_gateway( $this->payload( 'PAID' ) );
		$this->assertSame(
			Ainepay_Order_Sync::RESULT_OK,
			Ainepay_Order_Sync::handle_notification( array( 'orderId' => 'OID' ) )
		);
		$this->assertSame( 0, $client->get_orders_calls );
	}

	/* --- status mapping --------------------------------------------------- */

	public function test_paid_physical_order_moves_to_processing() {
		$order = $this->order( 'on-hold', array( '_ainepay_order_id' => 'OID' ), array( $this->physical_item() ) );
		Ainepay_Test_Env::set_gateway( $this->payload( 'PAID' ) );
		$this->assertSame(
			Ainepay_Order_Sync::RESULT_OK,
			Ainepay_Order_Sync::handle_notification( array( 'orderId' => 'OID' ) )
		);
		$this->assertTrue( $order->has_status( 'processing' ) );
		$this->assertSame( 'PAID', $order->get_meta( '_ainepay_status' ) );
		$this->assertSame( 1, $order->payment_complete_calls );
	}

	public function test_paid_virtual_order_moves_to_completed() {
		$order = $this->order( 'on-hold', array( '_ainepay_order_id' => 'OID' ), array( $this->virtual_item() ) );
		Ainepay_Test_Env::set_gateway( $this->payload( 'PAID' ) );
		Ainepay_Order_Sync::handle_notification( array( 'orderId' => 'OID' ) );
		$this->assertTrue( $order->has_status( 'completed' ) );
		$this->assertSame( 'PAID', $order->get_meta( '_ainepay_status' ) );
	}

	public function test_expired_order_moves_to_failed() {
		$order = $this->order();
		Ainepay_Test_Env::set_gateway( $this->payload( 'EXPIRED' ) );
		Ainepay_Order_Sync::handle_notification( array( 'orderId' => 'OID' ) );
		$this->assertTrue( $order->has_status( 'failed' ) );
		$this->assertSame( 'EXPIRED', $order->get_meta( '_ainepay_status' ) );
	}

	public function test_cancel_status_moves_to_cancelled() {
		$order = $this->order();
		Ainepay_Test_Env::set_gateway( $this->payload( 'CANCEL' ) );
		Ainepay_Order_Sync::handle_notification( array( 'orderId' => 'OID' ) );
		$this->assertTrue( $order->has_status( 'cancelled' ) );
		$this->assertSame( 'CANCEL', $order->get_meta( '_ainepay_status' ) );
	}

	public function test_refund_status_moves_to_refunded() {
		$order = $this->order();
		Ainepay_Test_Env::set_gateway( $this->payload( 'REFUND' ) );
		Ainepay_Order_Sync::handle_notification( array( 'orderId' => 'OID' ) );
		$this->assertTrue( $order->has_status( 'refunded' ) );
		$this->assertSame( 'REFUND', $order->get_meta( '_ainepay_status' ) );
	}

	public function test_woo_first_refunded_pending_backing_is_not_short_circuited_and_closes_to_refund() {
		// Woo-first refund: the merchant refunded in WooCommerce (WC=refunded) but the
		// AinePay leg has not landed yet, so the backing meta is still PAID. This is the
		// crux of the "PAID short-circuit swallows REFUND" concern: because refunded
		// requires meta=REFUND, is_settled_and_backed() is false, so the authoritative
		// REFUND notification must still be queried and applied (meta -> REFUND) rather
		// than acked away. The status stays refunded; only the backing converges.
		$order  = $this->order( 'refunded', array( '_ainepay_order_id' => 'OID', '_ainepay_status' => 'PAID' ) );
		$client = Ainepay_Test_Env::set_gateway( $this->payload( 'REFUND' ) );

		$this->assertSame(
			Ainepay_Order_Sync::RESULT_OK,
			Ainepay_Order_Sync::handle_notification( array( 'orderId' => 'OID' ) )
		);

		// Not short-circuited: the backend was actually queried.
		$this->assertSame( 1, $client->get_orders_calls );
		$this->assertTrue( $order->has_status( 'refunded' ) );
		$this->assertSame( 'REFUND', $order->get_meta( '_ainepay_status' ) );
	}

	public function test_pending_restores_a_mislabelled_cancelled_order_to_on_hold() {
		// A settle race left it cancelled with empty backing; PENDING must re-reserve.
		$order = $this->order( 'cancelled', array( '_ainepay_order_id' => 'OID', '_ainepay_status' => '' ) );
		Ainepay_Test_Env::set_gateway( $this->payload( 'PENDING' ) );
		Ainepay_Order_Sync::handle_notification( array( 'orderId' => 'OID' ) );
		$this->assertTrue( $order->has_status( 'on-hold' ) );
	}

	public function test_paid_repair_from_cancelled_preserves_existing_stock_reduction() {
		$order = $this->order(
			'cancelled',
			array(
				'_ainepay_order_id'    => 'OID',
				'_ainepay_status'      => '',
				'_order_stock_reduced' => 'yes',
			),
			array( $this->physical_item() )
		);
		Ainepay_Test_Env::set_gateway( $this->payload( 'PAID' ) );

		Ainepay_Order_Sync::handle_notification( array( 'orderId' => 'OID' ) );

		$this->assertTrue( $order->has_status( 'processing' ) );
		$this->assertSame( 'PAID', $order->get_meta( '_ainepay_status' ) );
		$this->assertSame( 'yes', $order->get_meta( '_order_stock_reduced' ) );
		$this->assertSame( 1, $order->payment_complete_calls );
		$this->assertSame( 0, $order->stock_increase_calls );
	}

	/* --- idempotency ------------------------------------------------------ */

	public function test_reprocessing_paid_does_not_re_run_payment_complete() {
		$order = $this->order( 'on-hold', array( '_ainepay_order_id' => 'OID' ), array( $this->physical_item() ) );
		Ainepay_Test_Env::set_gateway( $this->payload( 'PAID' ) );
		Ainepay_Order_Sync::handle_notification( array( 'orderId' => 'OID' ) );
		Ainepay_Order_Sync::handle_notification( array( 'orderId' => 'OID' ) );
		$this->assertSame( 1, $order->payment_complete_calls );
		$this->assertTrue( $order->has_status( 'processing' ) );
	}
}
