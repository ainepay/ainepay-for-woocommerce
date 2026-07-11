<?php
/**
 * Webhook endpoint: GET connectivity test and POST payment notifications.
 *
 * AinePay always calls "{notifyUrl}/ainepay/notify", so we register a rewrite
 * endpoint that resolves https://site/ainepay/notify, with a plain-permalink
 * fallback (?ainepay-notify=1).
 *
 *  - GET  : connectivity test. Echo back the x-callback-token header with 2xx.
 *  - POST : signed payment notification. Verify with the notify secret, then
 *           hand off to Ainepay_Order_Sync.
 *
 * @package AinePay\WooCommerce
 */

defined( 'ABSPATH' ) || exit;

/**
 * Registers and serves the /ainepay/notify endpoint.
 */
class Ainepay_Webhook_Handler {

	const QUERY_VAR      = 'ainepay-notify';
	const CALLBACK_HDR   = 'x-callback-token';
	const MAX_BODY_BYTES = 16384;

	/**
	 * Register routing hooks.
	 *
	 * @return void
	 */
	public static function init() {
		$self = new self();
		add_action( 'init', array( $self, 'add_rewrite' ) );
		add_filter( 'query_vars', array( $self, 'add_query_var' ) );
		add_action( 'parse_request', array( $self, 'maybe_handle' ) );
	}

	/**
	 * Register the rewrite rule for /ainepay/notify.
	 *
	 * @return void
	 */
	public function add_rewrite() {
		add_rewrite_rule( '^ainepay/notify/?$', 'index.php?' . self::QUERY_VAR . '=1', 'top' );
	}

	/**
	 * Whitelist the query var.
	 *
	 * @param array $vars Query vars.
	 * @return array
	 */
	public function add_query_var( $vars ) {
		$vars[] = self::QUERY_VAR;
		return $vars;
	}

	/**
	 * Detect the endpoint as early as possible, covering both pretty and plain
	 * permalinks. Runs on parse_request so it works before the main query.
	 *
	 * @param WP $wp Current WordPress environment.
	 * @return void
	 */
	public function maybe_handle( $wp ) {
		$is_endpoint = false;

		if ( isset( $wp->query_vars[ self::QUERY_VAR ] ) ) {
			$is_endpoint = true;
		} elseif ( isset( $_GET[ self::QUERY_VAR ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$is_endpoint = true;
		} else {
			// Fallback: match the path suffix directly (plain permalinks).
			$request_uri = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';
			$path        = wp_parse_url( $request_uri, PHP_URL_PATH );
			if ( is_string( $path ) && preg_match( '#/ainepay/notify/?$#', $path ) ) {
				$is_endpoint = true;
			}
		}

		if ( ! $is_endpoint ) {
			return;
		}

		$method = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) : 'GET';
		if ( 'GET' === $method || 'HEAD' === $method ) {
			$this->handle_connectivity_test();
		} elseif ( 'POST' === $method ) {
			$this->handle_notification();
		} else {
			header( 'Allow: GET, HEAD, POST' );
			$this->respond( 405, 'method not allowed' );
		}
	}

	/**
	 * GET connectivity test: return 2xx and echo the callback token header.
	 *
	 * @return void
	 */
	private function handle_connectivity_test() {
		$token = self::header( self::CALLBACK_HDR );
		if ( '' !== $token ) {
			header( self::CALLBACK_HDR . ': ' . $token );
		}
		status_header( 200 );
		header( 'Content-Type: text/plain; charset=utf-8' );
		echo 'ok';
		exit;
	}

	/**
	 * POST notification: verify signature, then hand off to order sync.
	 *
	 * @return void
	 */
	private function handle_notification() {
		// Reject a declared oversized request before PHP copies or parses its body.
		// A missing Content-Length is valid for chunked HTTP, so the bounded stream
		// read below remains the authoritative limit in every case.
		$content_length = self::declared_content_length();
		if ( false === $content_length ) {
			$this->respond( 400, 'invalid content length' );
		}
		if ( null !== $content_length && $content_length > self::MAX_BODY_BYTES ) {
			$this->respond( 413, 'payload too large' );
		}

		$raw_body = self::read_request_body();
		if ( false === $raw_body ) {
			$this->respond( 400, 'invalid request body' );
		}
		if ( strlen( $raw_body ) > self::MAX_BODY_BYTES ) {
			$this->respond( 413, 'payload too large' );
		}

		$signature   = self::header( 'x-api-signature' );
		$timestamp   = (int) self::header( 'x-api-timestamp' );
		$recv_window = (int) self::header( 'x-api-recv-window' );

		$gateway = $this->gateway();
		$secret  = $gateway ? (string) $gateway->get_option( 'notify_secret' ) : '';

		if ( '' === $secret ) {
			Ainepay_Logger::error( 'Notification received but no notify secret is configured.' );
			$this->respond( 500, 'not configured' );
		}

		$valid = Ainepay_Signer::verify_notify( $raw_body, $signature, $timestamp, $recv_window, $secret );
		if ( ! $valid ) {
			Ainepay_Logger::error( 'Notification signature verification failed.' );
			$this->respond( 401, 'invalid signature' );
		}

		$fields = Ainepay_Signer::parse_body( $raw_body );

		$result = Ainepay_Order_Sync::handle_notification( $fields );

		switch ( $result ) {
			case Ainepay_Order_Sync::RESULT_OK:
			case Ainepay_Order_Sync::RESULT_NOT_FOUND:
				// Acknowledge: processed, duplicate, or an order we don't own.
				$this->respond( 200, 'ok' );
				break;

			case Ainepay_Order_Sync::RESULT_BUSY:
				// Lock contention: ask AinePay to retry; idempotency keeps it safe.
				$this->respond( 503, 'busy, retry' );
				break;

			case Ainepay_Order_Sync::RESULT_RETRY:
			default:
				// Transient failure (e.g. confirmation query failed): retry.
				$this->respond( 500, 'temporary error, retry' );
				break;
		}
	}

	/**
	 * Parse the optional Content-Length header without accepting signs, decimals,
	 * whitespace or overflow-like values.
	 *
	 * @return int|null|false Byte count, null when absent, false when malformed.
	 */
	private static function declared_content_length() {
		// Preserve whitespace/signs for the strict grammar check below; sanitizing
		// would normalize malformed protocol input into an accepted integer.
		$raw = isset( $_SERVER['CONTENT_LENGTH'] ) ? wp_unslash( $_SERVER['CONTENT_LENGTH'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- validated by the anchored digits-only regex before use.
		if ( '' === $raw ) {
			return null;
		}
		if ( ! is_string( $raw ) || 1 !== preg_match( '/\A[0-9]+\z/D', $raw ) ) {
			return false;
		}
		// Compare as a decimal string first so values larger than PHP_INT_MAX cannot
		// wrap into a small integer and bypass the early rejection.
		$normalised = ltrim( $raw, '0' );
		$normalised = '' === $normalised ? '0' : $normalised;
		$max        = (string) self::MAX_BODY_BYTES;
		if ( strlen( $normalised ) > strlen( $max )
			|| ( strlen( $normalised ) === strlen( $max ) && strcmp( $normalised, $max ) > 0 ) ) {
			return self::MAX_BODY_BYTES + 1;
		}
		return (int) $normalised;
	}

	/**
	 * Read at most MAX_BODY_BYTES + 1 bytes. The extra byte distinguishes an exact
	 * limit-sized body from an oversized/chunked body without ever buffering the
	 * remainder in plugin memory.
	 *
	 * @param resource|string $stream Input stream; injectable for bounded unit tests.
	 * @return string|false Bounded bytes, or false when the stream cannot be read.
	 */
	private static function read_request_body( $stream = 'php://input' ) {
		$owned  = ! is_resource( $stream );
		$handle = $owned ? @fopen( $stream, 'rb' ) : $stream; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		if ( false === $handle ) {
			return false;
		}
		$body = stream_get_contents( $handle, self::MAX_BODY_BYTES + 1 );
		if ( $owned ) {
			fclose( $handle );
		}
		return is_string( $body ) ? $body : false;
	}

	/**
	 * Send a plain-text response and stop.
	 *
	 * @param int    $code HTTP status code.
	 * @param string $body Response body.
	 * @return void
	 */
	private function respond( $code, $body ) {
		status_header( $code );
		header( 'Content-Type: text/plain; charset=utf-8' );
		echo esc_html( $body );
		exit;
	}

	/**
	 * Read an HTTP request header by name (lowercased), via $_SERVER.
	 *
	 * @param string $name Header name (lowercase, dashed).
	 * @return string
	 */
	private static function header( $name ) {
		$key = 'HTTP_' . strtoupper( str_replace( '-', '_', $name ) );
		if ( isset( $_SERVER[ $key ] ) ) {
			return trim( sanitize_text_field( wp_unslash( $_SERVER[ $key ] ) ) );
		}
		return '';
	}

	/**
	 * Get the AinePay gateway instance.
	 *
	 * @return Ainepay_Gateway|null
	 */
	private function gateway() {
		if ( ! function_exists( 'WC' ) || ! WC()->payment_gateways() ) {
			return null;
		}
		$gateways = WC()->payment_gateways()->payment_gateways();
		return isset( $gateways[ Ainepay_Plugin::GATEWAY_ID ] ) ? $gateways[ Ainepay_Plugin::GATEWAY_ID ] : null;
	}
}
