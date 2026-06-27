<?php
/**
 * PHPUnit bootstrap. Defines the ABSPATH guard so plugin classes can be loaded
 * in isolation (no full WordPress install needed for the signer unit tests).
 *
 * @package AinePay\WooCommerce
 */

define( 'ABSPATH', __DIR__ . '/' );

require_once dirname( __DIR__ ) . '/includes/client/class-ainepay-signer.php';
