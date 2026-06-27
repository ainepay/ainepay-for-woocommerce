<?php
/**
 * Unit tests for Ainepay_Order_Helper::derive_user_id().
 *
 * Guards the security-critical identity rule: balance reuse is keyed on the
 * authenticated WP customer id (not the unverified billing email), and guests
 * get a per-order, non-reusable userId.
 *
 * derive_user_id() uses only PHP's hash() builtin, so the helper can be loaded
 * and tested in isolation (no full WordPress install needed).
 *
 * @package AinePay\WooCommerce
 */

use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__ ) . '/includes/class-ainepay-order-helper.php';

/**
 * @covers Ainepay_Order_Helper
 */
class OrderHelperTest extends TestCase {

	const NAMESPACE_HASH = 'a1b2c3d4';

	/**
	 * A logged-in customer's userId is sha256(site_namespace|customer_id) and
	 * does not depend on the order id.
	 */
	public function test_logged_in_user_id_is_account_derived() {
		$expected = hash( 'sha256', self::NAMESPACE_HASH . '|42' );
		$this->assertSame(
			$expected,
			Ainepay_Order_Helper::derive_user_id( self::NAMESPACE_HASH, 42, 1001 )
		);
	}

	/**
	 * The same logged-in customer reproduces the same userId across different
	 * orders — this is what makes balance reuse work.
	 */
	public function test_logged_in_user_id_stable_across_orders() {
		$a = Ainepay_Order_Helper::derive_user_id( self::NAMESPACE_HASH, 42, 1001 );
		$b = Ainepay_Order_Helper::derive_user_id( self::NAMESPACE_HASH, 42, 2002 );
		$this->assertSame( $a, $b );
	}

	/**
	 * Guests (customer id 0) get a per-order userId, so two different orders
	 * never share an identity and a guest balance is never reused.
	 */
	public function test_guest_user_id_is_per_order() {
		$a = Ainepay_Order_Helper::derive_user_id( self::NAMESPACE_HASH, 0, 1001 );
		$b = Ainepay_Order_Helper::derive_user_id( self::NAMESPACE_HASH, 0, 2002 );
		$this->assertNotSame( $a, $b );
		$this->assertSame( hash( 'sha256', self::NAMESPACE_HASH . '|guest_order_1001' ) . '|', $a );
	}

	/**
	 * Guest userIds carry a trailing "|" marker (appended after hashing) so
	 * the AinePay backend can detect a guest at refund time. The hash itself is opaque
	 * hex with no "|", so the marker is an unambiguous guest test. Logged-in
	 * userIds are the bare hash and never carry the marker.
	 */
	public function test_guest_user_id_carries_guest_marker() {
		$guest     = Ainepay_Order_Helper::derive_user_id( self::NAMESPACE_HASH, 0, 1001 );
		$logged_in = Ainepay_Order_Helper::derive_user_id( self::NAMESPACE_HASH, 42, 1001 );
		$this->assertStringEndsWith( '|', $guest );
		$this->assertStringNotContainsString( '|', $logged_in );
	}

	/**
	 * Security regression: a logged-in customer id must never collide with a
	 * guest whose order id has the same numeric value. The "guest_order_"
	 * prefix namespaces the two.
	 */
	public function test_customer_id_does_not_collide_with_guest_order_id() {
		$logged_in = Ainepay_Order_Helper::derive_user_id( self::NAMESPACE_HASH, 7, 999 );
		$guest      = Ainepay_Order_Helper::derive_user_id( self::NAMESPACE_HASH, 0, 7 );
		$this->assertNotSame( $logged_in, $guest );
	}

	/**
	 * Security regression: the same WP customer id under two different sites
	 * (stores sharing one merchant/API key) must produce different userIds, so
	 * one store's customer #42 can never claim another store's customer #42
	 * reusable balance. This is why the namespace is the per-site
	 * site_namespace() token and not the merchantId.
	 */
	public function test_user_id_is_isolated_across_sites() {
		$store_a = Ainepay_Order_Helper::derive_user_id( 'a1b2c3d4', 42, 1001 );
		$store_b = Ainepay_Order_Helper::derive_user_id( 'e5f6a7b8', 42, 1001 );
		$this->assertNotSame( $store_a, $store_b );
	}

	/**
	 * Empty / non-positive customer ids are treated as guests.
	 *
	 * @dataProvider guest_like_customer_ids
	 *
	 * @param mixed $customer_id Customer id treated as a guest.
	 */
	public function test_non_positive_customer_id_is_guest( $customer_id ) {
		$this->assertSame(
			hash( 'sha256', self::NAMESPACE_HASH . '|guest_order_500' ) . '|',
			Ainepay_Order_Helper::derive_user_id( self::NAMESPACE_HASH, $customer_id, 500 )
		);
	}

	/**
	 * @return array<string,array{0:mixed}>
	 */
	public function guest_like_customer_ids() {
		return array(
			'zero int'    => array( 0 ),
			'zero string' => array( '0' ),
			'empty string' => array( '' ),
			'null'        => array( null ),
		);
	}

	/**
	 * Every derived userId satisfies the backend length constraint (5..256).
	 */
	public function test_derived_ids_satisfy_length_constraint() {
		$this->assertTrue(
			Ainepay_Order_Helper::is_valid_identifier(
				Ainepay_Order_Helper::derive_user_id( self::NAMESPACE_HASH, 42, 1001 )
			)
		);
		$this->assertTrue(
			Ainepay_Order_Helper::is_valid_identifier(
				Ainepay_Order_Helper::derive_user_id( self::NAMESPACE_HASH, 0, 1001 )
			)
		);
	}

	/**
	 * Reuse eligibility follows the recorded _ainepay_account_user flag, not the
	 * live customer id — so an order placed as a guest stays "no reuse" even if
	 * it is later linked to a customer account.
	 */
	public function test_can_reuse_reads_recorded_flag_over_live_customer_id() {
		// Placed as guest ('0'), later linked to a customer account (id 9).
		$guest_then_linked = new Ainepay_Fake_Order( 9, array( '_ainepay_account_user' => '0' ) );
		$this->assertFalse( Ainepay_Order_Helper::can_reuse_balance( $guest_then_linked ) );

		// Placed while signed in.
		$account = new Ainepay_Fake_Order( 9, array( '_ainepay_account_user' => '1' ) );
		$this->assertTrue( Ainepay_Order_Helper::can_reuse_balance( $account ) );
	}

	/**
	 * Legacy orders without the flag fall back to the live customer id.
	 */
	public function test_can_reuse_falls_back_to_customer_id_when_flag_absent() {
		$legacy_guest   = new Ainepay_Fake_Order( 0, array() );
		$legacy_account = new Ainepay_Fake_Order( 5, array() );
		$this->assertFalse( Ainepay_Order_Helper::can_reuse_balance( $legacy_guest ) );
		$this->assertTrue( Ainepay_Order_Helper::can_reuse_balance( $legacy_account ) );
	}
}

/**
 * Minimal WC_Order stand-in exposing just the methods can_reuse_balance() uses.
 */
class Ainepay_Fake_Order {

	/** @var int */
	private $customer_id;

	/** @var array<string,string> */
	private $meta;

	/**
	 * @param int                  $customer_id Customer id.
	 * @param array<string,string> $meta        Meta key => value.
	 */
	public function __construct( $customer_id, array $meta ) {
		$this->customer_id = (int) $customer_id;
		$this->meta        = $meta;
	}

	/**
	 * @return int
	 */
	public function get_customer_id() {
		return $this->customer_id;
	}

	/**
	 * @param string $key Meta key.
	 * @return string
	 */
	public function get_meta( $key ) {
		return isset( $this->meta[ $key ] ) ? $this->meta[ $key ] : '';
	}
}
