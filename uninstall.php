<?php
/**
 * Uninstall routine for AinePay for WooCommerce.
 *
 * Removes plugin options on deletion. Order meta is intentionally left in place
 * so historical orders remain intact; set the filter below to also purge it.
 *
 * @package AinePay\WooCommerce
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

// Gateway settings, the cached supported-coins list, and the per-site userId
// namespace token.
delete_option( 'woocommerce_ainepay_settings' );
delete_option( 'ainepay_supported_coins' );
delete_option( 'ainepay_site_namespace' );

/**
 * Allow opting in to also delete per-order AinePay meta on uninstall.
 * Defaults to false to preserve order history.
 */
if ( apply_filters( 'ainepay_delete_order_meta_on_uninstall', false ) ) {
	global $wpdb;

	// Legacy post meta storage.
	$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		"DELETE FROM {$wpdb->postmeta} WHERE meta_key LIKE '\_ainepay\_%'"
	);

	// HPOS orders meta table, when present.
	$hpos_meta = $wpdb->prefix . 'wc_orders_meta';
	$exists    = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $hpos_meta ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	if ( $exists === $hpos_meta ) {
		$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			"DELETE FROM {$hpos_meta} WHERE meta_key LIKE '\_ainepay\_%'"
		);
	}
}
