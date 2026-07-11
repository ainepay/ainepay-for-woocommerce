<?php
/**
 * Tests for the local per-order payment-creation concurrency gate.
 *
 * @package AinePay\WooCommerce
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/wp-stubs.php';

if ( ! class_exists( 'WC_Payment_Gateway' ) ) {
	class WC_Payment_Gateway {}
}

require_once dirname( __DIR__ ) . '/includes/class-ainepay-gateway.php';

/**
 * @covers Ainepay_Gateway
 */
class PaymentCreationLockTest extends TestCase {

	protected function setUp(): void {
		Ainepay_Test_Env::reset();
	}

	private static function priv( $method, array $args = array() ) {
		$reflection = new ReflectionMethod( 'Ainepay_Gateway', $method );
		$reflection->setAccessible( true );
		return $reflection->invoke( null, ...$args );
	}

	public function test_creation_lock_fails_closed_on_contention() {
		Ainepay_Test_Env::$lock_result = '0';
		$this->assertFalse( self::priv( 'acquire_payment_creation_lock', array( 42 ) ) );
	}

	public function test_creation_lock_is_site_and_order_scoped() {
		Ainepay_Test_Env::$lock_result = '1';
		$first = self::priv( 'acquire_payment_creation_lock', array( 42 ) );
		$this->assertIsString( $first );

		$GLOBALS['wpdb']->prefix = 'other_';
		$other_site = self::priv( 'acquire_payment_creation_lock', array( 42 ) );
		$other_order = self::priv( 'acquire_payment_creation_lock', array( 43 ) );
		$this->assertNotSame( $first, $other_site );
		$this->assertNotSame( $other_site, $other_order );
	}
}
