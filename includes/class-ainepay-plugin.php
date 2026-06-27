<?php
/**
 * Plugin bootstrap: dependency checks and gateway registration.
 *
 * @package AinePay\WooCommerce
 */

defined( 'ABSPATH' ) || exit;

/**
 * Singleton bootstrap for AinePay for WooCommerce.
 */
class Ainepay_Plugin {

	const GATEWAY_ID = 'ainepay';

	/**
	 * Minimum supported WooCommerce version.
	 */
	const MIN_WC_VERSION = '7.0';

	/**
	 * Singleton instance.
	 *
	 * @var Ainepay_Plugin|null
	 */
	private static $instance = null;

	/**
	 * Whether init() has already run.
	 *
	 * @var bool
	 */
	private $booted = false;

	/**
	 * Get the singleton instance.
	 *
	 * @return Ainepay_Plugin
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Private constructor (use instance()).
	 */
	private function __construct() {}

	/**
	 * Bootstrap the plugin once WordPress has loaded all plugins.
	 *
	 * @return void
	 */
	public function init() {
		if ( $this->booted ) {
			return;
		}
		$this->booted = true;

		load_plugin_textdomain(
			'ainepay-for-woocommerce',
			false,
			dirname( AINEPAY_WC_PLUGIN_BASENAME ) . '/languages'
		);

		if ( ! $this->woocommerce_ready() ) {
			add_action( 'admin_notices', array( $this, 'render_missing_woocommerce_notice' ) );
			return;
		}

		add_filter( 'woocommerce_payment_gateways', array( $this, 'register_gateway' ) );
		add_filter(
			'plugin_action_links_' . AINEPAY_WC_PLUGIN_BASENAME,
			array( $this, 'add_settings_link' )
		);

		// Customer-facing payment instructions + status polling.
		Ainepay_Payment_Display::init();

		// Webhook endpoint (/ainepay/notify): connectivity test + notifications.
		Ainepay_Webhook_Handler::init();

		// Polling fallback for missed webhooks.
		Ainepay_Poller::init();

		// Admin order screen: show AinePay payment details + cancel button.
		Ainepay_Admin_Order::init();

		// Cancellation: safety net when an AinePay order is moved to cancelled by
		// any path, plus the persistent cancel-sync retry worker. Together they
		// keep WC and AinePay eventually consistent without ever marking a paid
		// order cancelled.
		add_action( 'woocommerce_order_status_cancelled', array( 'Ainepay_Order_Sync', 'on_wc_cancelled' ), 10, 1 );
		add_action( Ainepay_Order_Sync::CANCEL_SYNC_HOOK, array( 'Ainepay_Order_Sync', 'handle_cancel_sync' ), 10, 1 );

		// Manual two-step refund closure (full refunds only): the gateway has no
		// process_refund(), so a merchant refunds in WooCommerce first and then in
		// the AinePay dashboard. Track the WC-first full refund and verify out of
		// band that AinePay reaches REFUND, alerting if it never does.
		add_action( 'woocommerce_order_fully_refunded', array( 'Ainepay_Order_Sync', 'on_wc_fully_refunded' ), 10, 2 );
		// Partial refunds are unsupported (AinePay is full-refund only): warn the
		// merchant via a one-time order note instead of starting a verify chain that
		// could never converge and would falsely alert as "refund stuck".
		add_action( 'woocommerce_order_partially_refunded', array( 'Ainepay_Order_Sync', 'on_wc_partially_refunded' ), 10, 2 );
		add_action( Ainepay_Order_Sync::REFUND_VERIFY_HOOK, array( 'Ainepay_Order_Sync', 'verify_refund' ), 10, 1 );

		// Governing invariant: an AinePay order may only be in a success
		// state when AinePay has confirmed PAID. Guards against admin/REST/3rd-party
		// promotions that bypass the gateway. Early priority so it runs before
		// fulfilment side effects trust the new status.
		add_action( 'woocommerce_order_status_changed', array( 'Ainepay_Order_Sync', 'guard_paid_invariant' ), 5, 4 );
		// Async worker that authoritatively verifies an unbacked success promotion
		// (kept off the save path so it never blocks the admin or bricks a manual
		// override during a backend outage).
		add_action( Ainepay_Order_Sync::VERIFY_PAID_HOOK, array( 'Ainepay_Order_Sync', 'verify_paid_invariant' ), 10, 1 );

		// Fail-closed gate of the customer-facing fulfilment side effects that WC
		// fires on the success transition BEFORE guard_paid_invariant can revert an
		// unbacked promotion (status_{to}/{from}_to_{to} run before
		// status_changed): never email the customer "order received/complete" nor
		// grant downloads for an AinePay order until AinePay confirms PAID.
		add_filter( 'woocommerce_email_enabled_customer_processing_order', array( 'Ainepay_Order_Sync', 'gate_unbacked_email' ), 10, 2 );
		add_filter( 'woocommerce_email_enabled_customer_completed_order', array( 'Ainepay_Order_Sync', 'gate_unbacked_email' ), 10, 2 );
		add_filter( 'woocommerce_order_is_download_permitted', array( 'Ainepay_Order_Sync', 'gate_unbacked_download' ), 10, 2 );

		// Native WC admin/bulk "Cancelled" marks WC cancelled (and restocks) before
		// the backend is asked, unlike the cancel-first button. Hold the stock
		// restore for an AinePay order whose cancel the backend has not yet confirmed
		// CANCEL, so a really-settling order cannot open an oversell window before the
		// async reconcile repairs it. Released by release_held_stock() on confirmation.
		add_filter( 'woocommerce_can_restore_order_stock', array( 'Ainepay_Order_Sync', 'gate_premature_restock' ), 10, 2 );

		// "Test connection" AJAX handler. Registered here (not in the gateway
		// constructor) because admin-ajax.php does not instantiate gateways.
		add_action( 'wp_ajax_ainepay_test_connection', array( $this, 'ajax_test_connection' ) );
	}

	/**
	 * Resolve the AinePay gateway instance from the WooCommerce registry.
	 *
	 * @return Ainepay_Gateway|null
	 */
	public static function get_gateway() {
		if ( ! function_exists( 'WC' ) || ! WC()->payment_gateways() ) {
			return null;
		}
		$gateways = WC()->payment_gateways()->payment_gateways();
		return isset( $gateways[ self::GATEWAY_ID ] ) ? $gateways[ self::GATEWAY_ID ] : null;
	}

	/**
	 * AJAX entry point for the settings "Test connection" button. Delegates to
	 * the gateway, instantiating it via the registry if needed.
	 *
	 * @return void
	 */
	public function ajax_test_connection() {
		$gateway = self::get_gateway();
		if ( ! $gateway ) {
			wp_send_json_error( array( 'message' => __( 'AinePay gateway is not available.', 'ainepay-for-woocommerce' ) ), 500 );
		}
		$gateway->ajax_test_connection();
	}

	/**
	 * Whether WooCommerce is active and new enough.
	 *
	 * @return bool
	 */
	private function woocommerce_ready() {
		if ( ! class_exists( 'WooCommerce' ) ) {
			return false;
		}
		if ( defined( 'WC_VERSION' ) && version_compare( WC_VERSION, self::MIN_WC_VERSION, '<' ) ) {
			return false;
		}
		return true;
	}

	/**
	 * Register the AinePay gateway with WooCommerce.
	 *
	 * @param array $gateways Registered gateway class names.
	 * @return array
	 */
	public function register_gateway( $gateways ) {
		$gateways[] = 'Ainepay_Gateway';
		return $gateways;
	}

	/**
	 * Add a "Settings" link on the Plugins screen pointing to the gateway config.
	 *
	 * @param array $links Existing action links.
	 * @return array
	 */
	public function add_settings_link( $links ) {
		$url = admin_url( 'admin.php?page=wc-settings&tab=checkout&section=' . self::GATEWAY_ID );
		$settings_link = '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Settings', 'ainepay-for-woocommerce' ) . '</a>';
		array_unshift( $links, $settings_link );
		return $links;
	}

	/**
	 * Admin notice shown when WooCommerce is missing or too old.
	 *
	 * @return void
	 */
	public function render_missing_woocommerce_notice() {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return;
		}
		$message = sprintf(
			/* translators: %s: minimum WooCommerce version. */
			esc_html__( 'AinePay for WooCommerce requires WooCommerce %s or later to be installed and active.', 'ainepay-for-woocommerce' ),
			esc_html( self::MIN_WC_VERSION )
		);
		printf( '<div class="notice notice-error"><p>%s</p></div>', wp_kses_post( $message ) );
	}
}
