<?php
/**
 * AinePay payment gateway.
 *
 * @package AinePay\WooCommerce
 */

defined( 'ABSPATH' ) || exit;

/**
 * WooCommerce payment gateway for AinePay non-custodial stablecoin payments.
 *
 * Handles registration, settings, checkout payment creation, address
 * validation, webhooks, and polling.
 */
class Ainepay_Gateway extends WC_Payment_Gateway {

	const DEFAULT_API_BASE = 'https://api.ainepay.com';

	// Ethereum mainnet contract defaults for non-custodial CREATE2 address derivation.
	const DEFAULT_FACTORY  = '0x06559ab75cd906e2ecd9c3e91459eea558e2ec1b';
	const DEFAULT_IMPL     = '0x42eb2a5b755551d5f386f2c79807abd438341557';
	const DEFAULT_VERSION  = '1';
	const DEFAULT_CHAIN_ID = '1';

	/**
	 * Placeholder rendered in password fields instead of the stored secret,
	 * so secrets are never echoed back to the browser in clear text.
	 */
	const SECRET_MASK = '••••••••';

	/**
	 * Settings keys that hold secrets and must be masked on render / preserved on save.
	 *
	 * @var string[]
	 */
	private $secret_fields = array( 'api_secret', 'notify_secret' );

	/**
	 * Constructor: wire up the gateway metadata and settings.
	 */
	public function __construct() {
		$this->id                 = Ainepay_Plugin::GATEWAY_ID;
		$this->method_title       = __( 'AinePay', 'ainepay-for-woocommerce' );
		$this->method_description = __( 'Accept non-custodial USDT/USDC stablecoin payments via AinePay. Customers pay to a deterministically derived address that this plugin verifies locally before showing it.', 'ainepay-for-woocommerce' );
		$this->has_fields         = true;
		$this->supports           = array( 'products' );

		// AinePay icon: only set when the asset actually exists, to avoid a
		// broken image at checkout. Themes/merchants can override via the filter.
		$icon_rel   = 'assets/images/ainepay-mark.svg';
		$icon_url   = file_exists( AINEPAY_WC_PLUGIN_DIR . $icon_rel ) ? AINEPAY_WC_PLUGIN_URL . $icon_rel : '';
		$this->icon = apply_filters( 'ainepay_gateway_icon', $icon_url );

		$this->init_form_fields();
		$this->init_settings();

		$this->title       = $this->get_option( 'title' );
		$this->description = $this->get_option( 'description' );
		$this->enabled     = $this->get_option( 'enabled' );

		Ainepay_Logger::set_enabled( 'yes' === $this->get_option( 'debug' ) );

		add_action(
			'woocommerce_update_options_payment_gateways_' . $this->id,
			array( $this, 'process_admin_options' )
		);
		// After settings persist, refresh the supported-coin cache from AinePay.
		add_action(
			'woocommerce_update_options_payment_gateways_' . $this->id,
			array( $this, 'refresh_supported_coins' ),
			20
		);
		// Note: the "Test connection" AJAX action is registered in
		// Ainepay_Plugin::init() (not here) because admin-ajax.php is not
		// guaranteed to instantiate payment gateways.
	}

	/**
	 * Render the gateway icon with a bounded size.
	 *
	 * WooCommerce's default get_icon() emits a plain <img> with no size, so the
	 * icon's rendered dimensions are left entirely to theme CSS — which on many
	 * themes blows the SVG up to its fallback box. We cap the height inline and
	 * let the width scale, so the horizontal AinePay logo stays a consistent
	 * line-height-sized mark everywhere.
	 *
	 * @return string
	 */
	public function get_icon() {
		if ( empty( $this->icon ) ) {
			return apply_filters( 'woocommerce_gateway_icon', '', $this->id );
		}
		$icon = sprintf(
			'<img src="%s" alt="%s" style="max-height:24px;width:auto;display:inline-block;vertical-align:middle" />',
			esc_url( $this->icon ),
			esc_attr( $this->get_title() )
		);
		return apply_filters( 'woocommerce_gateway_icon', $icon, $this->id );
	}

	/**
	 * Render the settings screen with an extra "Test connection" button.
	 *
	 * @return void
	 */
	public function admin_options() {
		parent::admin_options();

		// Enqueued at render time (footer script) so it only loads on this
		// gateway's own settings section.
		wp_enqueue_script(
			'ainepay-admin-settings',
			AINEPAY_WC_PLUGIN_URL . 'assets/js/admin-settings.js',
			array(),
			AINEPAY_WC_VERSION,
			true
		);

		$nonce = wp_create_nonce( 'ainepay_test_connection' );
		?>
		<h3 class="wc-settings-sub-title"><?php esc_html_e( 'Diagnostics', 'ainepay-for-woocommerce' ); ?></h3>
		<p>
			<button type="button" class="button" id="ainepay-test-connection"
				data-nonce="<?php echo esc_attr( $nonce ); ?>">
				<?php esc_html_e( 'Test connection &amp; configuration', 'ainepay-for-woocommerce' ); ?>
			</button>
			<span id="ainepay-test-result" style="margin-left:8px"></span>
		</p>
		<?php
	}

	/**
	 * AJAX: test API reachability and verify configuration end-to-end by placing
	 * a probe order and comparing the returned address to the local derivation.
	 *
	 * Mere local CREATE2 cannot catch a wrong merchantId/factory/impl/version/
	 * chainId; only comparing against a real returned address does.
	 *
	 * @return void
	 */
	public function ajax_test_connection() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'ainepay-for-woocommerce' ) ), 403 );
		}
		check_ajax_referer( 'ainepay_test_connection', 'nonce' );

		// 1. /pay/info reachability (also validates API base URL).
		$info = $this->get_api_client()->get_pay_info();
		if ( is_wp_error( $info ) ) {
			wp_send_json_error( array( 'message' => sprintf( /* translators: %s: error */ __( 'API unreachable: %s', 'ainepay-for-woocommerce' ), $info->get_error_message() ) ) );
		}

		$coins = isset( $info['supported'] ) && is_array( $info['supported'] ) ? $info['supported'] : array();
		if ( empty( $coins ) ) {
			wp_send_json_error( array( 'message' => __( 'Connected, but no supported coins were returned.', 'ainepay-for-woocommerce' ) ) );
		}

		// 2. Keccak availability (address verification depends on it).
		if ( ! Ainepay_Address_Validator::is_available() ) {
			wp_send_json_error( array( 'message' => __( 'Connected, but the keccak dependency is missing — run composer install.', 'ainepay-for-woocommerce' ) ) );
		}

		// 3. Probe order: place a *fresh* order each time and compare the
		// returned address to the local derivation. The orderId must be unique
		// per call — a stable id would, after the first probe expires/pays,
		// return an order whose address is null (backend returns address only
		// while status == INIT), making the test fail forever. The userId stays
		// deterministic so the derivation is reproducible.
		$merchant_id    = (string) $this->get_option( 'merchant_id' );
		$probe_user_id  = 'probe_' . substr( hash( 'sha256', home_url( '/' ) . '|ainepay-probe' ), 0, 12 );
		$probe_order_id = 'wc_probe_' . substr( md5( uniqid( 'ainepay', true ) ), 0, 16 );
		$first          = $coins[0];

		$result = $this->get_api_client()->create_pay_order(
			array(
				'orderId'        => $probe_order_id,
				'userId'         => $probe_user_id,
				'coin'           => isset( $first['coin'] ) ? $first['coin'] : '',
				'chain'          => isset( $first['chain'] ) ? $first['chain'] : '',
				'qty'            => '1.00',
				'collectAddress' => (string) $this->get_option( 'collect_address' ),
			)
		);
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => sprintf( /* translators: %s: error */ __( 'Probe order failed: %s', 'ainepay-for-woocommerce' ), $result->get_error_message() ) ) );
		}

		$address = isset( $result['address']['address'] ) ? (string) $result['address']['address'] : '';
		if ( '' === $address ) {
			wp_send_json_error( array( 'message' => __( 'Probe order returned no address.', 'ainepay-for-woocommerce' ) ) );
		}

		$verified = Ainepay_Address_Validator::verify(
			$address,
			(string) $this->get_option( 'forwarder_factory' ),
			(string) $this->get_option( 'forwarder_impl' ),
			$merchant_id,
			$probe_user_id,
			(string) $this->get_option( 'collect_address' ),
			(int) $this->get_option( 'forwarder_version', self::DEFAULT_VERSION ),
			(int) $this->get_option( 'chain_id', self::DEFAULT_CHAIN_ID )
		);

		if ( ! $verified ) {
			wp_send_json_error( array( 'message' => __( 'Connected, but address verification FAILED. Check Merchant ID, Collection Address and contract settings.', 'ainepay-for-woocommerce' ) ) );
		}

		wp_send_json_success( array( 'message' => __( 'Success: API reachable and address verification passed.', 'ainepay-for-woocommerce' ) ) );
	}

	/**
	 * Build an API client from the current settings.
	 *
	 * @return Ainepay_Api_Client
	 */
	public function get_api_client() {
		return new Ainepay_Api_Client(
			$this->get_option( 'api_base_url', self::DEFAULT_API_BASE ),
			$this->get_option( 'api_key' ),
			$this->get_option( 'api_secret' )
		);
	}

	/**
	 * Fetch the supported coins from /api/merchant/pay/info and cache them in the
	 * ainepay_supported_coins option (read by the enabled_coins multiselect).
	 *
	 * Runs on settings save; failures surface as an admin notice but never block
	 * saving the rest of the configuration.
	 *
	 * @return void
	 */
	public function refresh_supported_coins() {
		$result = $this->get_api_client()->get_pay_info();

		if ( is_wp_error( $result ) ) {
			Ainepay_Logger::error( 'Failed to refresh supported coins: ' . $result->get_error_message() );
			WC_Admin_Settings::add_error(
				sprintf(
					/* translators: %s: error message. */
					__( 'AinePay: could not load supported coins (%s). Saved other settings.', 'ainepay-for-woocommerce' ),
					$result->get_error_message()
				),
				true
			);
			return;
		}

		$supported = isset( $result['supported'] ) && is_array( $result['supported'] ) ? $result['supported'] : array();
		$clean     = array();
		foreach ( $supported as $entry ) {
			if ( empty( $entry['coin'] ) || empty( $entry['chain'] ) ) {
				continue;
			}
			$clean[] = array(
				'coin'      => sanitize_text_field( $entry['coin'] ),
				'chain'     => sanitize_text_field( $entry['chain'] ),
				'contract'  => isset( $entry['contract'] ) ? sanitize_text_field( $entry['contract'] ) : '',
				'chainName' => isset( $entry['chainName'] ) ? sanitize_text_field( $entry['chainName'] ) : '',
			);
		}

		update_option( 'ainepay_supported_coins', $clean, false );
		Ainepay_Logger::debug( 'Supported coins refreshed', array( 'count' => count( $clean ) ) );
	}

	/**
	 * Define the admin settings form.
	 *
	 * @return void
	 */
	public function init_form_fields() {
		$this->form_fields = array(

			// --- General ---------------------------------------------------
			'enabled'           => array(
				'title'   => __( 'Enable/Disable', 'ainepay-for-woocommerce' ),
				'type'    => 'checkbox',
				'label'   => __( 'Enable AinePay', 'ainepay-for-woocommerce' ),
				'default' => 'no',
			),
			'title'             => array(
				'title'       => __( 'Title', 'ainepay-for-woocommerce' ),
				'type'        => 'text',
				'description' => __( 'Payment method title shown to customers at checkout.', 'ainepay-for-woocommerce' ),
				'default'     => __( 'Pay with USDT / USDC (AinePay)', 'ainepay-for-woocommerce' ),
				'desc_tip'    => true,
			),
			'description'       => array(
				'title'       => __( 'Description', 'ainepay-for-woocommerce' ),
				'type'        => 'textarea',
				'description' => __( 'Payment method description shown to customers at checkout.', 'ainepay-for-woocommerce' ),
				'default'     => __( 'Pay with USDT or USDC stablecoins. You will be shown a payment address after placing the order.', 'ainepay-for-woocommerce' ),
				'desc_tip'    => true,
			),

			// --- API connection -------------------------------------------
			'api_section'       => array(
				'title' => __( 'API connection', 'ainepay-for-woocommerce' ),
				'type'  => 'title',
			),
			'api_base_url'      => array(
				'title'       => __( 'API Base URL', 'ainepay-for-woocommerce' ),
				'type'        => 'text',
				'description' => __( 'Trusted AinePay HTTPS endpoint. Production uses api.ainepay.com; additional test hosts require an explicit developer allowlist filter.', 'ainepay-for-woocommerce' ),
				'default'     => self::DEFAULT_API_BASE,
				'desc_tip'    => true,
			),
			'api_key'           => array(
				'title'       => __( 'API Key', 'ainepay-for-woocommerce' ),
				'type'        => 'text',
				/* translators: do not hard-validate the prefix; it may evolve. */
				'description' => __( 'Your AinePay API Key (sent as the x-api-key header). Usually starts with an environment prefix such as "live_" or "test_".', 'ainepay-for-woocommerce' ),
				'default'     => '',
				'desc_tip'    => true,
			),
			'api_secret'        => array(
				'title'       => __( 'API Secret', 'ainepay-for-woocommerce' ),
				'type'        => 'password',
				'description' => __( 'Your AinePay API signing secret (used only to sign requests locally; never sent). Usually starts with "sv_".', 'ainepay-for-woocommerce' ),
				'default'     => '',
				'desc_tip'    => true,
			),
			'notify_secret'     => array(
				'title'       => __( 'Notify Secret', 'ainepay-for-woocommerce' ),
				'type'        => 'password',
				'description' => __( 'Your AinePay notification secret (used to verify webhook signatures). Different from the API Secret. Usually starts with "sv_".', 'ainepay-for-woocommerce' ),
				'default'     => '',
				'desc_tip'    => true,
			),
			'merchant_id'       => array(
				'title'       => __( 'Merchant ID', 'ainepay-for-woocommerce' ),
				'type'        => 'text',
				'description' => __( 'Your AinePay Merchant ID, found in the AinePay dashboard. Used for address verification and user identification.', 'ainepay-for-woocommerce' ),
				'default'     => '',
				'desc_tip'    => true,
			),

			// --- Collection & contracts -----------------------------------
			'address_section'   => array(
				'title'       => __( 'Collection address & contracts', 'ainepay-for-woocommerce' ),
				'type'        => 'title',
				'description' => __( 'Used to verify that the payment address returned by AinePay is deterministically derived from your collection address. Get these values from the AinePay dashboard.', 'ainepay-for-woocommerce' ),
			),
			'collect_address'   => array(
				'title'       => __( 'Collection Address', 'ainepay-for-woocommerce' ),
				'type'        => 'text',
				'description' => __( 'Your registered (ACTIVE) collection address on AinePay. Funds settle here.', 'ainepay-for-woocommerce' ),
				'default'     => '',
				'desc_tip'    => true,
			),
			'forwarder_factory' => array(
				'title'       => __( 'Forwarder Factory Address', 'ainepay-for-woocommerce' ),
				'type'        => 'text',
				'description' => __( 'CREATE2 factory contract address (Ethereum mainnet default pre-filled).', 'ainepay-for-woocommerce' ),
				'default'     => self::DEFAULT_FACTORY,
				'desc_tip'    => true,
			),
			'forwarder_impl'    => array(
				'title'       => __( 'Forwarder Implementation Address', 'ainepay-for-woocommerce' ),
				'type'        => 'text',
				'description' => __( 'Forwarder implementation (proxy template) contract address (Ethereum mainnet default pre-filled).', 'ainepay-for-woocommerce' ),
				'default'     => self::DEFAULT_IMPL,
				'desc_tip'    => true,
			),
			'forwarder_version' => array(
				'title'       => __( 'Forwarder Version', 'ainepay-for-woocommerce' ),
				'type'        => 'number',
				'description' => __( 'Forwarder version used in address derivation.', 'ainepay-for-woocommerce' ),
				'default'     => self::DEFAULT_VERSION,
				'desc_tip'    => true,
			),
			'chain_id'          => array(
				'title'       => __( 'Chain ID', 'ainepay-for-woocommerce' ),
				'type'        => 'number',
				'description' => __( 'EVM chain ID used in address derivation (Ethereum mainnet = 1).', 'ainepay-for-woocommerce' ),
				'default'     => self::DEFAULT_CHAIN_ID,
				'desc_tip'    => true,
			),

			// --- Coins -----------------------------------------------------
			'coins_section'     => array(
				'title'       => __( 'Supported coins', 'ainepay-for-woocommerce' ),
				'type'        => 'title',
				'description' => __( 'Coins are loaded from AinePay (/api/merchant/pay/info) when you save. Customers can choose from enabled coin/chain pairs at checkout.', 'ainepay-for-woocommerce' ),
			),
			'enabled_coins'     => array(
				'title'             => __( 'Enabled coins', 'ainepay-for-woocommerce' ),
				'type'              => 'multiselect',
				'class'             => 'wc-enhanced-select',
				'description'       => __( 'Select which coin/chain combinations to offer. Populated after the first successful connection.', 'ainepay-for-woocommerce' ),
				'default'           => array(),
				'options'           => $this->get_cached_coin_options(),
				'desc_tip'          => true,
				'custom_attributes' => array(),
			),

			// --- Advanced --------------------------------------------------
			'advanced_section'  => array(
				'title' => __( 'Advanced', 'ainepay-for-woocommerce' ),
				'type'  => 'title',
			),
			'poll_interval'     => array(
				'title'       => __( 'Order polling interval (seconds)', 'ainepay-for-woocommerce' ),
				'type'        => 'number',
				'description' => __( 'How often to actively query AinePay for pending order status. Webhooks only speed up updates; polling provides final consistency.', 'ainepay-for-woocommerce' ),
				'default'     => '60',
				'desc_tip'    => true,
			),
			'debug'             => array(
				'title'       => __( 'Debug logging', 'ainepay-for-woocommerce' ),
				'type'        => 'checkbox',
				'label'       => __( 'Enable debug logging', 'ainepay-for-woocommerce' ),
				'description' => __( 'Logs to WooCommerce > Status > Logs (source: ainepay). Secrets are redacted.', 'ainepay-for-woocommerce' ),
				'default'     => 'no',
				'desc_tip'    => true,
			),
		);
	}

	/**
	 * Coin options for the multiselect, read from the cached /pay/info response.
	 *
	 * @return array<string,string> Map of "COIN|CHAIN" => label.
	 */
	private function get_cached_coin_options() {
		$cached  = get_option( 'ainepay_supported_coins', array() );
		$options = array();
		if ( is_array( $cached ) ) {
			foreach ( $cached as $entry ) {
				if ( empty( $entry['coin'] ) || empty( $entry['chain'] ) ) {
					continue;
				}
				$key             = $entry['coin'] . '|' . $entry['chain'];
				$label           = isset( $entry['chainName'] ) && '' !== $entry['chainName']
					? sprintf( '%s (%s)', $entry['coin'], $entry['chainName'] )
					: sprintf( '%s / %s', $entry['coin'], $entry['chain'] );
				$options[ $key ] = $label;
			}
		}
		return $options;
	}

	/**
	 * Render password fields with a fixed mask instead of the stored secret.
	 *
	 * @param string $key  Field key.
	 * @param array  $data Field definition.
	 * @return string
	 */
	public function generate_password_html( $key, $data ) {
		if ( in_array( $key, $this->secret_fields, true ) && '' !== (string) $this->get_option( $key ) ) {
			$data['custom_attributes']                 = isset( $data['custom_attributes'] ) ? $data['custom_attributes'] : array();
			$data['custom_attributes']['placeholder']  = self::SECRET_MASK;
			$data['custom_attributes']['autocomplete'] = 'new-password';
			// Render with an empty value; leaving it blank on save preserves the stored secret.
			$this->settings[ $key ] = '';
		}
		return parent::generate_password_html( $key, $data );
	}

	/**
	 * Validate a secret field: an empty submission keeps the previously stored value,
	 * so the secret is never required to round-trip through the browser.
	 *
	 * @param string $key   Field key.
	 * @param string $value Posted value.
	 * @return string
	 */
	public function validate_password_field( $key, $value ) {
		$value = is_null( $value ) ? '' : trim( (string) $value );
		if ( in_array( $key, $this->secret_fields, true ) && '' === $value ) {
			return (string) $this->get_option( $key );
		}
		return $value;
	}

	/**
	 * Validate the API origin before WooCommerce persists it. Invalid input keeps
	 * the last known-safe value and surfaces an admin settings error. The API client
	 * repeats this validation at request time to cover direct option/DB mutation.
	 *
	 * @param string $key   Field key.
	 * @param string $value Posted value.
	 * @return string Validated canonical API base URL.
	 */
	public function validate_api_base_url_field( $key, $value ) {
		$validated = Ainepay_Api_Client::validate_base_url( $value );
		if ( ! is_wp_error( $validated ) ) {
			return $validated;
		}

		if ( class_exists( 'WC_Admin_Settings' ) ) {
			WC_Admin_Settings::add_error(
				__( 'API Base URL was not saved. Use the trusted AinePay HTTPS endpoint.', 'ainepay-for-woocommerce' )
			);
		}

		$previous = Ainepay_Api_Client::validate_base_url( $this->get_option( $key, self::DEFAULT_API_BASE ) );
		return is_wp_error( $previous ) ? self::DEFAULT_API_BASE : $previous;
	}

	/*
	---------------------------------------------------------------------
	 * Checkout & order placement (M3)
	 * -------------------------------------------------------------------
	 */

	/**
	 * The coin/chain combinations enabled by the merchant and still supported
	 * by AinePay, as a map of "COIN|CHAIN" => label.
	 *
	 * @return array<string,string>
	 */
	public function get_available_coins() {
		$enabled   = (array) $this->get_option( 'enabled_coins', array() );
		$available = $this->get_cached_coin_options();
		if ( empty( $enabled ) ) {
			return $available; // No explicit selection yet: offer everything supported.
		}
		return array_intersect_key( $available, array_flip( $enabled ) );
	}

	/**
	 * Whether the gateway can be offered: enabled, configured, supported currency,
	 * and at least one coin available.
	 *
	 * @return bool
	 */
	public function is_available() {
		if ( 'yes' !== $this->enabled ) {
			return false;
		}
		if ( ! Ainepay_Order_Helper::is_supported_currency() ) {
			return false;
		}
		if ( '' === (string) $this->get_option( 'api_key' ) || '' === (string) $this->get_option( 'api_secret' ) ) {
			return false;
		}
		if ( '' === (string) $this->get_option( 'merchant_id' ) || '' === (string) $this->get_option( 'collect_address' ) ) {
			return false;
		}
		// Address verification is mandatory; without the keccak dependency we
		// cannot verify and must not offer the gateway.
		if ( ! Ainepay_Address_Validator::is_available() ) {
			return false;
		}
		if ( empty( $this->get_available_coins() ) ) {
			return false;
		}
		return true;
	}

	/**
	 * Render the coin/chain selector on the classic checkout.
	 *
	 * @return void
	 */
	public function payment_fields() {
		if ( $this->description ) {
			echo wp_kses_post( wpautop( wptexturize( $this->description ) ) );
		}

		$coins = $this->get_available_coins();
		if ( empty( $coins ) ) {
			echo '<p>' . esc_html__( 'No payment coins are currently available.', 'ainepay-for-woocommerce' ) . '</p>';
			return;
		}

		echo '<p class="form-row form-row-wide">';
		echo '<label for="ainepay_coin">' . esc_html__( 'Pay with', 'ainepay-for-woocommerce' ) . '&nbsp;<abbr class="required">*</abbr></label>';
		echo '<select name="ainepay_coin" id="ainepay_coin" class="ainepay-coin-select">';
		foreach ( $coins as $key => $label ) {
			printf( '<option value="%s">%s</option>', esc_attr( $key ), esc_html( $label ) );
		}
		echo '</select>';
		echo '</p>';
	}

	/**
	 * Validate the selected coin on the classic checkout.
	 *
	 * @return bool
	 */
	public function validate_fields() {
		$selected = $this->get_posted_coin();
		if ( '' === $selected || ! array_key_exists( $selected, $this->get_available_coins() ) ) {
			wc_add_notice( __( 'Please select a valid coin to pay with AinePay.', 'ainepay-for-woocommerce' ), 'error' );
			return false;
		}
		return true;
	}

	/**
	 * Read the posted coin selection. WooCommerce Blocks merges the
	 * paymentMethodData ("ainepay_coin") into $_POST before invoking the
	 * gateway, so the same key works for classic and Blocks checkout.
	 *
	 * @return string
	 */
	private function get_posted_coin() {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- WooCommerce checkout verifies its own nonce.
		return isset( $_POST['ainepay_coin'] ) ? sanitize_text_field( wp_unslash( $_POST['ainepay_coin'] ) ) : '';
	}

	/**
	 * Split a "COIN|CHAIN" selector value into [coin, chain]; returns null if invalid.
	 *
	 * @param string $value Selector value.
	 * @return array{0:string,1:string}|null
	 */
	private function parse_coin_value( $value ) {
		$parts = explode( '|', (string) $value, 2 );
		if ( 2 !== count( $parts ) || '' === $parts[0] || '' === $parts[1] ) {
			return null;
		}
		if ( ! array_key_exists( $value, $this->get_available_coins() ) ) {
			return null;
		}
		return array( $parts[0], $parts[1] );
	}

	/**
	 * Place the order with AinePay and verify the returned payment address.
	 *
	 * @param int $order_id WooCommerce order id.
	 * @return array WooCommerce process_payment result.
	 */
	public function process_payment( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return $this->payment_error( __( 'Order not found.', 'ainepay-for-woocommerce' ) );
		}

		// Currency guard (defense in depth; is_available() already filters).
		if ( ! Ainepay_Order_Helper::is_supported_currency( $order->get_currency() ) ) {
			return $this->payment_error( __( 'This store currency is not supported by AinePay.', 'ainepay-for-woocommerce' ) );
		}

		$selected   = $this->get_posted_coin();
		$coin_chain = $this->parse_coin_value( $selected );
		if ( null === $coin_chain ) {
			return $this->payment_error( __( 'Invalid coin selection.', 'ainepay-for-woocommerce' ) );
		}
		list( $coin, $chain ) = $coin_chain;

		$merchant_id = (string) $this->get_option( 'merchant_id' );

		// Whether the userId is derived from an authenticated account (vs a
		// per-order guest key). Captured here so the "balance can be reused"
		// messaging always matches the identity actually used to place the
		// order, even if the order is later linked to a customer account.
		$is_account_user = $order->get_customer_id() > 0;
		// userId is namespaced on a stable, persisted per-site token (not the
		// merchant): one merchant may run several stores under the same key, and
		// WP customer ids are per-site, so per-site scoping keeps each store's
		// customers — and their reusable balances — distinct. The token is
		// URL-independent so an http→https/domain change never changes the userId.
		$user_id = Ainepay_Order_Helper::derive_user_id( Ainepay_Order_Helper::site_namespace(), $order->get_customer_id(), $order_id );
		$qty     = Ainepay_Order_Helper::format_qty( $order->get_total() );

		if ( ! Ainepay_Order_Helper::is_valid_identifier( $user_id ) ) {
			return $this->payment_error( __( 'Could not build a valid order reference.', 'ainepay-for-woocommerce' ) );
		}

		$existing_ainepay_order_id = (string) $order->get_meta( '_ainepay_order_id' );
		$existing_address          = (string) $order->get_meta( '_ainepay_address' );
		if ( '' !== $existing_ainepay_order_id ) {
			if ( '' !== $existing_address && $order->has_status( array( 'pending', 'on-hold' ) ) ) {
				return array(
					'result'   => 'success',
					'redirect' => $this->get_return_url( $order ),
				);
			}

			return $this->payment_error(
				sprintf(
					/* translators: %s: URL to start a new shop order. */
					__( 'This order already has an AinePay payment. <a href="%s">Start a new order</a> if you need to pay again.', 'ainepay-for-woocommerce' ),
					esc_url( $this->get_new_order_url( $order ) )
				),
				true
			);
		}

		$base_params = array(
			'userId'         => $user_id,
			'coin'           => $coin,
			'chain'          => $chain,
			'qty'            => $qty,
			'collectAddress' => (string) $this->get_option( 'collect_address' ),
		);

		// One WooCommerce order must map to at most one AinePay payment order.
		// If the payment expires or the customer wants different parameters,
		// they should start a new WooCommerce order instead of creating another
		// backend payment order for the same shop order.
		$ainepay_order_id = Ainepay_Order_Helper::derive_order_id( $order_id );
		if ( ! Ainepay_Order_Helper::is_valid_identifier( $ainepay_order_id ) ) {
			return $this->payment_error( __( 'Could not build a valid order reference.', 'ainepay-for-woocommerce' ) );
		}

		// Serialise the local create-and-persist window. The backend is idempotent by
		// orderId and returns the existing order on a retry; this lock prevents normal
		// concurrent checkout requests from making duplicate remote calls, while the
		// deterministic orderId still recovers a remote-success/local-crash retry.
		$creation_lock = self::acquire_payment_creation_lock( $order_id );
		if ( ! $creation_lock ) {
			return $this->payment_error( __( 'This payment is already being started. Please wait a moment and try again.', 'ainepay-for-woocommerce' ) );
		}

		// Re-read after acquiring the lock: another worker may have completed the
		// AinePay create and local save while this request was waiting.
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			self::release_payment_creation_lock( $creation_lock );
			return $this->payment_error( __( 'Order not found.', 'ainepay-for-woocommerce' ) );
		}
		$locked_order_id = (string) $order->get_meta( '_ainepay_order_id' );
		$locked_address  = (string) $order->get_meta( '_ainepay_address' );
		if ( '' !== $locked_order_id ) {
			self::release_payment_creation_lock( $creation_lock );
			if ( $locked_order_id === $ainepay_order_id
				&& ( '' !== $locked_address || 'PAID' === strtoupper( (string) $order->get_meta( '_ainepay_status' ) ) ) ) {
				return array(
					'result'   => 'success',
					'redirect' => $this->get_return_url( $order ),
				);
			}
			return $this->payment_error( __( 'This order already has an AinePay payment. Please start a new order.', 'ainepay-for-woocommerce' ) );
		}

		$create_params = array_merge( array( 'orderId' => $ainepay_order_id ), $base_params );
		$result        = $this->get_api_client()->create_pay_order( $create_params );
		if ( is_wp_error( $result ) ) {
			self::release_payment_creation_lock( $creation_lock );
			// API/transport messages are not trusted presentation text. They may
			// contain backend HTML, URLs or internal diagnostics, so checkout always
			// receives a fixed local plain-text message; details stay in WC logs.
			return $this->payment_error(
				__( 'AinePay could not start the payment. Please try again or choose another payment method.', 'ainepay-for-woocommerce' ),
				false
			);
		}

		$binding = Ainepay_Api_Client::validate_create_response( $result, $create_params );
		if ( is_wp_error( $binding ) ) {
			Ainepay_Logger::error(
				'Create-order response binding failed.',
				array(
					'wc_order_id'       => $order_id,
					'reason'            => $binding->get_error_message(),
					'expected_order_id' => $ainepay_order_id,
					'response_order_id' => isset( $result['orderId'] ) ? (string) $result['orderId'] : '',
					'expected_qty'      => $qty,
					'response_qty'      => isset( $result['qty'] ) ? (string) $result['qty'] : '',
				)
			);
			self::release_payment_creation_lock( $creation_lock );
			return $this->payment_error( __( 'AinePay returned inconsistent payment details. Please contact the store owner.', 'ainepay-for-woocommerce' ) );
		}

		$status  = isset( $result['status'] ) ? strtoupper( (string) $result['status'] ) : '';
		$address = isset( $result['address']['address'] ) ? (string) $result['address']['address'] : '';

		// A customer with an existing AinePay balance can be charged the instant
		// the order is created: the backend returns status PAID and no payment
		// address. There is nothing for the customer to pay, so we skip address
		// derivation/verification entirely and settle the order immediately.
		if ( 'PAID' === $status ) {
			$order->update_meta_data( '_ainepay_attempt', 0 );
			$order->update_meta_data( '_ainepay_order_id', $ainepay_order_id );
			$order->update_meta_data( '_ainepay_user_id', $user_id );
			$order->update_meta_data( '_ainepay_account_user', $is_account_user ? '1' : '0' );
			$order->update_meta_data( '_ainepay_coin', $coin );
			$order->update_meta_data( '_ainepay_chain', $chain );
			$order->update_meta_data( '_ainepay_qty', $qty );
			if ( isset( $result['id'] ) ) {
				$order->update_meta_data( '_ainepay_id', (string) $result['id'] );
			}
			$order->update_meta_data( '_ainepay_status', 'PAID' );
			$order->add_order_note( __( 'AinePay order paid from account balance on creation.', 'ainepay-for-woocommerce' ) );
			if ( class_exists( 'Ainepay_Order_Sync' ) ) {
				Ainepay_Order_Sync::mark_order_paid( $order, isset( $result['id'] ) ? (string) $result['id'] : '' );
			}
			$order->save();

			if ( WC()->cart ) {
				WC()->cart->empty_cart();
			}
			self::release_payment_creation_lock( $creation_lock );

			return array(
				'result'   => 'success',
				'redirect' => $this->get_return_url( $order ),
			);
		}

		if ( '' === $address ) {
			self::release_payment_creation_lock( $creation_lock );
			return $this->payment_error( __( 'AinePay did not return a payment address. Please start a new order if the payment window has expired.', 'ainepay-for-woocommerce' ) );
		}

		// Critical: verify the address is deterministically derived from the
		// merchant's collection address before showing it to the customer.
		$verified = Ainepay_Address_Validator::verify(
			$address,
			(string) $this->get_option( 'forwarder_factory' ),
			(string) $this->get_option( 'forwarder_impl' ),
			$merchant_id,
			$user_id,
			(string) $this->get_option( 'collect_address' ),
			(int) $this->get_option( 'forwarder_version', self::DEFAULT_VERSION ),
			(int) $this->get_option( 'chain_id', self::DEFAULT_CHAIN_ID )
		);

		if ( ! $verified ) {
			Ainepay_Logger::error(
				'Payment address verification FAILED — refusing to display.',
				array(
					'wc_order_id'      => $order_id,
					'ainepay_order_id' => $ainepay_order_id,
					'returned_address' => $address,
				)
			);
			$order->update_status( 'failed', __( 'AinePay payment address failed local verification.', 'ainepay-for-woocommerce' ) );
			self::release_payment_creation_lock( $creation_lock );
			return $this->payment_error( __( 'Payment address verification failed. Please contact the store owner.', 'ainepay-for-woocommerce' ) );
		}

		// Persist AinePay order context on the WC order.
		$order->update_meta_data( '_ainepay_attempt', 0 );
		$order->update_meta_data( '_ainepay_order_id', $ainepay_order_id );
		$order->update_meta_data( '_ainepay_user_id', $user_id );
		$order->update_meta_data( '_ainepay_account_user', $is_account_user ? '1' : '0' );
		$order->update_meta_data( '_ainepay_address', $address );
		$order->update_meta_data( '_ainepay_coin', $coin );
		$order->update_meta_data( '_ainepay_chain', $chain );
		$order->update_meta_data( '_ainepay_qty', $qty );
		if ( isset( $result['id'] ) ) {
			$order->update_meta_data( '_ainepay_id', (string) $result['id'] );
		}
		if ( isset( $result['payExpired'] ) ) {
			$order->update_meta_data( '_ainepay_pay_expired', (string) $result['payExpired'] );
		}
		$order->update_meta_data( '_ainepay_status', isset( $result['status'] ) ? (string) $result['status'] : 'INIT' );

		$order->update_status( 'on-hold', __( 'Awaiting AinePay stablecoin payment.', 'ainepay-for-woocommerce' ) );
		$order->save();

		// Reduce stock and empty the cart (standard pending-payment flow).
		wc_reduce_stock_levels( $order_id );
		if ( WC()->cart ) {
			WC()->cart->empty_cart();
		}
		self::release_payment_creation_lock( $creation_lock );

		return array(
			'result'   => 'success',
			'redirect' => $this->get_return_url( $order ),
		);
	}

	/**
	 * Acquire a site-scoped per-Woo-order creation lock with a bounded wait.
	 *
	 * @param int $order_id WooCommerce order id.
	 * @return string|false Lock name on success, false on contention/failure.
	 */
	private static function acquire_payment_creation_lock( $order_id ) {
		global $wpdb;
		$scope = isset( $wpdb->prefix ) ? (string) $wpdb->prefix : '';
		$name  = 'ainepay_create_' . substr( hash( 'sha256', $scope . ':' . (int) $order_id ), 0, 40 );
		$got   = $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, %d)', $name, 5 ) );
		return ( '1' === (string) $got ) ? $name : false;
	}

	/**
	 * Release a payment-creation lock.
	 *
	 * @param string $name Lock name returned by acquire_payment_creation_lock().
	 * @return void
	 */
	private static function release_payment_creation_lock( $name ) {
		global $wpdb;
		$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $name ) );
	}

	/**
	 * Return the customer-facing URL for starting a fresh order.
	 *
	 * @param WC_Order $order WooCommerce order.
	 * @return string
	 */
	private function get_new_order_url( $order ) {
		$url = function_exists( 'wc_get_page_permalink' ) ? wc_get_page_permalink( 'shop' ) : '';
		if ( ! $url ) {
			$url = home_url( '/' );
		}

		return (string) apply_filters( 'ainepay_new_order_url', $url, $order );
	}

	/**
	 * Add a checkout error notice and return the failure result.
	 *
	 * @param string $message    Error message for the customer.
	 * @param bool   $allow_html Whether trusted plugin-generated HTML is required.
	 * @return array
	 */
	private function payment_error( $message, $allow_html = false ) {
		$notice = $allow_html ? wp_kses_post( $message ) : sanitize_text_field( $message );
		wc_add_notice( $notice, 'error' );
		return array( 'result' => 'failure' );
	}
}
