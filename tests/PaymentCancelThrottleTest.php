<?php
/**
 * Tests for the customer-cancel rate limiter on the unauthenticated (nopriv)
 * endpoint: a per-order window (so a held order key cannot replay-flood the
 * backend) and a per-IP burst cap (fast-fail before any DB/backend work). The
 * private helpers are exercised directly via reflection.
 *
 * @package AinePay\WooCommerce
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/wp-stubs.php';
require_once dirname( __DIR__ ) . '/includes/class-ainepay-order-helper.php';
require_once dirname( __DIR__ ) . '/includes/class-ainepay-order-sync.php';
require_once dirname( __DIR__ ) . '/includes/class-ainepay-payment-display.php';

/**
 * @covers Ainepay_Payment_Display
 */
class PaymentCancelThrottleTest extends TestCase {

	protected function setUp(): void {
		Ainepay_Test_Env::reset();
		$_POST = array();
	}

	protected function tearDown(): void {
		unset( $_SERVER['REMOTE_ADDR'] );
		$_POST = array();
	}

	/**
	 * @param string $method Private static method name.
	 * @param array  $args   Arguments.
	 * @return mixed
	 */
	private static function priv( $method, array $args = array() ) {
		$m = new ReflectionMethod( 'Ainepay_Payment_Display', $method );
		$m->setAccessible( true );
		return $m->invoke( null, ...$args );
	}

	private function order( $id, $payment_method = 'ainepay', $key = 'wc_order_KEY123', array $meta = array() ) {
		return Ainepay_Test_Env::add_order(
			new WC_Order(
				array(
					'id'             => $id,
					'status'         => 'on-hold',
					'payment_method' => $payment_method,
					'order_key'      => $key,
					'meta'           => $meta,
				)
			)
		);
	}

	private function cancel_response( $order_id, $nonce, $key ) {
		$_POST = array(
			'order_id' => $order_id,
			'nonce'    => $nonce,
			'key'      => $key,
		);
		try {
			( new Ainepay_Payment_Display() )->ajax_cancel();
			$this->fail( 'ajax_cancel() should terminate with a JSON response.' );
		} catch ( Ainepay_Test_Json_Response $response ) {
			return $response;
		}
	}

	/* --- endpoint classification / information disclosure ---------------- */

	public function test_missing_order_returns_not_found() {
		$response = $this->cancel_response(
			9999,
			'valid-' . Ainepay_Payment_Display::CANCEL_ACTION,
			'unknown'
		);

		$this->assertFalse( $response->success );
		$this->assertSame( 404, $response->status );
		$this->assertSame( 'Order not found.', $response->data['message'] );
	}

	public function test_authorized_non_ainepay_order_returns_gateway_conflict() {
		$this->order( 5001, 'stripe', 'wc_order_STRIPE' );
		$response = $this->cancel_response(
			5001,
			'valid-' . Ainepay_Payment_Display::CANCEL_ACTION,
			'wc_order_STRIPE'
		);

		$this->assertFalse( $response->success );
		$this->assertSame( 409, $response->status );
		$this->assertSame( 'This order was not paid with AinePay and cannot be cancelled here.', $response->data['message'] );
	}

	public function test_unauthorized_non_ainepay_order_does_not_disclose_gateway() {
		$this->order( 5002, 'stripe', 'wc_order_SECRET' );
		$response = $this->cancel_response(
			5002,
			'valid-' . Ainepay_Payment_Display::CANCEL_ACTION,
			'wrong-key'
		);

		$this->assertFalse( $response->success );
		$this->assertSame( 403, $response->status );
		$this->assertSame( 'Unauthorized.', $response->data['message'] );
	}

	public function test_pending_backed_order_is_rejected_without_backend_call() {
		// The finding: poll/webhook recorded _ainepay_status=PENDING while WC is still
		// on-hold. The endpoint must reject (409) before any synchronous backend cancel.
		$client = Ainepay_Test_Env::set_gateway( null, array( 'orderId' => 'OID', 'status' => 'CANCEL' ) );
		$this->order( 5101, 'ainepay', 'wc_order_PEND', array( '_ainepay_order_id' => 'OID', '_ainepay_status' => 'PENDING' ) );

		$response = $this->cancel_response(
			5101,
			'valid-' . Ainepay_Payment_Display::CANCEL_ACTION,
			'wc_order_PEND'
		);

		$this->assertFalse( $response->success );
		$this->assertSame( 409, $response->status );
		$this->assertSame( 0, $client->cancel_calls, 'no doomed backend cancel for a PENDING-backed order' );
	}

	public function test_init_backed_order_passes_the_cancellable_gate() {
		// Defence in depth must not over-block: an INIT order still reaches the backend
		// (which re-checks under its lock). We assert the cancel actually fired.
		$client = Ainepay_Test_Env::set_gateway( null, array( 'orderId' => 'OID', 'status' => 'CANCEL' ) );
		$this->order( 5102, 'ainepay', 'wc_order_INIT', array( '_ainepay_order_id' => 'OID', '_ainepay_status' => 'INIT' ) );

		$response = $this->cancel_response(
			5102,
			'valid-' . Ainepay_Payment_Display::CANCEL_ACTION,
			'wc_order_INIT'
		);

		$this->assertTrue( $response->success );
		$this->assertSame( 1, $client->cancel_calls );
	}

	/* --- per-order window ------------------------------------------------- */

	public function test_first_cancel_for_an_order_is_allowed_then_blocked() {
		$this->assertFalse( self::priv( 'cancel_order_throttled', array( 555 ) ) );
		$this->assertTrue( self::priv( 'cancel_order_throttled', array( 555 ) ) );
	}

	public function test_per_order_throttle_is_independent_across_orders() {
		$this->assertFalse( self::priv( 'cancel_order_throttled', array( 1 ) ) );
		$this->assertFalse( self::priv( 'cancel_order_throttled', array( 2 ) ) );
		$this->assertTrue( self::priv( 'cancel_order_throttled', array( 1 ) ) );
	}

	/* --- per-IP burst cap ------------------------------------------------- */

	public function test_ip_burst_allows_up_to_cap_then_blocks() {
		$cap = Ainepay_Payment_Display::CANCEL_IP_BURST;
		for ( $i = 0; $i < $cap; $i++ ) {
			$this->assertFalse( self::priv( 'cancel_ip_throttled' ), "request $i should pass" );
		}
		$this->assertTrue( self::priv( 'cancel_ip_throttled' ), 'request over the cap must be blocked' );
	}

	public function test_ip_burst_is_independent_per_ip() {
		$cap = Ainepay_Payment_Display::CANCEL_IP_BURST;
		for ( $i = 0; $i <= $cap; $i++ ) {
			self::priv( 'cancel_ip_throttled' );
		}
		$this->assertTrue( self::priv( 'cancel_ip_throttled' ), 'first IP is now blocked' );

		Ainepay_Test_Env::$client_ip = '198.51.100.42';
		$this->assertFalse( self::priv( 'cancel_ip_throttled' ), 'a fresh IP starts with a clean budget' );
	}

	/* --- client ip resolution --------------------------------------------- */

	public function test_client_ip_prefers_woocommerce_geolocation() {
		Ainepay_Test_Env::$client_ip = '203.0.113.99';
		$this->assertSame( '203.0.113.99', self::priv( 'client_ip' ) );
	}

	public function test_client_ip_falls_back_to_remote_addr() {
		Ainepay_Test_Env::$client_ip  = '';
		$_SERVER['REMOTE_ADDR']       = '198.51.100.9';
		$this->assertSame( '198.51.100.9', self::priv( 'client_ip' ) );
	}
}
