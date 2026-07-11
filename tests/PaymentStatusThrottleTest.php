<?php
/**
 * Tests for load shedding on the unauthenticated order-status endpoint.
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
class PaymentStatusThrottleTest extends TestCase {

	protected function setUp(): void {
		Ainepay_Test_Env::reset();
		$_POST = array();
	}

	protected function tearDown(): void {
		$_POST = array();
	}

	/**
	 * @param string $method Private static method name.
	 * @param array  $args   Arguments.
	 * @return mixed
	 */
	private static function priv( $method, array $args = array() ) {
		$reflection = new ReflectionMethod( 'Ainepay_Payment_Display', $method );
		$reflection->setAccessible( true );
		return $reflection->invoke( null, ...$args );
	}

	/**
	 * Invoke the terminating AJAX handler and expose its JSON response.
	 *
	 * @param int    $order_id Order id.
	 * @param string $key      WooCommerce order key.
	 * @return Ainepay_Test_Json_Response
	 */
	private function status_response( $order_id, $key ) {
		$_POST = array(
			'order_id' => $order_id,
			'nonce'    => 'valid-' . Ainepay_Payment_Display::AJAX_ACTION,
			'key'      => $key,
		);
		try {
			( new Ainepay_Payment_Display() )->ajax_status();
			$this->fail( 'ajax_status() should terminate with a JSON response.' );
		} catch ( Ainepay_Test_Json_Response $response ) {
			return $response;
		}
	}

	public function test_first_status_refresh_for_order_is_allowed_then_throttled() {
		$this->assertFalse( self::priv( 'status_order_throttled', array( 701 ) ) );
		$this->assertTrue( self::priv( 'status_order_throttled', array( 701 ) ) );
	}

	public function test_status_refresh_cooldown_is_independent_per_order() {
		$this->assertFalse( self::priv( 'status_order_throttled', array( 701 ) ) );
		$this->assertFalse( self::priv( 'status_order_throttled', array( 702 ) ) );
		$this->assertTrue( self::priv( 'status_order_throttled', array( 701 ) ) );
	}

	public function test_status_refresh_single_flight_lock_is_non_blocking() {
		Ainepay_Test_Env::$lock_result = '0';
		$this->assertFalse( self::priv( 'acquire_status_refresh_lock', array( 704 ) ) );

		Ainepay_Test_Env::$lock_result = '1';
		$this->assertIsString( self::priv( 'acquire_status_refresh_lock', array( 704 ) ) );
	}

	public function test_repeated_authorized_ajax_poll_reuses_local_state_without_second_backend_call() {
		$order_id = 703;
		$order_key = 'wc_order_STATUS703';
		Ainepay_Test_Env::add_order(
			new WC_Order(
				array(
					'id'             => $order_id,
					'status'         => 'on-hold',
					'payment_method' => 'ainepay',
					'order_key'      => $order_key,
					'meta'           => array( '_ainepay_order_id' => 'OID-703', '_ainepay_status' => 'INIT' ),
				)
			)
		);
		$client = Ainepay_Test_Env::set_gateway(
			array( 'orders' => array( array( 'orderId' => 'OID-703', 'status' => 'INIT', 'updated' => '1' ) ) )
		);

		$first  = $this->status_response( $order_id, $order_key );
		$second = $this->status_response( $order_id, $order_key );

		$this->assertTrue( $first->success );
		$this->assertTrue( $second->success );
		$this->assertSame( 'pending', $second->data['state'] );
		$this->assertSame( 1, $client->get_orders_calls, 'cooldown must suppress the second synchronous backend query' );
	}

	public function test_status_ip_burst_allows_cap_then_blocks() {
		$cap = Ainepay_Payment_Display::STATUS_IP_BURST;
		for ( $i = 0; $i < $cap; $i++ ) {
			$this->assertFalse( self::priv( 'status_ip_throttled' ), "request $i should pass" );
		}
		$this->assertTrue( self::priv( 'status_ip_throttled' ) );
	}

	public function test_status_ip_burst_is_independent_per_ip() {
		$cap = Ainepay_Payment_Display::STATUS_IP_BURST;
		for ( $i = 0; $i <= $cap; $i++ ) {
			self::priv( 'status_ip_throttled' );
		}
		$this->assertTrue( self::priv( 'status_ip_throttled' ) );

		Ainepay_Test_Env::$client_ip = '198.51.100.77';
		$this->assertFalse( self::priv( 'status_ip_throttled' ) );
	}
}
