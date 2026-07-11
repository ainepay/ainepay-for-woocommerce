<?php
/**
 * Offline CREATE2 payment-address verification.
 *
 * Reproduces the AinePay wallet CREATE2 derivation so the plugin can confirm,
 * without trusting the backend, that the payment address AinePay returns is
 * deterministically derived from the merchant's collection address. A non-match
 * means the address must be rejected (potential fund-diversion attack).
 *
 *   initCodeHash = keccak256( EIP-1167 minimal-proxy init code with <impl> )
 *   salt         = keccak256( abi.encode(
 *                      bytes32 keccak256(merchantId),
 *                      bytes32 keccak256(userId),
 *                      uint256 version,
 *                      address destination,
 *                      uint256 chainId ) )
 *   address      = keccak256( 0xff ++ factory ++ salt ++ initCodeHash )[12:]
 *
 * Golden vectors: tests/fixtures/test-vectors.json (create2 section).
 *
 * @package AinePay\WooCommerce
 */

defined( 'ABSPATH' ) || exit;

use kornrunner\Keccak;

/**
 * CREATE2 forwarder address predictor / verifier.
 */
class Ainepay_Address_Validator {

	/**
	 * EIP-1167 minimal proxy init code, split around the implementation address.
	 */
	const PROXY_PREFIX = '3d602d80600a3d3981f3363d3d373d3d3d363d73';
	const PROXY_SUFFIX = '5af43d82803e903d91602b57fd5bf3';

	/**
	 * Whether the keccak dependency is available.
	 *
	 * @return bool
	 */
	public static function is_available() {
		return class_exists( '\kornrunner\Keccak' );
	}

	/**
	 * Predict the CREATE2 forwarder address.
	 *
	 * @param string     $factory     Factory contract address (0x...).
	 * @param string     $impl        Implementation contract address (0x...).
	 * @param string     $merchant_id Merchant id (UTF-8 string).
	 * @param string     $user_id     User id (UTF-8 string).
	 * @param string     $destination Collection address (0x...).
	 * @param int|string $version     Forwarder version.
	 * @param int|string $chain_id    EVM chain id.
	 * @return string Checksummed predicted address (0x...).
	 * @throws InvalidArgumentException When an input is malformed.
	 */
	public static function predict_address( $factory, $impl, $merchant_id, $user_id, $destination, $version, $chain_id ) {
		$factory_hex = self::normalize_address_hex( $factory );
		$impl_hex    = self::normalize_address_hex( $impl );
		$dest_hex    = self::normalize_address_hex( $destination );

		// init code hash (EIP-1167 minimal proxy).
		$init_code      = self::PROXY_PREFIX . $impl_hex . self::PROXY_SUFFIX;
		$init_code_hash = Keccak::hash( self::hex2bin_strict( $init_code ), 256 );

		// salt = keccak256( abi.encode(...) ), 5 static 32-byte words.
		$merchant_hash = Keccak::hash( $merchant_id, 256 ); // string keccak, hex (no 0x).
		$user_hash     = Keccak::hash( $user_id, 256 );

		$abi  = $merchant_hash;                                   // bytes32.
		$abi .= $user_hash;                                       // bytes32.
		$abi .= self::uint256_hex( $version );                    // uint256.
		$abi .= str_pad( $dest_hex, 64, '0', STR_PAD_LEFT );      // address, left-padded to 32 bytes.
		$abi .= self::uint256_hex( $chain_id );                   // uint256.

		$salt = Keccak::hash( self::hex2bin_strict( $abi ), 256 );

		// address = keccak256( 0xff ++ factory ++ salt ++ initCodeHash )[12:].
		$packed = 'ff' . $factory_hex . $salt . $init_code_hash;
		$hash   = Keccak::hash( self::hex2bin_strict( $packed ), 256 );
		$addr   = substr( $hash, 24 ); // last 20 bytes = 40 hex chars.

		return self::to_checksum_address( $addr );
	}

	/**
	 * Verify that a payment address matches the deterministic derivation.
	 *
	 * @param string     $address     Address returned by AinePay.
	 * @param string     $factory     Factory contract address.
	 * @param string     $impl        Implementation contract address.
	 * @param string     $merchant_id Merchant id.
	 * @param string     $user_id     User id.
	 * @param string     $destination Collection address.
	 * @param int|string $version     Forwarder version.
	 * @param int|string $chain_id    EVM chain id.
	 * @return bool True when the address is verified; false on any mismatch/error.
	 */
	public static function verify( $address, $factory, $impl, $merchant_id, $user_id, $destination, $version, $chain_id ) {
		try {
			$predicted = self::predict_address( $factory, $impl, $merchant_id, $user_id, $destination, $version, $chain_id );
			$given     = self::to_checksum_address( self::normalize_address_hex( $address ) );
			return hash_equals( $predicted, $given );
		} catch ( \Throwable $e ) {
			// Includes the case where the keccak dependency is missing (Error).
			return false;
		}
	}

	/**
	 * Lowercase 40-hex-char form of an address (no 0x), validated.
	 *
	 * @param string $address Address string.
	 * @return string 40 lowercase hex chars.
	 * @throws InvalidArgumentException When not a 20-byte hex address.
	 */
	private static function normalize_address_hex( $address ) {
		$hex = strtolower( (string) $address );
		if ( 0 === strpos( $hex, '0x' ) ) {
			$hex = substr( $hex, 2 );
		}
		if ( 40 !== strlen( $hex ) || ! ctype_xdigit( $hex ) ) {
			throw new InvalidArgumentException( 'Invalid address: ' . esc_html( $address ) );
		}
		return $hex;
	}

	/**
	 * Encode a non-negative integer as a 32-byte (64 hex char) big-endian word.
	 *
	 * @param int|string $value Non-negative integer (int or decimal string).
	 * @return string 64 hex chars.
	 * @throws InvalidArgumentException When the value is not a valid uint.
	 */
	private static function uint256_hex( $value ) {
		$dec = (string) $value;
		if ( '' === $dec || ! ctype_digit( $dec ) ) {
			throw new InvalidArgumentException( 'Invalid uint256: ' . esc_html( $value ) );
		}
		$hex = self::dec2hex( $dec );
		if ( strlen( $hex ) > 64 ) {
			throw new InvalidArgumentException( 'uint256 overflow: ' . esc_html( $value ) );
		}
		return str_pad( $hex, 64, '0', STR_PAD_LEFT );
	}

	/**
	 * Convert a non-negative decimal string to hex, using bcmath/gmp when present
	 * and falling back to native int (sufficient for version/chainId ranges).
	 *
	 * @param string $dec Decimal string.
	 * @return string Hex string (no leading zeros, lowercase, '' for zero handled by caller padding).
	 */
	private static function dec2hex( $dec ) {
		if ( function_exists( 'gmp_init' ) ) {
			return gmp_strval( gmp_init( $dec, 10 ), 16 );
		}
		if ( function_exists( 'bcadd' ) ) {
			$hex = '';
			$n   = $dec;
			if ( '0' === $n ) {
				return '0';
			}
			while ( bccomp( $n, '0' ) > 0 ) {
				$rem = bcmod( $n, '16' );
				$hex = dechex( (int) $rem ) . $hex;
				$n   = bcdiv( $n, '16', 0 );
			}
			return $hex;
		}
		return dechex( (int) $dec );
	}

	/**
	 * Strict hex-to-binary (rejects odd length / non-hex).
	 *
	 * @param string $hex Hex string (no 0x).
	 * @return string Raw bytes.
	 * @throws InvalidArgumentException When the input is not valid hex.
	 */
	private static function hex2bin_strict( $hex ) {
		if ( 0 !== strlen( $hex ) % 2 || ! ctype_xdigit( $hex ) ) {
			throw new InvalidArgumentException( 'Invalid hex' );
		}
		return hex2bin( $hex );
	}

	/**
	 * Apply EIP-55 checksum casing to a 40-hex-char address.
	 *
	 * @param string $hex 40 hex chars (no 0x), any case.
	 * @return string Checksummed address with 0x prefix.
	 */
	public static function to_checksum_address( $hex ) {
		$hex  = strtolower( $hex );
		$hash = Keccak::hash( $hex, 256 );
		$out  = '0x';
		for ( $i = 0; $i < 40; $i++ ) {
			$char = $hex[ $i ];
			if ( ctype_digit( $char ) ) {
				$out .= $char;
				continue;
			}
			$out .= ( hexdec( $hash[ $i ] ) >= 8 ) ? strtoupper( $char ) : $char;
		}
		return $out;
	}
}
