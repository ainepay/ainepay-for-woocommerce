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

	const QUERY_VAR     = 'ainepay-notify';
	const CALLBACK_HDR  = 'x-callback-token';

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
			$request_uri = isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '';
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
		} else {
			$this->handle_notification();
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
		$raw_body    = file_get_contents( 'php://input' );
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
