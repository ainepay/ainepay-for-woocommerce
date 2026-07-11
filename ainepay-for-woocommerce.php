<?php
/**
 * Plugin Name:       AinePay for WooCommerce
 * Plugin URI:        https://github.com/ainepay/ainepay-for-woocommerce
 * Description:       Accept non-custodial USDT/USDC stablecoin payments in WooCommerce through AinePay, with local payment-address verification.
 * Version:           0.1.0
 * Requires at least: 6.2
 * Requires PHP:      7.4
 * Author:            AinePay
 * Author URI:        https://ainepay.com
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       ainepay-for-woocommerce
 * Domain Path:       /languages
 * Requires Plugins:  woocommerce
 *
 * WC requires at least: 7.0
 * WC tested up to:      9.4
 *
 * @package AinePay\WooCommerce
 */

defined( 'ABSPATH' ) || exit;

if ( defined( 'AINEPAY_WC_VERSION' ) ) {
	return;
}

define( 'AINEPAY_WC_VERSION', '0.1.0' );
define( 'AINEPAY_WC_PLUGIN_FILE', __FILE__ );
define( 'AINEPAY_WC_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'AINEPAY_WC_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'AINEPAY_WC_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );

/**
 * Minimal class autoloader for the Ainepay_* classes living under includes/.
 *
 * Maps Ainepay_Foo_Bar -> includes/class-ainepay-foo-bar.php (and admin/ subtree).
 */
spl_autoload_register(
	function ( $class ) {
		if ( 0 !== strpos( $class, 'Ainepay_' ) ) {
			return;
		}
		$file  = 'class-' . str_replace( '_', '-', strtolower( $class ) ) . '.php';
		$paths = array(
			AINEPAY_WC_PLUGIN_DIR . 'includes/' . $file,
			AINEPAY_WC_PLUGIN_DIR . 'includes/client/' . $file,
			AINEPAY_WC_PLUGIN_DIR . 'includes/admin/' . $file,
		);
		foreach ( $paths as $path ) {
			if ( is_readable( $path ) ) {
				require_once $path;
				return;
			}
		}
	}
);

// Composer dependencies (kornrunner/keccak etc.), shipped inside the release zip.
if ( is_readable( AINEPAY_WC_PLUGIN_DIR . 'vendor/autoload.php' ) ) {
	require_once AINEPAY_WC_PLUGIN_DIR . 'vendor/autoload.php';
}

/**
 * Declare compatibility with WooCommerce High-Performance Order Storage (HPOS)
 * and the Cart/Checkout Blocks before WooCommerce initialises.
 */
add_action(
	'before_woocommerce_init',
	function () {
		if ( ! class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			return;
		}
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
			'custom_order_tables',
			AINEPAY_WC_PLUGIN_FILE,
			true
		);
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
			'cart_checkout_blocks',
			AINEPAY_WC_PLUGIN_FILE,
			true
		);
	}
);

// Boot the plugin once all plugins are loaded (so we can detect WooCommerce).
add_action(
	'plugins_loaded',
	function () {
		Ainepay_Plugin::instance()->init();
	}
);

// Register the Cart/Checkout Blocks payment method integration.
add_action(
	'woocommerce_blocks_payment_method_type_registration',
	function ( $registry ) {
		if ( class_exists( '\Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType' ) ) {
			$registry->register( new Ainepay_Blocks_Support() );
		}
	}
);

/**
 * Activation hook: flush rewrite rules so the /ainepay/notify endpoint resolves.
 * The endpoint itself is registered lazily by the webhook handler (added in M4);
 * flushing here keeps activation cheap and idempotent.
 */
register_activation_hook(
	__FILE__,
	function () {
		// Register the rewrite rule before flushing so /ainepay/notify resolves.
		if ( class_exists( 'Ainepay_Webhook_Handler' ) ) {
			( new Ainepay_Webhook_Handler() )->add_rewrite();
		}
		flush_rewrite_rules();
	}
);

register_deactivation_hook(
	__FILE__,
	function () {
		if ( class_exists( 'Ainepay_Poller' ) ) {
			Ainepay_Poller::unschedule();
		}
		flush_rewrite_rules();
	}
);
