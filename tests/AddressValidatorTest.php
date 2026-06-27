<?php
/**
 * Golden-vector regression for Ainepay_Address_Validator.
 *
 * Vectors from tests/fixtures/test-vectors.json (create2 section), derived from
 * the authoritative wallet CREATE2 logic.
 *
 * Requires the kornrunner/keccak dependency (composer install).
 *
 * @package AinePay\WooCommerce
 */

use PHPUnit\Framework\TestCase;

/**
 * @covers Ainepay_Address_Validator
 */
class AddressValidatorTest extends TestCase {

	private static $vectors;

	public static function setUpBeforeClass(): void {
		if ( ! class_exists( '\kornrunner\Keccak' ) ) {
			self::markTestSkipped( 'kornrunner/keccak not installed (run composer install).' );
		}
		$path = __DIR__ . '/fixtures/test-vectors.json';
		self::$vectors = json_decode( file_get_contents( $path ), true );
	}

	public function test_official_test_vector() {
		$v = self::$vectors['create2']['official_test'];
		$addr = Ainepay_Address_Validator::predict_address(
			'0x1111111111111111111111111111111111111111',
			'0x2222222222222222222222222222222222222222',
			'merchant-demo',
			'user-demo',
			'0x3333333333333333333333333333333333333333',
			1,
			1
		);
		$this->assertSame( $v['address'], $addr );
	}

	public function test_mainnet_example_vector() {
		$v = self::$vectors['create2']['mainnet_example'];
		$addr = Ainepay_Address_Validator::predict_address(
			'0x06559ab75cd906e2ecd9c3e91459eea558e2ec1b',
			'0x42eb2a5b755551d5f386f2c79807abd438341557',
			'20001',
			'guest_order_1042',
			'0x000000000000000000000000000000000000dEaD',
			1,
			1
		);
		$this->assertSame( $v['address'], $addr );
	}

	public function test_verify_accepts_matching_address() {
		$v = self::$vectors['create2']['official_test'];
		$this->assertTrue(
			Ainepay_Address_Validator::verify(
				$v['address'],
				'0x1111111111111111111111111111111111111111',
				'0x2222222222222222222222222222222222222222',
				'merchant-demo',
				'user-demo',
				'0x3333333333333333333333333333333333333333',
				1,
				1
			)
		);
		// Case-insensitive: lowercased input still verifies.
		$this->assertTrue(
			Ainepay_Address_Validator::verify(
				strtolower( $v['address'] ),
				'0x1111111111111111111111111111111111111111',
				'0x2222222222222222222222222222222222222222',
				'merchant-demo',
				'user-demo',
				'0x3333333333333333333333333333333333333333',
				1,
				1
			)
		);
	}

	public function test_verify_rejects_wrong_destination() {
		$v = self::$vectors['create2']['official_test'];
		// Same predicted address but a different collection address -> must fail.
		$this->assertFalse(
			Ainepay_Address_Validator::verify(
				$v['address'],
				'0x1111111111111111111111111111111111111111',
				'0x2222222222222222222222222222222222222222',
				'merchant-demo',
				'user-demo',
				'0x4444444444444444444444444444444444444444',
				1,
				1
			)
		);
	}

	public function test_verify_rejects_invalid_address() {
		$this->assertFalse(
			Ainepay_Address_Validator::verify(
				'not-an-address',
				'0x1111111111111111111111111111111111111111',
				'0x2222222222222222222222222222222222222222',
				'merchant-demo',
				'user-demo',
				'0x3333333333333333333333333333333333333333',
				1,
				1
			)
		);
	}
}
