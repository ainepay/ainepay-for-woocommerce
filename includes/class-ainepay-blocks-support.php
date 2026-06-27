<?php
/**
 * WooCommerce Cart/Checkout Blocks integration for the AinePay gateway.
 *
 * @package AinePay\WooCommerce
 */

defined( 'ABSPATH' ) || exit;

use Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType;

/**
 * Registers the AinePay payment method with the Blocks checkout.
 */
final class Ainepay_Blocks_Support extends AbstractPaymentMethodType {

	/**
	 * Payment method name (matches the gateway id).
	 *
	 * @var string
	 */
	protected $name = 'ainepay';

	/**
	 * The gateway settings.
	 *
	 * @var array
	 */
	private $gateway_settings = array();

	/**
	 * Initialise from stored gateway settings.
	 *
	 * @return void
	 */
	public function initialize() {
		$this->gateway_settings = get_option( 'woocommerce_' . $this->name . '_settings', array() );
	}

	/**
	 * Whether the payment method is active.
	 *
	 * @return bool
	 */
	public function is_active() {
		$gateways = WC()->payment_gateways() ? WC()->payment_gateways()->payment_gateways() : array();
		if ( isset( $gateways[ $this->name ] ) && is_callable( array( $gateways[ $this->name ], 'is_available' ) ) ) {
			return $gateways[ $this->name ]->is_available();
		}
		return ! empty( $this->gateway_settings['enabled'] ) && 'yes' === $this->gateway_settings['enabled'];
	}

	/**
	 * Register and return the script handles for the Blocks integration.
	 *
	 * @return string[]
	 */
	public function get_payment_method_script_handles() {
		$handle = 'ainepay-blocks';
		wp_register_script(
			$handle,
			AINEPAY_WC_PLUGIN_URL . 'assets/js/blocks.js',
			array( 'wc-blocks-registry', 'wc-settings', 'wp-element', 'wp-html-entities', 'wp-i18n' ),
			AINEPAY_WC_VERSION,
			true
		);
		if ( function_exists( 'wp_set_script_translations' ) ) {
			wp_set_script_translations( $handle, 'ainepay-for-woocommerce' );
		}
		return array( $handle );
	}

	/**
	 * Data passed to the client integration.
	 *
	 * @return array
	 */
	public function get_payment_method_data() {
		$gateways = WC()->payment_gateways() ? WC()->payment_gateways()->payment_gateways() : array();
		$gateway  = isset( $gateways[ $this->name ] ) ? $gateways[ $this->name ] : null;

		$coins = array();
		if ( $gateway && is_callable( array( $gateway, 'get_available_coins' ) ) ) {
			foreach ( $gateway->get_available_coins() as $value => $label ) {
				$coins[] = array(
					'value' => $value,
					'label' => $label,
				);
			}
		}

		return array(
			'title'       => isset( $this->gateway_settings['title'] ) ? $this->gateway_settings['title'] : __( 'AinePay', 'ainepay-for-woocommerce' ),
			'description' => isset( $this->gateway_settings['description'] ) ? $this->gateway_settings['description'] : '',
			'coins'       => $coins,
			'supports'    => $gateway ? array_values( $gateway->supports ) : array( 'products' ),
		);
	}
}
