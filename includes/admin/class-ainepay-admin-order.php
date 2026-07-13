<?php
/**
 * Admin order screen: display AinePay payment details (HPOS-compatible).
 *
 * @package AinePay\WooCommerce
 */

defined( 'ABSPATH' ) || exit;

/**
 * Renders AinePay metadata in a meta box on the order edit screen.
 */
class Ainepay_Admin_Order {

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'add_meta_boxes', array( __CLASS__, 'add_meta_box' ) );
		add_action( 'wp_ajax_ainepay_cancel_order', array( __CLASS__, 'ajax_cancel_order' ) );
	}

	/**
	 * Register the meta box for both legacy posts and HPOS order screens.
	 *
	 * @return void
	 */
	public static function add_meta_box() {
		$screen = self::order_screen_id();
		add_meta_box(
			'ainepay-order-details',
			__( 'AinePay payment', 'ainepay-for-woocommerce' ),
			array( __CLASS__, 'render' ),
			$screen,
			'side',
			'default'
		);
	}

	/**
	 * The correct screen id depending on whether HPOS is enabled.
	 *
	 * @return string
	 */
	private static function order_screen_id() {
		if ( class_exists( '\Automattic\WooCommerce\Utilities\OrderUtil' )
			&& \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled() ) {
			return wc_get_page_screen_id( 'shop-order' );
		}
		return 'shop_order';
	}

	/**
	 * Render the meta box.
	 *
	 * @param mixed $post_or_order Post object or WC_Order (HPOS).
	 * @return void
	 */
	public static function render( $post_or_order ) {
		$order = $post_or_order instanceof WC_Order ? $post_or_order : wc_get_order( $post_or_order->ID );
		if ( ! $order || Ainepay_Plugin::GATEWAY_ID !== $order->get_payment_method() ) {
			echo '<p>' . esc_html__( 'Not an AinePay order.', 'ainepay-for-woocommerce' ) . '</p>';
			return;
		}

		$rows = array(
			__( 'AinePay order ID', 'ainepay-for-woocommerce' ) => $order->get_meta( '_ainepay_order_id' ),
			__( 'Coin', 'ainepay-for-woocommerce' )   => $order->get_meta( '_ainepay_coin' ),
			__( 'Chain', 'ainepay-for-woocommerce' )  => $order->get_meta( '_ainepay_chain' ),
			__( 'Amount', 'ainepay-for-woocommerce' ) => $order->get_meta( '_ainepay_qty' ),
			__( 'Payment address', 'ainepay-for-woocommerce' ) => $order->get_meta( '_ainepay_address' ),
			__( 'AinePay status', 'ainepay-for-woocommerce' ) => $order->get_meta( '_ainepay_status' ),
		);

		echo '<table class="ainepay-admin-order" style="width:100%">';
		foreach ( $rows as $label => $value ) {
			if ( '' === (string) $value ) {
				continue;
			}
			printf(
				'<tr><th style="text-align:left;padding:2px 0;">%s</th></tr><tr><td style="word-break:break-all;padding:0 0 6px;">%s</td></tr>',
				esc_html( $label ),
				esc_html( (string) $value )
			);
		}
		echo '</table>';

		self::render_cancel_ui( $order );
	}

	/**
	 * Render the "cancel at AinePay" button for an order that is still awaiting
	 * payment. The button drives a cancel-first flow: the backend decides whether
	 * the (INIT) order can be cancelled, so a settle race that left the order really
	 * paid is reported as paid instead of being lost as cancelled.
	 *
	 * @param WC_Order $order WooCommerce order.
	 * @return void
	 */
	private static function render_cancel_ui( $order ) {
		if ( '' === (string) $order->get_meta( '_ainepay_order_id' ) ) {
			return;
		}

		if ( '1' === (string) $order->get_meta( '_ainepay_cancel_failed' ) ) {
			echo '<p style="color:#b32d2e;">' . esc_html__( 'AinePay cancel needs manual attention (see order notes).', 'ainepay-for-woocommerce' ) . '</p>';
		} elseif ( '1' === (string) $order->get_meta( '_ainepay_cancel_pending' ) ) {
			echo '<p><em>' . esc_html__( 'AinePay cancel is in progress; syncing with AinePay…', 'ainepay-for-woocommerce' ) . '</em></p>';
		}

		// Only offer the button while the order is still awaiting payment AND has not
		// moved past INIT at AinePay. Once the local backing records PENDING (on-chain
		// payment seen) or a settled status, the backend rejects the cancel (code 26),
		// so the button would only yield "can't cancel" and a wasted backend call.
		// Terminal/advanced orders are handled by reconciliation, not a manual cancel.
		if ( ! Ainepay_Order_Sync::is_locally_cancellable( $order ) ) {
			return;
		}

		// Enqueued at render time (footer script) so it only loads when the
		// button is actually shown; the meta box renders before the admin footer.
		wp_enqueue_script(
			'ainepay-admin-order',
			AINEPAY_WC_PLUGIN_URL . 'assets/js/admin-order.js',
			array(),
			AINEPAY_WC_VERSION,
			true
		);
		wp_localize_script(
			'ainepay-admin-order',
			'AinepayAdminOrder',
			array(
				'i18n' => array(
					'confirm' => __( 'Cancel this order at AinePay? Only an unpaid order can be cancelled.', 'ainepay-for-woocommerce' ),
					'working' => __( 'Working…', 'ainepay-for-woocommerce' ),
					'done'    => __( 'Done.', 'ainepay-for-woocommerce' ),
					'failed'  => __( 'Request failed. Please retry.', 'ainepay-for-woocommerce' ),
				),
			)
		);

		$nonce = wp_create_nonce( 'ainepay_cancel_order' );
		?>
		<p>
			<button type="button" class="button" id="ainepay-cancel-order"
				data-order="<?php echo esc_attr( (string) $order->get_id() ); ?>"
				data-nonce="<?php echo esc_attr( $nonce ); ?>">
				<?php esc_html_e( 'Cancel this order at AinePay', 'ainepay-for-woocommerce' ); ?>
			</button>
			<span id="ainepay-cancel-result" style="display:block;margin-top:6px;"></span>
		</p>
		<?php
	}

	/**
	 * AJAX handler for the admin cancel button. Validates the nonce and capability,
	 * delegates to the cancellation coordinator and returns a human message.
	 *
	 * @return void
	 */
	public static function ajax_cancel_order() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => __( 'You are not allowed to do this.', 'ainepay-for-woocommerce' ) ), 403 );
		}
		$nonce = isset( $_POST['nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['nonce'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, 'ainepay_cancel_order' ) ) {
			wp_send_json_error( array( 'message' => __( 'Security check failed. Reload the page and try again.', 'ainepay-for-woocommerce' ) ), 400 );
		}
		$order_id = isset( $_POST['order_id'] ) ? absint( wp_unslash( $_POST['order_id'] ) ) : 0;
		$order    = $order_id ? wc_get_order( $order_id ) : null;
		if ( ! $order ) {
			wp_send_json_error( array( 'message' => __( 'Order not found.', 'ainepay-for-woocommerce' ) ), 404 );
		}

		$outcome = Ainepay_Order_Sync::request_cancel( $order, 'admin' );

		$reload = true;
		switch ( $outcome ) {
			case Ainepay_Order_Sync::CANCEL_DONE:
				$message = __( 'Order cancelled at AinePay.', 'ainepay-for-woocommerce' );
				break;
			case Ainepay_Order_Sync::CANCEL_RECONCILED:
				$message = __( 'This order could not be cancelled; it was updated to match its latest AinePay status (see order notes).', 'ainepay-for-woocommerce' );
				break;
			case Ainepay_Order_Sync::CANCEL_PAID:
				$message = __( 'This order was already paid; it has been moved to a paid state instead of cancelled.', 'ainepay-for-woocommerce' );
				break;
			case Ainepay_Order_Sync::CANCEL_PENDING:
				$message = __( 'An on-chain payment was detected; the order is kept awaiting confirmation and was not cancelled.', 'ainepay-for-woocommerce' );
				break;
			case Ainepay_Order_Sync::CANCEL_RETRY:
				$message = __( 'AinePay is temporarily unreachable. The cancel has been queued and will keep retrying; the order stays on-hold.', 'ainepay-for-woocommerce' );
				break;
			case Ainepay_Order_Sync::CANCEL_FAILED:
				$message = __( 'Cancel failed and needs manual review. See the order notes.', 'ainepay-for-woocommerce' );
				break;
			case Ainepay_Order_Sync::CANCEL_SKIPPED:
			default:
				$message = __( 'This order cannot be cancelled at AinePay.', 'ainepay-for-woocommerce' );
				$reload  = false;
				break;
		}

		wp_send_json_success(
			array(
				'message' => $message,
				'reload'  => $reload,
			)
		);
	}
}
