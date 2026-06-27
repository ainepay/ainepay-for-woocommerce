<?php
/**
 * Bootstrap the plugin inside WooCommerce's real PHPUnit environment.
 *
 * Required environment:
 * - WC_TESTS_DIR: WooCommerce source checkout (plugins/woocommerce).
 * - WP_TESTS_DIR: WordPress PHPUnit library, if not at /tmp/wordpress-tests-lib.
 *
 * @package AinePay\WooCommerce
 */

$wc_tests_dir = getenv( 'WC_TESTS_DIR' );
if ( ! $wc_tests_dir || ! is_readable( $wc_tests_dir . '/tests/legacy/bootstrap.php' ) ) {
	fwrite( STDERR, "WC_TESTS_DIR must point to a WooCommerce source checkout.\n" );
	exit( 1 );
}

$wp_tests_dir = getenv( 'WP_TESTS_DIR' );
if ( ! $wp_tests_dir ) {
	$wp_tests_dir = sys_get_temp_dir() . '/wordpress-tests-lib';
}
if ( ! is_readable( $wp_tests_dir . '/includes/functions.php' ) ) {
	fwrite( STDERR, "WP_TESTS_DIR must point to the WordPress PHPUnit library.\n" );
	exit( 1 );
}

require_once $wp_tests_dir . '/includes/functions.php';

$ainepay_plugin_file = dirname( __DIR__ ) . '/ainepay-for-woocommerce.php';
tests_add_filter(
	'muplugins_loaded',
	function () use ( $ainepay_plugin_file ) {
		require_once $ainepay_plugin_file;
	},
	20
);

require_once $wc_tests_dir . '/tests/legacy/bootstrap.php';
