<?php
/**
 * Tests for the customer-facing state derivation: an unbacked WC success
 * state (processing/completed without an authoritative PAID) must NOT freeze the
 * page as final, and must not be presented as paid or as awaiting-with-an-address.
 * It is a distinct "verifying" state that keeps polling.
 *
 * @package AinePay\WooCommerce
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/wp-stubs.php';
require_once dirname( __DIR__ ) . '/includes/class-ainepay-order-helper.php';
require_once dirname( __DIR__ ) . '/includes/class-ainepay-payment-display.php';

/**
 * @covers Ainepay_Payment_Display
 */
class PaymentDisplayStateTest extends TestCase {

	protected function setUp(): void {
		Ainepay_Test_Env::reset();
	}

	/**
	 * @param string              $status WC status.
	 * @param array<string,mixed> $meta   Order meta.
	 * @return WC_Order
	 */
	private function order( $status, array $meta ) {
		static $id = 6000;
		$id++;
		return Ainepay_Test_Env::add_order(
			new WC_Order(
				array(
					'id'             => $id,
					'status'         => $status,
					'payment_method' => 'ainepay',
					'meta'           => $meta,
				)
			)
		);
	}

	/**
	 * Render and return the data handed to the template.
	 *
	 * @param string              $status WC status.
	 * @param array<string,mixed> $meta   Order meta.
	 * @return array
	 */
	private function render_data( $status, array $meta ) {
		$order = $this->order( $status, $meta );
		( new Ainepay_Payment_Display() )->render( $order->get_id() );
		return Ainepay_Test_Env::$last_template;
	}

	/**
	 * @param string   $method Private static predicate.
	 * @param WC_Order $order  Order.
	 * @return bool
	 */
	private static function predicate( $method, $order ) {
		$m = new ReflectionMethod( 'Ainepay_Payment_Display', $method );
		$m->setAccessible( true );
		return $m->invoke( null, $order );
	}

	/* --- is_final (poller stop) ------------------------------------------- */

	public function test_unbacked_success_is_not_final_so_polling_continues() {
		$data = $this->render_data( 'processing', array( '_ainepay_order_id' => 'OID', '_ainepay_address' => '0xabc' ) );
		$this->assertFalse( $data['is_final'] );
	}

	public function test_backed_success_is_final() {
		$data = $this->render_data( 'processing', array( '_ainepay_order_id' => 'OID', '_ainepay_status' => 'PAID' ) );
		$this->assertTrue( $data['is_final'] );
	}

	public function test_awaiting_order_is_not_final() {
		$data = $this->render_data( 'on-hold', array( '_ainepay_order_id' => 'OID', '_ainepay_address' => '0xabc' ) );
		$this->assertFalse( $data['is_final'] );
	}

	/**
	 * @dataProvider terminal_statuses
	 *
	 * @param string $status Terminal WC status.
	 */
	public function test_terminal_statuses_are_final( $status ) {
		$data = $this->render_data( $status, array( '_ainepay_order_id' => 'OID' ) );
		$this->assertTrue( $data['is_final'], "$status should be final" );
	}

	/**
	 * @return array<string,array{0:string}>
	 */
	public function terminal_statuses() {
		return array(
			'failed'    => array( 'failed' ),
			'cancelled' => array( 'cancelled' ),
			'refunded'  => array( 'refunded' ),
		);
	}

	/* --- predicates ------------------------------------------------------- */

	public function test_is_unbacked_success_predicate() {
		$this->assertTrue( self::predicate( 'is_unbacked_success', $this->order( 'processing', array( '_ainepay_order_id' => 'OID' ) ) ) );
		$this->assertTrue( self::predicate( 'is_unbacked_success', $this->order( 'completed', array( '_ainepay_order_id' => 'OID' ) ) ) );
		$this->assertFalse( self::predicate( 'is_unbacked_success', $this->order( 'processing', array( '_ainepay_status' => 'PAID' ) ) ) );
		$this->assertFalse( self::predicate( 'is_unbacked_success', $this->order( 'on-hold', array( '_ainepay_order_id' => 'OID' ) ) ) );
	}

	public function test_is_paid_backed_predicate() {
		$this->assertTrue( self::predicate( 'is_paid_backed', $this->order( 'processing', array( '_ainepay_status' => 'PAID' ) ) ) );
		$this->assertFalse( self::predicate( 'is_paid_backed', $this->order( 'processing', array( '_ainepay_order_id' => 'OID' ) ) ) );
		$this->assertFalse( self::predicate( 'is_paid_backed', $this->order( 'on-hold', array( '_ainepay_status' => 'PAID' ) ) ) );
	}
}
