<?php
/**
 * Tests for webhook request-size boundaries applied before signature parsing.
 *
 * @package AinePay\WooCommerce
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/wp-stubs.php';
require_once dirname( __DIR__ ) . '/includes/class-ainepay-webhook-handler.php';

/**
 * @covers Ainepay_Webhook_Handler
 */
class WebhookRequestBoundaryTest extends TestCase {

	protected function setUp(): void {
		$_SERVER = array();
	}

	protected function tearDown(): void {
		$_SERVER = array();
	}

	/**
	 * @param string $method Private static method name.
	 * @param array  $args   Arguments.
	 * @return mixed
	 */
	private static function priv( $method, array $args = array() ) {
		$reflection = new ReflectionMethod( 'Ainepay_Webhook_Handler', $method );
		$reflection->setAccessible( true );
		return $reflection->invoke( null, ...$args );
	}

	public function test_absent_content_length_is_allowed_for_chunked_requests() {
		$this->assertNull( self::priv( 'declared_content_length' ) );
	}

	public function test_content_length_at_limit_is_allowed() {
		$_SERVER['CONTENT_LENGTH'] = (string) Ainepay_Webhook_Handler::MAX_BODY_BYTES;
		$this->assertSame( Ainepay_Webhook_Handler::MAX_BODY_BYTES, self::priv( 'declared_content_length' ) );
	}

	public function test_oversized_and_integer_overflow_lengths_are_rejected_early() {
		$_SERVER['CONTENT_LENGTH'] = (string) ( Ainepay_Webhook_Handler::MAX_BODY_BYTES + 1 );
		$this->assertGreaterThan( Ainepay_Webhook_Handler::MAX_BODY_BYTES, self::priv( 'declared_content_length' ) );

		$_SERVER['CONTENT_LENGTH'] = '999999999999999999999999999999999999';
		$this->assertGreaterThan( Ainepay_Webhook_Handler::MAX_BODY_BYTES, self::priv( 'declared_content_length' ) );
	}

	public function test_malformed_content_lengths_are_rejected() {
		foreach ( array( '-1', '+1', '1.5', ' 12', '12 ', 'abc' ) as $value ) {
			$_SERVER['CONTENT_LENGTH'] = $value;
			$this->assertFalse( self::priv( 'declared_content_length' ), "length '$value' must be rejected" );
		}
	}

	public function test_stream_reader_never_returns_more_than_limit_plus_one() {
		$payload = str_repeat( 'a', Ainepay_Webhook_Handler::MAX_BODY_BYTES + 100 );
		$stream  = fopen( 'php://temp', 'w+b' );
		fwrite( $stream, $payload );
		rewind( $stream );
		$body    = self::priv( 'read_request_body', array( $stream ) );
		fclose( $stream );

		$this->assertIsString( $body );
		$this->assertSame( Ainepay_Webhook_Handler::MAX_BODY_BYTES + 1, strlen( $body ) );
	}
}
