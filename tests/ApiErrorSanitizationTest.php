<?php
/**
 * Tests that backend-controlled API errors remain diagnostic-only.
 *
 * @package AinePay\WooCommerce
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/wp-stubs.php';
require_once dirname( __DIR__ ) . '/includes/client/class-ainepay-signer.php';
require_once dirname( __DIR__ ) . '/includes/client/class-ainepay-api-client.php';

/**
 * @covers Ainepay_Api_Client
 */
class ApiErrorSanitizationTest extends TestCase {

	protected function setUp(): void {
		Ainepay_Test_Env::reset();
	}

	public function test_remote_html_and_internal_details_never_enter_public_wp_error_message() {
		$remote = '<a href="https://evil.example">Click</a> SQLSTATE secret-token';
		Ainepay_Test_Env::$remote_response = array(
			'response' => array( 'code' => 400 ),
			'body'     => json_encode(
				array(
					'success' => false,
					'code'    => 26,
					'msg'     => $remote,
				)
			),
		);

		$client = new Ainepay_Api_Client( 'https://api.ainepay.com', 'live_key', 'sv_secret' );
		$result = $client->get_orders( array( 'order-1' ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'AinePay could not process the request.', $result->get_error_message() );
		$this->assertStringNotContainsString( 'evil.example', $result->get_error_message() );
		$this->assertStringNotContainsString( 'SQLSTATE', $result->get_error_message() );
		$this->assertSame( 26, $result->get_error_data()['code'] );
	}
}
