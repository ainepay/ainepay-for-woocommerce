<?php
/**
 * Tests that order-sync advisory locks are isolated across WordPress sites.
 *
 * @package AinePay\WooCommerce
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/wp-stubs.php';
require_once dirname( __DIR__ ) . '/includes/class-ainepay-order-sync.php';

/**
 * @covers Ainepay_Order_Sync
 */
class OrderSyncLockNamespaceTest extends TestCase {

	protected function setUp(): void {
		Ainepay_Test_Env::reset();
	}

	private static function lock_name( $order_id ) {
		$reflection = new ReflectionMethod( 'Ainepay_Order_Sync', 'lock_name' );
		$reflection->setAccessible( true );
		return $reflection->invoke( null, $order_id );
	}

	public function test_same_order_id_on_different_blog_ids_has_different_lock() {
		Ainepay_Test_Env::$blog_id = 1;
		$first = self::lock_name( 'shared-order-id' );

		Ainepay_Test_Env::$blog_id = 2;
		$second = self::lock_name( 'shared-order-id' );

		$this->assertNotSame( $first, $second );
	}

	public function test_same_order_id_with_different_table_prefix_has_different_lock() {
		$first = self::lock_name( 'shared-order-id' );
		$GLOBALS['wpdb']->prefix = 'tenant_';
		$second = self::lock_name( 'shared-order-id' );

		$this->assertNotSame( $first, $second );
	}

	public function test_same_site_and_order_produces_stable_bounded_lock_name() {
		$first  = self::lock_name( str_repeat( 'x', 512 ) );
		$second = self::lock_name( str_repeat( 'x', 512 ) );

		$this->assertSame( $first, $second );
		$this->assertLessThanOrEqual( 64, strlen( $first ) );
		$this->assertStringStartsWith( 'ainepay_', $first );
	}
}
