<?php
/**
 * Golden-vector regression for Ainepay_Signer.
 *
 * Vectors are generated from the authoritative backend/wallet logic by
 * tests/fixtures/gen-vectors.js into tests/fixtures/test-vectors.json. This
 * suite asserts the PHP signer reproduces them byte-for-byte.
 *
 * @package AinePay\WooCommerce
 */

use PHPUnit\Framework\TestCase;

/**
 * @covers Ainepay_Signer
 */
class SignerTest extends TestCase {

	/**
	 * Decoded contents of tests/fixtures/test-vectors.json.
	 *
	 * @var array
	 */
	private static $vectors;

	public static function setUpBeforeClass(): void {
		$path = __DIR__ . '/fixtures/test-vectors.json';
		self::$vectors = json_decode( file_get_contents( $path ), true );
		if ( ! is_array( self::$vectors ) ) {
			throw new RuntimeException( 'Could not read tests/fixtures/test-vectors.json' );
		}
	}

	/**
	 * @dataProvider signatureVectorProvider
	 */
	public function test_request_signature_matches_vector( $name, $pairs, $timestamp, $recv_window, $expected_payload, $expected_signature, $secret ) {
		$payload = Ainepay_Signer::build_payload( $pairs, $timestamp, $recv_window );
		$this->assertSame( $expected_payload, $payload, "payload mismatch for vector: {$name}" );

		$signature = Ainepay_Signer::sign( $pairs, $timestamp, $recv_window, $secret );
		$this->assertSame( $expected_signature, $signature, "signature mismatch for vector: {$name}" );
	}

	public function signatureVectorProvider() {
		$path    = __DIR__ . '/fixtures/test-vectors.json';
		$vectors = json_decode( file_get_contents( $path ), true );
		$secret  = $vectors['signature']['secret'];
		$out     = array();
		foreach ( $vectors['signature']['cases'] as $case ) {
			// JSON pairs are [[k,v],...]; pass through unchanged.
			$out[ $case['name'] ] = array(
				$case['name'],
				$case['pairs'],
				$case['timestamp'],
				$case['recvWindow'],
				$case['payload'],
				$case['signature'],
				$secret,
			);
		}
		return $out;
	}

	public function test_notify_legacy_known_vector() {
		$v      = self::$vectors['notify'];
		$secret = $v['secret'];
		$body   = $v['legacy_known_vector']['canonical'];
		$sig    = $v['legacy_known_vector']['signature'];

		$this->assertTrue(
			Ainepay_Signer::verify_notify_legacy( $body, $sig, $secret ),
			'legacy notify vector failed to verify'
		);
		// Tamper check.
		$this->assertFalse(
			Ainepay_Signer::verify_notify_legacy( $body, 'deadbeef', $secret )
		);
	}

	public function test_notify_with_timestamp_vector() {
		$v      = self::$vectors['notify'];
		$secret = $v['secret'];
		$wt     = $v['with_timestamp'];

		// Reconstruct the raw urlencoded body from the field map (already sorted).
		$pairs = array();
		foreach ( $wt['body'] as $k => $val ) {
			$pairs[] = $k . '=' . rawurlencode( $val );
		}
		$raw_body  = implode( '&', $pairs );
		$timestamp = (int) $wt['timestamp'];
		$recv      = (int) $wt['recvWindow'];

		// "now" is pinned to the vector timestamp so the time window passes.
		$this->assertTrue(
			Ainepay_Signer::verify_notify( $raw_body, $wt['signature'], $timestamp, $recv, $secret, $timestamp )
		);

		// Stale timestamp (beyond recv window) must be rejected even with a valid sig.
		$stale = $timestamp + $recv + 60000;
		$this->assertFalse(
			Ainepay_Signer::verify_notify( $raw_body, $wt['signature'], $timestamp, $recv, $secret, $stale )
		);
	}

	public function test_java_url_encode_semantics() {
		// Space -> '+', not %20.
		$this->assertSame( 'hello+world', Ainepay_Signer::java_url_encode( 'hello world' ) );
		// Brackets -> %5B %5D.
		$this->assertSame( 'orderIds%5B0%5D', Ainepay_Signer::java_url_encode( 'orderIds[0]' ) );
		// Unreserved incl. '*' passes through.
		$this->assertSame( 'a-_.*Z9', Ainepay_Signer::java_url_encode( 'a-_.*Z9' ) );
	}
}
