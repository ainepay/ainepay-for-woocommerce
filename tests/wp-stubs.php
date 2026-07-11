<?php
/**
 * Minimal WordPress / WooCommerce test harness.
 *
 * The fund/state state machine (Ainepay_Order_Sync) and the customer-cancel
 * throttle (Ainepay_Payment_Display) are plain classes that lean on a handful of
 * WP/WC globals (wc_get_order(s), $wpdb GET_LOCK, the gateway/API client,
 * transients and the async scheduler). Rather than boot a full WordPress, this
 * file provides in-memory stand-ins so those branches can be exercised in
 * isolation — matching the hand-written-fake style already used by
 * OrderHelperTest.
 *
 * Tests reset the shared state via Ainepay_Test_Env::reset() in setUp().
 *
 * @package AinePay\WooCommerce
 */

defined( 'ABSPATH' ) || define( 'ABSPATH', __DIR__ . '/' );

defined( 'AINEPAY_WC_PLUGIN_DIR' ) || define( 'AINEPAY_WC_PLUGIN_DIR', dirname( __DIR__ ) . '/' );

defined( 'MINUTE_IN_SECONDS' ) || define( 'MINUTE_IN_SECONDS', 60 );
defined( 'HOUR_IN_SECONDS' ) || define( 'HOUR_IN_SECONDS', 3600 );
defined( 'DAY_IN_SECONDS' ) || define( 'DAY_IN_SECONDS', 86400 );
defined( 'WEEK_IN_SECONDS' ) || define( 'WEEK_IN_SECONDS', 604800 );

/**
 * Shared mutable test state. Reset between tests.
 */
class Ainepay_Test_Env {

	/** @var array<int,WC_Order> id => order */
	public static $orders = array();

	/** @var array<string,mixed> transient key => value */
	public static $transients = array();

	/** @var array<string,mixed> option name => value */
	public static $options = array();

	/** @var array<int,array{hook:string,args:array,when:int}> scheduled actions */
	public static $scheduled = array();

	/** @var array<int,array{hook:string,args:array}> do_action() calls */
	public static $actions = array();

	/** @var array<string,mixed> filter name => forced return value */
	public static $filter_overrides = array();

	/** @var string GET_LOCK return value: '1' acquired, '0' contended. */
	public static $lock_result = '1';

	/** @var Ainepay_Fake_Gateway|null */
	public static $gateway = null;

	/** @var string client IP reported by WC_Geolocation::get_ip_address(). */
	public static $client_ip = '203.0.113.7';

	/** @var int Current multisite blog id. */
	public static $blog_id = 1;

	/** @var bool force every scheduling call to fail (return 0/false). */
	public static $schedule_fails = false;

	/** @var array|null args passed to the most recent wc_get_template() call. */
	public static $last_template = null;

	/** @var array<int,array<string,mixed>> wc_get_orders() query arguments. */
	public static $order_queries = array();

	/** @var array<int,array{url:string,args:array}> outbound safe HTTP calls. */
	public static $remote_requests = array();

	/** @var mixed Response returned by wp_safe_remote_request(). */
	public static $remote_response = null;

	/**
	 * Restore a clean slate.
	 *
	 * @return void
	 */
	public static function reset() {
		self::$orders           = array();
		self::$transients       = array();
		self::$options          = array();
		self::$scheduled        = array();
		self::$actions          = array();
		self::$filter_overrides = array();
		self::$lock_result      = '1';
		self::$gateway          = null;
		self::$client_ip        = '203.0.113.7';
		self::$blog_id          = 1;
		self::$schedule_fails   = false;
		self::$last_template    = null;
		self::$order_queries    = array();
		self::$remote_requests  = array();
		self::$remote_response  = null;
		if ( isset( $GLOBALS['wpdb'] ) && isset( $GLOBALS['wpdb']->prefix ) ) {
			$GLOBALS['wpdb']->prefix = 'wp_';
		}
	}

	/**
	 * Record a scheduled/enqueued action, emulating Action Scheduler's behaviour:
	 * a forced failure returns 0, and $unique rejects a duplicate of an already
	 * pending (hook, args) action.
	 *
	 * @param string $hook   Action hook.
	 * @param array  $args   Action arguments.
	 * @param int    $when   Timestamp (0 for async).
	 * @param bool   $unique Whether to de-duplicate against a pending match.
	 * @return int Action id (>0) on success, 0 on failure/duplicate.
	 */
	public static function record_schedule( $hook, $args, $when, $unique ) {
		if ( self::$schedule_fails ) {
			return 0;
		}
		if ( $unique ) {
			foreach ( self::$scheduled as $s ) {
				if ( $s['hook'] === $hook && $s['args'] === $args ) {
					return 0; // A matching pending action already exists.
				}
			}
		}
		self::$scheduled[] = array(
			'hook' => $hook,
			'args' => $args,
			'when' => (int) $when,
		);
		return count( self::$scheduled );
	}

	/**
	 * Register an order so wc_get_order()/wc_get_orders() can find it.
	 *
	 * @param WC_Order $order Order.
	 * @return WC_Order
	 */
	public static function add_order( WC_Order $order ) {
		self::$orders[ $order->get_id() ] = $order;
		return $order;
	}

	/**
	 * Install a gateway whose API client returns the given canned responses.
	 *
	 * @param mixed $get_orders_response Response for get_orders().
	 * @param mixed $cancel_response     Response for cancel_order().
	 * @return Ainepay_Fake_Api_Client
	 */
	public static function set_gateway( $get_orders_response = null, $cancel_response = null ) {
		$client                      = new Ainepay_Fake_Api_Client();
		$client->get_orders_response = $get_orders_response;
		$client->cancel_response     = $cancel_response;
		self::$gateway               = new Ainepay_Fake_Gateway( $client );
		return $client;
	}
}

/* --- WP/WC function stubs -------------------------------------------------- */

if ( ! function_exists( '__' ) ) {
	function __( $text, $domain = 'default' ) { // phpcs:ignore
		return $text;
	}
}

if ( ! function_exists( 'esc_html__' ) ) {
	function esc_html__( $text, $domain = 'default' ) { // phpcs:ignore
		return $text;
	}
}

if ( ! function_exists( 'wp_unslash' ) ) {
	function wp_unslash( $value ) {
		return is_string( $value ) ? stripslashes( $value ) : $value;
	}
}

if ( ! function_exists( 'get_current_blog_id' ) ) {
	function get_current_blog_id() {
		return Ainepay_Test_Env::$blog_id;
	}
}

if ( ! function_exists( 'sanitize_text_field' ) ) {
	function sanitize_text_field( $value ) {
		return is_string( $value ) ? trim( $value ) : $value;
	}
}

if ( ! function_exists( 'esc_url_raw' ) ) {
	function esc_url_raw( $url, $protocols = null ) {
		$url = filter_var( (string) $url, FILTER_SANITIZE_URL );
		if ( false === filter_var( $url, FILTER_VALIDATE_URL ) ) {
			return '';
		}
		$scheme = parse_url( $url, PHP_URL_SCHEME );
		if ( is_array( $protocols ) && ! in_array( strtolower( (string) $scheme ), $protocols, true ) ) {
			return '';
		}
		return $url;
	}
}

if ( ! function_exists( 'wp_parse_url' ) ) {
	function wp_parse_url( $url, $component = -1 ) {
		return parse_url( $url, $component );
	}
}

if ( ! function_exists( 'wp_safe_remote_request' ) ) {
	function wp_safe_remote_request( $url, $args = array() ) {
		Ainepay_Test_Env::$remote_requests[] = array( 'url' => (string) $url, 'args' => $args );
		return null === Ainepay_Test_Env::$remote_response
			? new WP_Error( 'test_transport', 'No test transport configured.' )
			: Ainepay_Test_Env::$remote_response;
	}
}

if ( ! function_exists( 'wp_remote_retrieve_response_code' ) ) {
	function wp_remote_retrieve_response_code( $response ) {
		return isset( $response['response']['code'] ) ? (int) $response['response']['code'] : 0;
	}
}

if ( ! function_exists( 'wp_remote_retrieve_body' ) ) {
	function wp_remote_retrieve_body( $response ) {
		return isset( $response['body'] ) ? (string) $response['body'] : '';
	}
}

if ( ! function_exists( 'absint' ) ) {
	function absint( $value ) {
		return abs( (int) $value );
	}
}

if ( ! function_exists( 'apply_filters' ) ) {
	function apply_filters( $name, $value ) {
		if ( array_key_exists( $name, Ainepay_Test_Env::$filter_overrides ) ) {
			return Ainepay_Test_Env::$filter_overrides[ $name ];
		}
		return $value;
	}
}

if ( ! function_exists( 'do_action' ) ) {
	function do_action( $name ) {
		$args                       = array_slice( func_get_args(), 1 );
		Ainepay_Test_Env::$actions[] = array(
			'hook' => $name,
			'args' => $args,
		);
	}
}

if ( ! function_exists( 'is_wp_error' ) ) {
	function is_wp_error( $thing ) {
		return $thing instanceof WP_Error;
	}
}

/**
 * Exception used by wp_send_json_* stubs to expose an otherwise terminating
 * AJAX response to unit tests.
 */
class Ainepay_Test_Json_Response extends RuntimeException {

	/** @var bool */
	public $success;

	/** @var mixed */
	public $data;

	/** @var int */
	public $status;

	public function __construct( $success, $data, $status ) {
		parent::__construct( 'JSON response' );
		$this->success = (bool) $success;
		$this->data    = $data;
		$this->status  = (int) $status;
	}
}

if ( ! function_exists( 'wp_verify_nonce' ) ) {
	function wp_verify_nonce( $nonce, $action ) {
		return 'valid-' . $action === (string) $nonce;
	}
}

if ( ! function_exists( 'check_ajax_referer' ) ) {
	function check_ajax_referer( $action, $query_arg = false, $stop = true ) {
		$key   = $query_arg ? (string) $query_arg : '_ajax_nonce';
		$nonce = isset( $_POST[ $key ] ) ? (string) $_POST[ $key ] : '';
		return wp_verify_nonce( $nonce, $action );
	}
}

if ( ! function_exists( 'wp_send_json_error' ) ) {
	function wp_send_json_error( $data = null, $status_code = null ) {
		throw new Ainepay_Test_Json_Response( false, $data, $status_code ?: 200 );
	}
}

if ( ! function_exists( 'wp_send_json_success' ) ) {
	function wp_send_json_success( $data = null, $status_code = null ) {
		throw new Ainepay_Test_Json_Response( true, $data, $status_code ?: 200 );
	}
}

if ( ! function_exists( 'wc_get_order' ) ) {
	function wc_get_order( $id ) {
		$id = (int) $id;
		return isset( Ainepay_Test_Env::$orders[ $id ] ) ? Ainepay_Test_Env::$orders[ $id ] : false;
	}
}

if ( ! function_exists( 'wc_get_orders' ) ) {
	function wc_get_orders( $args ) {
		Ainepay_Test_Env::$order_queries[] = $args;
		$found  = array();
		foreach ( Ainepay_Test_Env::$orders as $order ) {
			if ( isset( $args['meta_value'] )
				&& (string) $order->get_meta( isset( $args['meta_key'] ) ? $args['meta_key'] : '' ) !== (string) $args['meta_value'] ) {
				continue;
			}
			if ( isset( $args['status'] ) && ! $order->has_status( (array) $args['status'] ) ) {
				continue;
			}
			if ( isset( $args['payment_method'] ) && (string) $order->get_payment_method() !== (string) $args['payment_method'] ) {
				continue;
			}
			if ( isset( $args['meta_query'] ) && is_array( $args['meta_query'] ) ) {
				$matches = array();
				foreach ( $args['meta_query'] as $key => $clause ) {
					if ( 'relation' === $key || ! is_array( $clause ) ) {
						continue;
					}
					$meta_key = isset( $clause['key'] ) ? (string) $clause['key'] : '';
					$value    = $order->get_meta( $meta_key );
					$compare  = isset( $clause['compare'] ) ? strtoupper( (string) $clause['compare'] ) : '=';
					if ( 'NOT EXISTS' === $compare ) {
						$matches[] = '' === (string) $value;
					} elseif ( 'NOT IN' === $compare ) {
						$matches[] = ! in_array( (string) $value, array_map( 'strval', (array) $clause['value'] ), true );
					} elseif ( '!=' === $compare ) {
						$matches[] = '' !== (string) $value && (string) $value !== (string) $clause['value'];
					}
				}
				$relation = isset( $args['meta_query']['relation'] ) ? strtoupper( (string) $args['meta_query']['relation'] ) : 'AND';
				$matched  = 'OR' === $relation ? in_array( true, $matches, true ) : ! in_array( false, $matches, true );
				if ( ! $matched ) {
					continue;
				}
			}
			$found[] = $order;
		}
		$offset = isset( $args['offset'] ) ? max( 0, (int) $args['offset'] ) : 0;
		$limit  = isset( $args['limit'] ) ? (int) $args['limit'] : -1;
		if ( $offset > 0 ) {
			$found = array_slice( $found, $offset );
		}
		if ( $limit > 0 ) {
			$found = array_slice( $found, 0, $limit );
		}
		return $found;
	}
}

if ( ! function_exists( 'get_option' ) ) {
	function get_option( $key, $default = false ) {
		return array_key_exists( $key, Ainepay_Test_Env::$options ) ? Ainepay_Test_Env::$options[ $key ] : $default;
	}
}

if ( ! function_exists( 'update_option' ) ) {
	function update_option( $key, $value ) {
		Ainepay_Test_Env::$options[ $key ] = $value;
		return true;
	}
}

if ( ! function_exists( 'get_transient' ) ) {
	function get_transient( $key ) {
		return isset( Ainepay_Test_Env::$transients[ $key ] ) ? Ainepay_Test_Env::$transients[ $key ] : false;
	}
}

if ( ! function_exists( 'set_transient' ) ) {
	function set_transient( $key, $value, $ttl = 0 ) {
		Ainepay_Test_Env::$transients[ $key ] = $value;
		return true;
	}
}

if ( ! function_exists( 'delete_transient' ) ) {
	function delete_transient( $key ) {
		unset( Ainepay_Test_Env::$transients[ $key ] );
		return true;
	}
}

if ( ! function_exists( 'as_enqueue_async_action' ) ) {
	function as_enqueue_async_action( $hook, $args = array(), $group = '', $unique = false ) {
		return Ainepay_Test_Env::record_schedule( $hook, $args, 0, $unique );
	}
}

if ( ! function_exists( 'as_schedule_single_action' ) ) {
	function as_schedule_single_action( $when, $hook, $args = array(), $group = '', $unique = false ) {
		return Ainepay_Test_Env::record_schedule( $hook, $args, (int) $when, $unique );
	}
}

if ( ! function_exists( 'wp_schedule_single_event' ) ) {
	function wp_schedule_single_event( $when, $hook, $args = array() ) {
		if ( Ainepay_Test_Env::$schedule_fails ) {
			return false;
		}
		Ainepay_Test_Env::$scheduled[] = array(
			'hook' => $hook,
			'args' => $args,
			'when' => (int) $when,
		);
		return true;
	}
}

if ( ! function_exists( 'as_has_scheduled_action' ) ) {
	function as_has_scheduled_action( $hook, $args = null, $group = '' ) {
		foreach ( Ainepay_Test_Env::$scheduled as $s ) {
			if ( $s['hook'] === $hook && ( null === $args || $s['args'] === $args ) ) {
				return true;
			}
		}
		return false;
	}
}

if ( ! function_exists( 'wp_next_scheduled' ) ) {
	function wp_next_scheduled( $hook, $args = array() ) {
		foreach ( Ainepay_Test_Env::$scheduled as $s ) {
			if ( $s['hook'] === $hook && $s['args'] === $args ) {
				return $s['when'] > 0 ? $s['when'] : time();
			}
		}
		return false;
	}
}

if ( ! function_exists( 'WC' ) ) {
	function WC() { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName
		return new Ainepay_Fake_WC();
	}
}

if ( ! function_exists( 'wc_increase_stock_levels' ) ) {
	function wc_increase_stock_levels( $order ) {
		if ( is_object( $order ) && method_exists( $order, '__test_mark_stock_increased' ) ) {
			$order->__test_mark_stock_increased();
		}
	}
}

if ( ! function_exists( 'wc_get_template' ) ) {
	function wc_get_template( $template_name, $args = array(), $template_path = '', $default_path = '' ) {
		Ainepay_Test_Env::$last_template = $args;
	}
}

/* --- WP/WC class stubs ----------------------------------------------------- */

if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {

		/** @var string */
		private $code;

		/** @var string */
		private $message;

		/** @var mixed */
		private $data;

		public function __construct( $code = '', $message = '', $data = '' ) {
			$this->code    = $code;
			$this->message = $message;
			$this->data    = $data;
		}

		public function get_error_code() {
			return $this->code;
		}

		public function get_error_message() {
			return $this->message;
		}

		public function get_error_data() {
			return $this->data;
		}
	}
}

if ( ! class_exists( 'WC_Geolocation' ) ) {
	class WC_Geolocation {
		public static function get_ip_address() {
			return Ainepay_Test_Env::$client_ip;
		}
	}
}

/**
 * In-memory $wpdb honouring just GET_LOCK / RELEASE_LOCK.
 */
class Ainepay_Fake_WPDB {
	/** @var string WordPress table prefix used to scope advisory locks. */
	public $prefix = 'wp_';

	public function prepare( $query, ...$args ) {
		return $query . '|' . implode( ',', $args );
	}

	public function get_var( $query ) {
		if ( false !== strpos( $query, 'GET_LOCK' ) ) {
			return Ainepay_Test_Env::$lock_result;
		}
		return '1'; // RELEASE_LOCK and anything else.
	}
}

$GLOBALS['wpdb'] = new Ainepay_Fake_WPDB();

/**
 * WC() accessor stub exposing the payment-gateways chain gateway() walks.
 */
class Ainepay_Fake_WC {
	public function payment_gateways() {
		return new Ainepay_Fake_Payment_Gateways();
	}
}

/**
 * Returns the registered gateways keyed by id (empty when none installed).
 */
class Ainepay_Fake_Payment_Gateways {
	public function payment_gateways() {
		if ( null === Ainepay_Test_Env::$gateway ) {
			return array();
		}
		return array( Ainepay_Plugin::GATEWAY_ID => Ainepay_Test_Env::$gateway );
	}
}

/**
 * Fake gateway returning a canned API client.
 */
class Ainepay_Fake_Gateway {

	/** @var Ainepay_Fake_Api_Client */
	private $client;

	public function __construct( Ainepay_Fake_Api_Client $client ) {
		$this->client = $client;
	}

	public function get_api_client() {
		return $this->client;
	}
}

/**
 * Fake API client with canned responses and call counters.
 */
class Ainepay_Fake_Api_Client {

	/** @var mixed array|WP_Error|null */
	public $get_orders_response = null;

	/** @var mixed array|WP_Error|null */
	public $cancel_response = null;

	/** @var int */
	public $get_orders_calls = 0;

	/** @var int */
	public $cancel_calls = 0;

	public function get_orders( $ids ) {
		$this->get_orders_calls++;
		return $this->get_orders_response;
	}

	public function cancel_order( $oid ) {
		$this->cancel_calls++;
		return $this->cancel_response;
	}
}

/**
 * No-op logger.
 */
if ( ! class_exists( 'Ainepay_Logger' ) ) {
	class Ainepay_Logger {
		public static function debug( $msg, $ctx = array() ) {}
		public static function error( $msg, $ctx = array() ) {}
		public static function info( $msg, $ctx = array() ) {}
		public static function warning( $msg, $ctx = array() ) {}
	}
}

/**
 * Minimal Ainepay_Plugin stand-in: only the gateway id constant is needed.
 */
if ( ! class_exists( 'Ainepay_Plugin' ) ) {
	class Ainepay_Plugin {
		const GATEWAY_ID = 'ainepay';
	}
}

/* --- WooCommerce order test double ---------------------------------------- */

/**
 * In-memory WC_Order supporting the surface the state machine touches. Records
 * status transitions and notes so tests can assert observable behaviour.
 */
if ( ! class_exists( 'WC_Order' ) ) :
class WC_Order {

	/** @var int */
	private $id;

	/** @var string */
	private $status;

	/** @var string */
	private $payment_method;

	/** @var string */
	private $order_key;

	/** @var int */
	private $customer_id;

	/** @var array<string,mixed> */
	private $meta;

	/** @var array<int,Ainepay_Fake_Item> */
	private $items;

	/** @var string[] recorded notes */
	public $notes = array();

	/** @var string[] recorded status history (target statuses) */
	public $status_history = array();

	/** @var int */
	public $save_calls = 0;

	/** @var int */
	public $payment_complete_calls = 0;

	/** @var int wc_increase_stock_levels() invocations */
	public $stock_increase_calls = 0;

	/**
	 * @param array{
	 *   id?:int, status?:string, payment_method?:string, order_key?:string,
	 *   customer_id?:int, meta?:array<string,mixed>, items?:array<int,Ainepay_Fake_Item>
	 * } $args Construction args.
	 */
	public function __construct( array $args = array() ) {
		$this->id             = isset( $args['id'] ) ? (int) $args['id'] : 1;
		$this->status         = isset( $args['status'] ) ? (string) $args['status'] : 'on-hold';
		$this->payment_method = isset( $args['payment_method'] ) ? (string) $args['payment_method'] : Ainepay_Plugin::GATEWAY_ID;
		$this->order_key      = isset( $args['order_key'] ) ? (string) $args['order_key'] : 'wc_order_KEY123';
		$this->customer_id    = isset( $args['customer_id'] ) ? (int) $args['customer_id'] : 0;
		$this->meta           = isset( $args['meta'] ) ? (array) $args['meta'] : array();
		$this->items          = isset( $args['items'] ) ? (array) $args['items'] : array();
	}

	public function get_id() {
		return $this->id;
	}

	public function get_payment_method() {
		return $this->payment_method;
	}

	public function get_order_key() {
		return $this->order_key;
	}

	public function get_customer_id() {
		return $this->customer_id;
	}

	public function get_status() {
		return $this->status;
	}

	/**
	 * @param string|string[] $status Status or list to test.
	 * @return bool
	 */
	public function has_status( $status ) {
		return in_array( $this->status, (array) $status, true );
	}

	public function get_meta( $key ) {
		return array_key_exists( $key, $this->meta ) ? $this->meta[ $key ] : '';
	}

	public function update_meta_data( $key, $value ) {
		$this->meta[ $key ] = $value;
	}

	public function delete_meta_data( $key ) {
		unset( $this->meta[ $key ] );
	}

	public function add_order_note( $note ) {
		$this->notes[] = (string) $note;
	}

	public function update_status( $status, $note = '' ) {
		$this->status           = (string) $status;
		$this->status_history[] = (string) $status;
		if ( '' !== (string) $note ) {
			$this->notes[] = (string) $note;
		}
		return true;
	}

	public function payment_complete( $transaction_id = '' ) {
		$this->payment_complete_calls++;
		if ( ! in_array( $this->status, array( 'processing', 'completed' ), true ) ) {
			$this->update_status( 'processing' );
		}
		$this->meta['_paid'] = 'yes';
		return true;
	}

	public function get_items() {
		return $this->items;
	}

	public function save() {
		$this->save_calls++;
		return $this->id;
	}

	/**
	 * Test hook used by the wc_increase_stock_levels() stub.
	 *
	 * @return void
	 */
	public function __test_mark_stock_increased() {
		$this->stock_increase_calls++;
	}
}
endif;

/**
 * Fake order line item.
 */
class Ainepay_Fake_Item {

	/** @var Ainepay_Fake_Product|null */
	private $product;

	public function __construct( $product ) {
		$this->product = $product;
	}

	public function get_product() {
		return $this->product;
	}
}

/**
 * Fake product.
 */
class Ainepay_Fake_Product {

	/** @var bool */
	private $virtual;

	public function __construct( $virtual ) {
		$this->virtual = (bool) $virtual;
	}

	public function is_virtual() {
		return $this->virtual;
	}
}
