<?php
/**
 * Tests for fail-closed AinePay API origin validation.
 *
 * @package AinePay\WooCommerce
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/wp-stubs.php';
require_once dirname( __DIR__ ) . '/includes/client/class-ainepay-api-client.php';

/**
 * @covers Ainepay_Api_Client
 */
class ApiBaseUrlValidationTest extends TestCase {

	protected function setUp(): void {
		Ainepay_Test_Env::reset();
	}

	public function test_official_https_origin_is_canonicalised() {
		$this->assertSame(
			'https://api.ainepay.com',
			Ainepay_Api_Client::validate_base_url( 'https://API.AINEPAY.COM/' )
		);
	}

	/**
	 * @dataProvider unsafe_url_provider
	 * @param string $url Unsafe candidate.
	 */
	public function test_unsafe_or_untrusted_urls_are_rejected( $url ) {
		$this->assertInstanceOf( WP_Error::class, Ainepay_Api_Client::validate_base_url( $url ) );
	}

	public function unsafe_url_provider() {
		return array(
			'plain HTTP'       => array( 'http://api.ainepay.com' ),
			'lookalike host'   => array( 'https://api.ainepay.com.evil.example' ),
			'userinfo'         => array( 'https://user:pass@api.ainepay.com' ),
			'query'            => array( 'https://api.ainepay.com?target=internal' ),
			'fragment'         => array( 'https://api.ainepay.com/#fragment' ),
			'path'             => array( 'https://api.ainepay.com/proxy' ),
			'non-TLS port'     => array( 'https://api.ainepay.com:8443' ),
			'loopback'         => array( 'https://127.0.0.1' ),
			'private address'  => array( 'https://10.0.0.1' ),
			'cloud metadata'   => array( 'https://169.254.169.254' ),
			'IPv6 loopback'    => array( 'https://[::1]' ),
			'empty'            => array( '' ),
		);
	}

	public function test_explicit_public_test_host_requires_allowlist_filter() {
		$url = 'https://sandbox-api.ainepay.example';
		$this->assertInstanceOf( WP_Error::class, Ainepay_Api_Client::validate_base_url( $url ) );

		Ainepay_Test_Env::$filter_overrides['ainepay_allowed_api_hosts'] = array(
			'api.ainepay.com',
			'sandbox-api.ainepay.example',
		);
		$this->assertSame( $url, Ainepay_Api_Client::validate_base_url( $url ) );
	}

	public function test_invalid_stored_url_fails_before_any_credentials_or_transport_are_built() {
		$client = new Ainepay_Api_Client( 'https://169.254.169.254', 'live_secret_key', 'sv_secret' );
		$result = $client->get_orders( array( 'order-1' ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'ainepay_api_url_untrusted', $result->get_error_code() );
		$this->assertSame( array(), Ainepay_Test_Env::$remote_requests );
	}
}
