<?php
/**
 * Tests for binding create-order responses to the checkout request.
 *
 * @package AinePay\WooCommerce
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/wp-stubs.php';
require_once dirname( __DIR__ ) . '/includes/client/class-ainepay-api-client.php';

/**
 * @covers Ainepay_Api_Client
 */
class CreateResponseBindingTest extends TestCase {

	private function expected() {
		return array(
			'orderId'       => 'wc_site_42',
			'userId'        => 'user_42',
			'coin'          => 'USDT',
			'chain'         => 'ETH',
			'qty'           => '12.34',
			'collectAddress' => '0x1234567890abcdef1234567890abcdef12345678',
		);
	}

	public function test_matching_required_and_echoed_fields_are_accepted() {
		$response = array_merge( $this->expected(), array( 'status' => 'INIT' ) );
		$this->assertTrue( Ainepay_Api_Client::validate_create_response( $response, $this->expected() ) );
	}

	public function test_optional_chain_and_collection_fields_may_be_absent() {
		$this->assertTrue(
			Ainepay_Api_Client::validate_create_response(
				array(
					'orderId' => 'wc_site_42',
					'status'  => 'PAID',
					'userId'  => 'user_42',
					'coin'    => 'USDT',
					'qty'     => '12.34',
				),
				$this->expected()
			)
		);
	}

	public function test_paid_response_missing_funds_binding_is_rejected() {
		$this->assertInstanceOf(
			WP_Error::class,
			Ainepay_Api_Client::validate_create_response(
				array( 'orderId' => 'wc_site_42', 'status' => 'PAID' ),
				$this->expected()
			)
		);
	}

	public function test_missing_or_cross_order_response_is_rejected() {
		$this->assertInstanceOf( WP_Error::class, Ainepay_Api_Client::validate_create_response( array(), $this->expected() ) );
		$this->assertInstanceOf(
			WP_Error::class,
			Ainepay_Api_Client::validate_create_response( array( 'orderId' => 'wc_other_99' ), $this->expected() )
		);
	}

	public function test_missing_or_terminal_initial_status_is_rejected() {
		$this->assertInstanceOf(
			WP_Error::class,
			Ainepay_Api_Client::validate_create_response( array( 'orderId' => 'wc_site_42' ), $this->expected() )
		);
		$this->assertInstanceOf(
			WP_Error::class,
			Ainepay_Api_Client::validate_create_response(
				array( 'orderId' => 'wc_site_42', 'status' => 'EXPIRED' ),
				$this->expected()
			)
		);
	}

	/**
	 * @dataProvider mismatched_echo_provider
	 * @param string $field Mismatched response field.
	 * @param string $value Mismatched value.
	 */
	public function test_mismatched_echoed_payment_fields_are_rejected( $field, $value ) {
		$response           = array_merge( $this->expected(), array( 'status' => 'INIT' ) );
		$response[ $field ] = $value;
		$this->assertInstanceOf( WP_Error::class, Ainepay_Api_Client::validate_create_response( $response, $this->expected() ) );
	}

	public function mismatched_echo_provider() {
		return array(
			'user'       => array( 'userId', 'user_other' ),
			'coin'       => array( 'coin', 'USDC' ),
			'chain'      => array( 'chain', 'BSC' ),
			'amount'     => array( 'qty', '99.99' ),
			'collection' => array( 'collectAddress', '0xaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa' ),
		);
	}
}
