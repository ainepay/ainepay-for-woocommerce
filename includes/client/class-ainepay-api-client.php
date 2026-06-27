<?php
/**
 * HTTP client for the AinePay merchant API.
 *
 * Wraps wp_remote_* with request signing (x-api-key / x-api-signature /
 * x-api-timestamp / x-api-recv-window) and normalises the
 * {success, code, data, msg} envelope into a WP_Error or a data array.
 *
 * @package AinePay\WooCommerce
 */

defined( 'ABSPATH' ) || exit;

/**
 * AinePay merchant API client.
 */
class Ainepay_Api_Client {

	const DEFAULT_RECV_WINDOW = 60000;
	const DEFAULT_TIMEOUT     = 20;

	/**
	 * API base URL, no trailing slash.
	 *
	 * @var string
	 */
	private $base_url;

	/**
	 * API key (sent as x-api-key).
	 *
	 * @var string
	 */
	private $api_key;

	/**
	 * API signing secret ("sv_...").
	 *
	 * @var string
	 */
	private $api_secret;

	/**
	 * Receive window in milliseconds.
	 *
	 * @var int
	 */
	private $recv_window;

	/**
	 * Constructor.
	 *
	 * @param string $base_url    API base URL.
	 * @param string $api_key     API key.
	 * @param string $api_secret  API signing secret.
	 * @param int    $recv_window Receive window (ms).
	 */
	public function __construct( $base_url, $api_key, $api_secret, $recv_window = self::DEFAULT_RECV_WINDOW ) {
		$this->base_url    = untrailingslashit( trim( (string) $base_url ) );
		$this->api_key     = trim( (string) $api_key );
		$this->api_secret  = (string) $api_secret;
		$this->recv_window = $recv_window > 0 ? (int) $recv_window : self::DEFAULT_RECV_WINDOW;
	}

	/**
	 * GET /api/merchant/pay/info — supported coins. No authentication required.
	 *
	 * @return array|WP_Error data array on success, WP_Error on failure.
	 */
	public function get_pay_info() {
		return $this->request( 'GET', '/api/merchant/pay/info', array(), false );
	}

	/**
	 * POST /api/merchant/pay — create an inline order. Authenticated.
	 *
	 * @param array $params Order params (orderId, userId, coin, chain, qty, ...).
	 * @return array|WP_Error
	 */
	public function create_pay_order( array $params ) {
		return $this->request( 'POST', '/api/merchant/pay', $params, true );
	}

	/**
	 * GET /api/merchant/order — query orders by id. Authenticated.
	 *
	 * @param string[] $order_ids 1-20 merchant order ids.
	 * @return array|WP_Error
	 */
	public function get_orders( array $order_ids ) {
		$order_ids = array_values( array_filter( array_map( 'strval', $order_ids ), 'strlen' ) );
		if ( empty( $order_ids ) ) {
			return new WP_Error( 'ainepay_bad_request', __( 'No order ids supplied.', 'ainepay-for-woocommerce' ) );
		}
		return $this->request( 'GET', '/api/merchant/order', array( 'orderIds' => $order_ids ), true );
	}

	/**
	 * POST /api/merchant/order/cancel — cancel an INIT order. Authenticated.
	 *
	 * The backend (ApiMerchantController::cancelOrder, CancelSource.API_INIT) only
	 * cancels orders still in INIT and is idempotent: cancelling an already-CANCEL
	 * order returns success. A non-INIT order (PAID/PENDING/EXPIRED/REFUND) is
	 * rejected with code ORDER_STATUS_INVALID (26), which the caller must treat as
	 * "re-query the authoritative status" rather than a hard failure.
	 *
	 * @param string $order_id Merchant order id.
	 * @return array|WP_Error Data array (the cancelled OrderVo) on success;
	 *                        WP_Error on failure (ainepay_api_error carries
	 *                        error_data['code'] / error_data['status']).
	 */
	public function cancel_order( $order_id ) {
		$order_id = trim( (string) $order_id );
		if ( '' === $order_id ) {
			return new WP_Error( 'ainepay_bad_request', __( 'No order id supplied.', 'ainepay-for-woocommerce' ) );
		}
		return $this->request( 'POST', '/api/merchant/order/cancel', array( 'orderId' => $order_id ), true );
	}

	/**
	 * Perform a signed (or unsigned) request and normalise the response.
	 *
	 * @param string $method HTTP method (GET|POST).
	 * @param string $path   Request path (leading slash).
	 * @param array  $params Request parameters.
	 * @param bool   $signed Whether to attach authentication headers.
	 * @return array|WP_Error
	 */
	private function request( $method, $path, array $params, $signed ) {
		if ( '' === $this->base_url ) {
			return new WP_Error( 'ainepay_config', __( 'AinePay API base URL is not configured.', 'ainepay-for-woocommerce' ) );
		}

		$method  = strtoupper( $method );
		$is_get  = ( 'GET' === $method );
		$url     = $this->base_url . $path;
		$headers = array( 'Accept' => 'application/json' );
		$body    = null;

		// Expand params into ordered pairs once; reused for query/body and signing.
		$pairs = Ainepay_Signer::params_to_pairs( $params );

		if ( $is_get ) {
			if ( ! empty( $pairs ) ) {
				$url .= '?' . $this->build_query( $pairs );
			}
		} else {
			$headers['Content-Type'] = 'application/x-www-form-urlencoded';
			$body = $this->build_query( $pairs );
		}

		if ( $signed ) {
			if ( '' === $this->api_key || '' === $this->api_secret ) {
				return new WP_Error( 'ainepay_config', __( 'AinePay API key/secret is not configured.', 'ainepay-for-woocommerce' ) );
			}
			$timestamp = (string) (int) round( microtime( true ) * 1000 );
			$recv      = (string) $this->recv_window;
			$signature = Ainepay_Signer::sign( $pairs, $timestamp, $recv, $this->api_secret );

			$headers['x-api-key']         = $this->api_key;
			$headers['x-api-signature']   = $signature;
			$headers['x-api-timestamp']   = $timestamp;
			$headers['x-api-recv-window'] = $recv;
		}

		$args = array(
			'method'  => $method,
			'headers' => $headers,
			'timeout' => self::DEFAULT_TIMEOUT,
		);
		if ( null !== $body ) {
			$args['body'] = $body;
		}

		Ainepay_Logger::debug(
			'API request',
			array(
				'method' => $method,
				'path'   => $path,
				'signed' => $signed,
			)
		);

		$response = wp_remote_request( $url, $args );

		if ( is_wp_error( $response ) ) {
			Ainepay_Logger::error( 'API transport error: ' . $response->get_error_message(), array( 'path' => $path ) );
			return $response;
		}

		return $this->parse_response( $response, $path );
	}

	/**
	 * Build a urlencoded query string from ordered pairs, matching the signed
	 * representation (Java URLEncoder semantics, original pair order preserved).
	 *
	 * @param array $pairs List of [key, value] pairs.
	 * @return string
	 */
	private function build_query( array $pairs ) {
		$parts = array();
		foreach ( $pairs as $pair ) {
			$parts[] = Ainepay_Signer::java_url_encode( $pair[0] ) . '=' . Ainepay_Signer::java_url_encode( $pair[1] );
		}
		return implode( '&', $parts );
	}

	/**
	 * Normalise an HTTP response into the data array or a WP_Error.
	 *
	 * @param array  $response wp_remote_* response.
	 * @param string $path     Request path (for logging).
	 * @return array|WP_Error
	 */
	private function parse_response( $response, $path ) {
		$status = (int) wp_remote_retrieve_response_code( $response );
		$raw    = wp_remote_retrieve_body( $response );
		$json   = json_decode( $raw, true );

		if ( ! is_array( $json ) ) {
			Ainepay_Logger::error( 'API non-JSON response', array( 'path' => $path, 'status' => $status ) );
			return new WP_Error(
				'ainepay_bad_response',
				/* translators: %d: HTTP status code. */
				sprintf( __( 'Unexpected response from AinePay (HTTP %d).', 'ainepay-for-woocommerce' ), $status )
			);
		}

		$success = ! empty( $json['success'] );
		$code    = isset( $json['code'] ) ? (int) $json['code'] : -1;

		if ( ! $success || 0 !== $code ) {
			$msg = isset( $json['msg'] ) && '' !== $json['msg']
				? (string) $json['msg']
				: __( 'AinePay returned an error.', 'ainepay-for-woocommerce' );
			Ainepay_Logger::error(
				'API error response',
				array( 'path' => $path, 'status' => $status, 'code' => $code, 'msg' => $msg )
			);
			return new WP_Error( 'ainepay_api_error', $msg, array( 'code' => $code, 'status' => $status ) );
		}

		return isset( $json['data'] ) && is_array( $json['data'] ) ? $json['data'] : array();
	}
}
