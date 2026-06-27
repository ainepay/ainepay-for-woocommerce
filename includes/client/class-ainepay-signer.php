<?php
/**
 * Request signing and webhook verification for the AinePay API.
 *
 * Mirrors the AinePay server-side request signing and notification
 * verification (HMAC-SHA256 with Java URLEncoder canonicalisation).
 *
 * Golden vectors live in tests/fixtures/test-vectors.json; the PHPUnit suite
 * asserts this class reproduces them byte-for-byte.
 *
 * @package AinePay\WooCommerce
 */

defined( 'ABSPATH' ) || exit;

/**
 * Stateless HMAC-SHA256 signer/verifier for AinePay.
 */
class Ainepay_Signer {

	/**
	 * Maximum recv-window (ms) a merchant will honour for inbound notifications,
	 * regardless of the value the sender claims. Matches AinepayVerifier.
	 */
	const MAX_ACCEPTABLE_RECV_WINDOW = 300000;

	/**
	 * Encode a string exactly like Java's {@code URLEncoder.encode(s, "UTF-8")}
	 * (i.e. application/x-www-form-urlencoded semantics):
	 *   - unreserved A-Z a-z 0-9 and "-" "_" "." "*" pass through,
	 *   - space becomes "+",
	 *   - everything else is percent-encoded from its UTF-8 bytes (uppercase hex).
	 *
	 * PHP's rawurlencode()/urlencode() differ on "*", "~" and space, so we encode
	 * byte-wise to stay identical to the backend.
	 *
	 * @param string $value Raw string.
	 * @return string Encoded string.
	 */
	public static function java_url_encode( $value ) {
		$value = (string) $value;
		$out   = '';
		$len   = strlen( $value );
		for ( $i = 0; $i < $len; $i++ ) {
			$ch  = $value[ $i ];
			$ord = ord( $ch );
			$is_unreserved =
				( $ord >= 0x41 && $ord <= 0x5A ) || // A-Z.
				( $ord >= 0x61 && $ord <= 0x7A ) || // a-z.
				( $ord >= 0x30 && $ord <= 0x39 );   // 0-9.
			if ( $is_unreserved || '-' === $ch || '_' === $ch || '.' === $ch || '*' === $ch ) {
				$out .= $ch;
			} elseif ( ' ' === $ch ) {
				$out .= '+';
			} else {
				$out .= '%' . strtoupper( str_pad( dechex( $ord ), 2, '0', STR_PAD_LEFT ) );
			}
		}
		return $out;
	}

	/**
	 * Decode an AinePay secret ("sv_<base64url>") into raw key bytes.
	 *
	 * @param string $secret_key Secret with optional "sv_" prefix.
	 * @return string Raw bytes (empty string on failure).
	 */
	public static function decode_secret( $secret_key ) {
		$secret_key = (string) $secret_key;
		$b64 = ( 0 === strpos( $secret_key, 'sv_' ) ) ? substr( $secret_key, 3 ) : $secret_key;
		// base64url -> base64, restore padding.
		$b64 = strtr( $b64, '-_', '+/' );
		$pad = strlen( $b64 ) % 4;
		if ( $pad > 0 ) {
			$b64 .= str_repeat( '=', 4 - $pad );
		}
		$decoded = base64_decode( $b64, true );
		return false === $decoded ? '' : $decoded;
	}

	/**
	 * Build the canonical signing payload from a list of key/value pairs.
	 *
	 * Sort by key ascending, then by value ascending; URL-encode each part;
	 * join with "&"; then append "&timestamp=<ts>&recvWindow=<rw>" (unencoded).
	 *
	 * @param array  $pairs       List of [key, value] pairs (values stringified).
	 * @param string $timestamp   Millisecond timestamp.
	 * @param string $recv_window Receive window (ms).
	 * @return string Canonical payload string.
	 */
	public static function build_payload( array $pairs, $timestamp, $recv_window ) {
		$normalized = array();
		foreach ( $pairs as $pair ) {
			$key   = isset( $pair[0] ) ? (string) $pair[0] : '';
			$value = isset( $pair[1] ) && null !== $pair[1] ? (string) $pair[1] : '';
			$normalized[] = array( $key, $value );
		}

		usort(
			$normalized,
			function ( $a, $b ) {
				if ( $a[0] !== $b[0] ) {
					return strcmp( $a[0], $b[0] );
				}
				return strcmp( $a[1], $b[1] );
			}
		);

		$parts = array();
		foreach ( $normalized as $pair ) {
			$parts[] = self::java_url_encode( $pair[0] ) . '=' . self::java_url_encode( $pair[1] );
		}
		$body   = implode( '&', $parts );
		$suffix = 'timestamp=' . $timestamp . '&recvWindow=' . $recv_window;

		return '' === $body ? $suffix : $body . '&' . $suffix;
	}

	/**
	 * Sign a request (mirror of AinepaySigner.sign).
	 *
	 * @param array  $pairs       List of [key, value] pairs.
	 * @param string $timestamp   Millisecond timestamp.
	 * @param string $recv_window Receive window (ms).
	 * @param string $secret_key  Secret ("sv_...").
	 * @return string Lowercase hex HMAC-SHA256 signature.
	 */
	public static function sign( array $pairs, $timestamp, $recv_window, $secret_key ) {
		$payload = self::build_payload( $pairs, $timestamp, $recv_window );
		$secret  = self::decode_secret( $secret_key );
		return hash_hmac( 'sha256', $payload, $secret );
	}

	/**
	 * Convert an associative parameter map into a list of [key, value] pairs.
	 * Array values expand into repeated keys (e.g. orderIds => multiple entries).
	 *
	 * @param array $params Associative map.
	 * @return array List of [key, value] pairs.
	 */
	public static function params_to_pairs( array $params ) {
		$pairs = array();
		foreach ( $params as $key => $value ) {
			if ( is_array( $value ) ) {
				foreach ( $value as $v ) {
					$pairs[] = array( (string) $key, null === $v ? '' : (string) $v );
				}
			} else {
				$pairs[] = array( (string) $key, null === $value ? '' : (string) $value );
			}
		}
		return $pairs;
	}

	/**
	 * Parse a urlencoded notification body into an associative map.
	 *
	 * @param string $raw_body Raw POST body.
	 * @return array<string,string>
	 */
	public static function parse_body( $raw_body ) {
		$map = array();
		$raw_body = (string) $raw_body;
		if ( '' === $raw_body ) {
			return $map;
		}
		foreach ( explode( '&', $raw_body ) as $pair ) {
			$idx = strpos( $pair, '=' );
			if ( false === $idx ) {
				continue;
			}
			$key   = urldecode( substr( $pair, 0, $idx ) );
			$value = urldecode( substr( $pair, $idx + 1 ) );
			$map[ $key ] = $value;
		}
		return $map;
	}

	/**
	 * Build the canonical string for a notification body: parse, sort by field
	 * name ascending, re-encode (mirror of AinepayVerifier.toCanonical).
	 *
	 * @param array $fields Field map.
	 * @return string
	 */
	public static function notify_canonical( array $fields ) {
		ksort( $fields, SORT_STRING );
		$parts = array();
		foreach ( $fields as $key => $value ) {
			$parts[] = self::java_url_encode( (string) $key ) . '=' . self::java_url_encode( null === $value ? '' : (string) $value );
		}
		return implode( '&', $parts );
	}

	/**
	 * Verify an inbound notification signature, including the time-window check
	 * (mirror of AinepayVerifier.verify with timestamp/recvWindow).
	 *
	 * @param string $raw_body     Raw POST body (urlencoded form).
	 * @param string $signature    Value of the x-api-signature header.
	 * @param int    $timestamp    Value of the x-api-timestamp header (ms).
	 * @param int    $recv_window  Value of the x-api-recv-window header (ms).
	 * @param string $notify_secret Notification secret ("sv_...").
	 * @param int    $now_ms       Current time in ms (injectable for testing).
	 * @return bool True when the signature and time window are both valid.
	 */
	public static function verify_notify( $raw_body, $signature, $timestamp, $recv_window, $notify_secret, $now_ms = null ) {
		$timestamp   = (int) $timestamp;
		$recv_window = (int) $recv_window;
		$effective   = min( $recv_window, self::MAX_ACCEPTABLE_RECV_WINDOW );
		if ( $effective <= 0 ) {
			return false;
		}
		if ( null === $now_ms ) {
			$now_ms = (int) round( microtime( true ) * 1000 );
		}
		if ( abs( $now_ms - $timestamp ) > $effective ) {
			return false;
		}

		$canonical = self::notify_canonical( self::parse_body( $raw_body ) );
		$payload   = '' === $canonical
			? 'timestamp=' . $timestamp . '&recvWindow=' . $recv_window
			: $canonical . '&timestamp=' . $timestamp . '&recvWindow=' . $recv_window;

		$secret   = self::decode_secret( $notify_secret );
		$expected = hash_hmac( 'sha256', $payload, $secret );
		$given    = is_string( $signature ) ? strtolower( $signature ) : '';

		return hash_equals( $expected, $given );
	}

	/**
	 * Verify a legacy body-only notification signature (no timestamp window).
	 * Provided for golden-vector regression only; production uses verify_notify().
	 *
	 * @param string $raw_body      Raw POST body.
	 * @param string $signature     Signature to check.
	 * @param string $notify_secret Notification secret.
	 * @return bool
	 */
	public static function verify_notify_legacy( $raw_body, $signature, $notify_secret ) {
		$canonical = self::notify_canonical( self::parse_body( $raw_body ) );
		$secret    = self::decode_secret( $notify_secret );
		$expected  = hash_hmac( 'sha256', $canonical, $secret );
		$given     = is_string( $signature ) ? strtolower( $signature ) : '';
		return hash_equals( $expected, $given );
	}
}
