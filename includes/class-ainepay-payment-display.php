<?php
/**
 * Customer-facing payment instructions: address, QR, countdown and status polling.
 *
 * Renders on the Thank-you page and the My-Account order view, and exposes an
 * AJAX endpoint that polls AinePay for the current order status as a fallback
 * to webhooks.
 *
 * @package AinePay\WooCommerce
 */

defined( 'ABSPATH' ) || exit;

/**
 * Orchestrates the payment display and the status-polling AJAX endpoint.
 */
class Ainepay_Payment_Display {

	const AJAX_ACTION   = 'ainepay_order_status';
	const CANCEL_ACTION = 'ainepay_customer_cancel';

	// The browser normally polls every 15 seconds. Permit one authoritative
	// refresh per order inside a shorter window and cap unauthenticated bursts
	// per client IP before they can consume order lookups or backend workers.
	const STATUS_THROTTLE_SECONDS = 10;
	const STATUS_IP_BURST         = 20;

	// Rate-limit window (seconds) for the unauthenticated customer-cancel
	// endpoint, and the max cancel attempts one client IP may make within it.
	const CANCEL_THROTTLE_SECONDS = 10;
	const CANCEL_IP_BURST         = 8;

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public static function init() {
		$self = new self();
		add_action( 'woocommerce_thankyou_' . Ainepay_Plugin::GATEWAY_ID, array( $self, 'render' ), 10, 1 );
		add_action( 'woocommerce_view_order', array( $self, 'render' ), 20, 1 );
		add_action( 'wp_enqueue_scripts', array( $self, 'maybe_enqueue_assets' ) );

		add_action( 'wp_ajax_' . self::AJAX_ACTION, array( $self, 'ajax_status' ) );
		add_action( 'wp_ajax_nopriv_' . self::AJAX_ACTION, array( $self, 'ajax_status' ) );

		// Customer-initiated cancellation (cancel-first). nopriv too, since guest
		// orders are authorised by the order key, not a login.
		add_action( 'wp_ajax_' . self::CANCEL_ACTION, array( $self, 'ajax_cancel' ) );
		add_action( 'wp_ajax_nopriv_' . self::CANCEL_ACTION, array( $self, 'ajax_cancel' ) );
	}

	/**
	 * Render payment instructions for an AinePay order awaiting payment.
	 *
	 * @param int $order_id WooCommerce order id.
	 * @return void
	 */
	public function render( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order || Ainepay_Plugin::GATEWAY_ID !== $order->get_payment_method() ) {
			return;
		}
		$address = (string) $order->get_meta( '_ainepay_address' );

		// Render whenever this is an AinePay order: an awaiting order needs the
		// address/QR, while a paid (incl. balance-funded, which has no address)
		// or expired order still needs its status shown. Only bail when there is
		// genuinely nothing to display: still awaiting and no address yet.
		$has_outcome = $order->has_status( array( 'completed', 'processing', 'failed', 'cancelled', 'refunded' ) );
		if ( '' === $address && ! $has_outcome ) {
			return;
		}

		$data = array(
			'order'       => $order,
			'address'     => (string) $address,
			'coin'        => (string) $order->get_meta( '_ainepay_coin' ),
			'chain'       => (string) $order->get_meta( '_ainepay_chain' ),
			'qty'         => (string) $order->get_meta( '_ainepay_qty' ),
			'pay_expired' => (int) $order->get_meta( '_ainepay_pay_expired' ),
			'status'      => (string) $order->get_meta( '_ainepay_status' ),
			// Balance reuse only applies when the userId was account-derived at
			// placement (see Ainepay_Order_Helper::can_reuse_balance). Guests get
			// a per-order userId and so cannot reuse a left-over balance.
			'can_reuse'   => Ainepay_Order_Helper::can_reuse_balance( $order ),
			// Final = stop polling. A success WC status only counts as final once it
			// is backed by an authoritative PAID; an UNBACKED processing/
		// completed order is still being verified/reverted by the async guard, so
		// it must keep polling instead of freezing on a contradictory state.
		'is_final'        => self::is_paid_backed( $order ) || $order->has_status( array( 'failed', 'cancelled', 'refunded' ) ),
			'qr_svg'      => class_exists( 'Ainepay_Qr' ) ? Ainepay_Qr::svg( (string) $address ) : '',
		);

		wc_get_template(
			'payment-instructions.php',
			$data,
			'ainepay/',
			AINEPAY_WC_PLUGIN_DIR . 'templates/'
		);
	}

	/**
	 * Enqueue countdown/polling assets when viewing a relevant order page.
	 *
	 * @return void
	 */
	public function maybe_enqueue_assets() {
		if ( ! is_wc_endpoint_url( 'order-received' ) && ! is_wc_endpoint_url( 'view-order' ) ) {
			return;
		}

		// Resolve the order being viewed so the expiry message can match the
		// customer's balance-reuse eligibility (signed-in vs guest).
		global $wp;
		$order_id = 0;
		if ( isset( $wp->query_vars['order-received'] ) ) {
			$order_id = absint( $wp->query_vars['order-received'] );
		} elseif ( isset( $wp->query_vars['view-order'] ) ) {
			$order_id = absint( $wp->query_vars['view-order'] );
		}
		$order     = $order_id ? wc_get_order( $order_id ) : null;
		$can_reuse = $order && Ainepay_Order_Helper::can_reuse_balance( $order );

		$expired_msg = $can_reuse
			? __( 'This payment window has expired. If you already paid, your balance is kept on your AinePay account and can be reused — place the order again while signed in to apply it.', 'ainepay-for-woocommerce' )
			: __( 'This payment window has expired. If you already paid, the funds are held by AinePay; please contact the store to recover them.', 'ainepay-for-woocommerce' );

		wp_register_script(
			'ainepay-payment',
			AINEPAY_WC_PLUGIN_URL . 'assets/js/payment.js',
			array( 'jquery' ),
			AINEPAY_WC_VERSION,
			true
		);
		wp_register_style(
			'ainepay-payment',
			AINEPAY_WC_PLUGIN_URL . 'assets/css/payment.css',
			array(),
			AINEPAY_WC_VERSION
		);

		wp_localize_script(
			'ainepay-payment',
			'AinepayPay',
			array(
				'ajaxUrl'      => admin_url( 'admin-ajax.php' ),
				'action'       => self::AJAX_ACTION,
				'nonce'        => wp_create_nonce( self::AJAX_ACTION ),
				'cancelAction' => self::CANCEL_ACTION,
				'cancelNonce'  => wp_create_nonce( self::CANCEL_ACTION ),
				'interval'     => (int) apply_filters( 'ainepay_poll_interval_ms', 15000 ),
				'i18n'         => array(
					'copied'        => __( 'Copied!', 'ainepay-for-woocommerce' ),
					'expired'       => $expired_msg,
					'paid'          => __( 'Payment received. Thank you!', 'ainepay-for-woocommerce' ),
					'badgePaid'     => __( 'Payment confirmed', 'ainepay-for-woocommerce' ),
					'badgeExpired'  => __( 'Payment window expired', 'ainepay-for-woocommerce' ),
					'cancelConfirm' => __( 'Cancel this order? Only an order that has not been paid can be cancelled.', 'ainepay-for-woocommerce' ),
					'cancelWorking' => __( 'Cancelling…', 'ainepay-for-woocommerce' ),
					'cancelError'   => __( 'Could not cancel right now. Please try again.', 'ainepay-for-woocommerce' ),
				),
			)
		);

		wp_enqueue_script( 'ainepay-payment' );
		wp_enqueue_style( 'ainepay-payment' );
	}

	/**
	 * AJAX: return the current status for an order, refreshing from AinePay when
	 * the order is not yet in a final state.
	 *
	 * @return void
	 */
	public function ajax_status() {
		// Fast-fail abusive clients before nonce verification, order lookup or any
		// possible AinePay request. This is a load-shedding guard, not an auth check.
		if ( self::status_ip_throttled() ) {
			wp_send_json_error( array( 'message' => __( 'Too many requests. Please wait a moment and try again.', 'ainepay-for-woocommerce' ) ), 429 );
		}

		check_ajax_referer( self::AJAX_ACTION, 'nonce' );

		$order_id = isset( $_POST['order_id'] ) ? absint( wp_unslash( $_POST['order_id'] ) ) : 0;
		$order    = $order_id ? wc_get_order( $order_id ) : null;
		if ( ! $order || Ainepay_Plugin::GATEWAY_ID !== $order->get_payment_method() ) {
			wp_send_json_error( array( 'message' => __( 'Order not found.', 'ainepay-for-woocommerce' ) ), 404 );
		}

		// Authorisation: the order key must match (works for guests too).
		$key = isset( $_POST['key'] ) ? sanitize_text_field( wp_unslash( $_POST['key'] ) ) : '';
		if ( ! hash_equals( $order->get_order_key(), $key ) ) {
			wp_send_json_error( array( 'message' => __( 'Unauthorized.', 'ainepay-for-woocommerce' ) ), 403 );
		}

		// Final state: report and stop polling. "paid" requires an authoritative
			// PAID backing: a success WC status alone (e.g. set by an admin or
		// a 3rd party without AinePay confirming payment) must never show as paid.
		if ( self::is_paid_backed( $order ) ) {
			wp_send_json_success(
				array(
					'state' => 'paid',
					'final' => true,
				)
			);
		}
		if ( $order->has_status( 'refunded' ) ) {
			wp_send_json_success(
				array(
					'state' => 'refunded',
					'final' => true,
				)
			);
		}
		if ( $order->has_status( array( 'failed', 'cancelled' ) ) ) {
			wp_send_json_success(
				array(
					'state' => 'expired',
					'final' => true,
				)
			);
		}

		// Collapse repeated requests for the same authorised order into one backend
		// refresh per short window. Throttled callers still receive the current local
		// state below, so normal browser polling remains responsive without amplifying
		// a held order key into synchronous AinePay traffic.
		if ( class_exists( 'Ainepay_Order_Sync' ) && ! self::status_order_throttled( $order_id ) ) {
			$refresh_lock = self::acquire_status_refresh_lock( $order_id );
			if ( $refresh_lock ) {
				try {
					Ainepay_Order_Sync::refresh_order( $order );
					$order = wc_get_order( $order_id );
				} finally {
					self::release_status_refresh_lock( $refresh_lock );
				}
			}
		}

		$state = 'pending';
		if ( self::is_paid_backed( $order ) ) {
			$state = 'paid';
		} elseif ( $order->has_status( 'refunded' ) ) {
			$state = 'refunded';
		} elseif ( $order->has_status( array( 'failed', 'cancelled' ) ) ) {
			$state = 'expired';
		} elseif ( self::is_unbacked_success( $order ) ) {
			// WC says paid but AinePay has not confirmed: keep polling (not final) so
			// the page reflects the async guard's verify/revert rather than freezing.
			$state = 'verifying';
		}

		wp_send_json_success(
			array(
				'state' => $state,
				'final' => in_array( $state, array( 'paid', 'refunded', 'expired' ), true ),
			)
		);
	}

	/**
	 * AJAX: customer-initiated cancellation. Authorised by the order key (so it
	 * works for guests) plus a nonce. Delegates to the cancel-first coordinator,
	 * so the backend decides whether the order can be cancelled — a customer can
	 * only cancel an unpaid (INIT) order, and a settle race that already paid is
	 * reported as paid instead of being lost as cancelled.
	 *
	 * @return void
	 */
	public function ajax_cancel() {
		// Rate-limit this nopriv endpoint up front: each accepted cancel can tie
		// up a PHP worker for the backend timeout, so cap the burst from any one
		// client before spending DB or backend work — even on invalid requests.
		if ( self::cancel_ip_throttled() ) {
			wp_send_json_error( array( 'message' => __( 'Too many requests. Please wait a moment and try again.', 'ainepay-for-woocommerce' ) ), 429 );
		}

		$order_id = isset( $_POST['order_id'] ) ? absint( wp_unslash( $_POST['order_id'] ) ) : 0;
		$order    = $order_id ? wc_get_order( $order_id ) : null;
		if ( ! $order ) {
			wp_send_json_error( array( 'message' => __( 'Order not found.', 'ainepay-for-woocommerce' ) ), 404 );
		}

		$nonce = isset( $_POST['nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['nonce'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, self::CANCEL_ACTION ) ) {
			wp_send_json_error( array( 'message' => __( 'Security check failed. Please refresh and try again.', 'ainepay-for-woocommerce' ) ), 400 );
		}

		// Authorisation: the order key must match (works for guests too).
		$key = isset( $_POST['key'] ) ? sanitize_text_field( wp_unslash( $_POST['key'] ) ) : '';
		if ( ! hash_equals( $order->get_order_key(), $key ) ) {
			wp_send_json_error( array( 'message' => __( 'Unauthorized.', 'ainepay-for-woocommerce' ) ), 403 );
		}

		// Only disclose the payment-method mismatch after the caller proves access
		// to the order. This gives an authenticated customer an accurate response
		// without turning the public endpoint into an order/payment-method probe.
		if ( Ainepay_Plugin::GATEWAY_ID !== $order->get_payment_method() ) {
			wp_send_json_error( array( 'message' => __( 'This order was not paid with AinePay and cannot be cancelled here.', 'ainepay-for-woocommerce' ) ), 409 );
		}

		// Only an order still awaiting payment AND not yet moved past INIT at AinePay
		// is cancellable. When the local backing already records PENDING (on-chain
		// payment seen) or a settled status, the backend would reject the cancel with
		// code 26, so reject here rather than make a doomed synchronous backend call.
		// An empty/INIT backing still proceeds: request_cancel re-checks under the
			// backend row lock so a settle race is reported as paid, not lost.
		if ( ! Ainepay_Order_Sync::is_locally_cancellable( $order ) ) {
			wp_send_json_error( array( 'message' => __( 'This order can no longer be cancelled.', 'ainepay-for-woocommerce' ) ), 409 );
		}

		// Per-order throttle: collapse a flood of cancels for one order (a held
		// order key) to a single in-flight backend attempt per short window. The
		// per-order DB lock in request_cancel still guarantees correctness; this
		// just stops repeated requests each occupying a worker for the backend
		// timeout.
		if ( self::cancel_order_throttled( $order_id ) ) {
			wp_send_json_error( array( 'message' => __( 'Your cancellation is already being processed. Please check back shortly.', 'ainepay-for-woocommerce' ) ), 429 );
		}

		$outcome = class_exists( 'Ainepay_Order_Sync' )
			? Ainepay_Order_Sync::request_cancel( $order, 'customer' )
			: Ainepay_Order_Sync::CANCEL_SKIPPED;

		switch ( $outcome ) {
			case Ainepay_Order_Sync::CANCEL_DONE:
				$message = __( 'Your order has been cancelled.', 'ainepay-for-woocommerce' );
				$reload  = true;
				break;
			case Ainepay_Order_Sync::CANCEL_RECONCILED:
				$message = __( 'This order could not be cancelled; its status has been updated. Please refresh to see the latest status.', 'ainepay-for-woocommerce' );
				$reload  = true;
				break;
			case Ainepay_Order_Sync::CANCEL_PAID:
				$message = __( 'Your payment has already been received, so the order was not cancelled — it is now being processed.', 'ainepay-for-woocommerce' );
				$reload  = true;
				break;
			case Ainepay_Order_Sync::CANCEL_PENDING:
				$message = __( 'We detected your payment on-chain; the order is awaiting confirmation and was not cancelled.', 'ainepay-for-woocommerce' );
				$reload  = false;
				break;
			case Ainepay_Order_Sync::CANCEL_RETRY:
				$message = __( 'Your cancellation is being processed. Please check back shortly.', 'ainepay-for-woocommerce' );
				$reload  = false;
				break;
			default:
				$message = __( 'This order could not be cancelled. Please contact the store.', 'ainepay-for-woocommerce' );
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

	/**
	 * Whether an order is in a success state AND backed by an authoritative AinePay
	 * PAID. This is the only condition under which the customer is shown "paid"
	 * A WC success status without PAID backing must never display success.
	 *
	 * @param WC_Order $order WooCommerce order.
	 * @return bool
	 */
	private static function is_paid_backed( $order ) {
		return $order->has_status( array( 'processing', 'completed' ) )
			&& 'PAID' === strtoupper( (string) $order->get_meta( '_ainepay_status' ) );
	}

	/**
	 * Whether the order is in a WC success state that AinePay has NOT confirmed paid
	 * (e.g. an admin/3rd-party promotion the async guard is still verifying). Such an
	 * order must never be shown as paid OR as awaiting-with-a-pay-address.
	 *
	 * @param WC_Order $order WooCommerce order.
	 * @return bool
	 */
	private static function is_unbacked_success( $order ) {
		return $order->has_status( array( 'processing', 'completed' ) )
			&& 'PAID' !== strtoupper( (string) $order->get_meta( '_ainepay_status' ) );
	}

	/**
	 * Best-effort per-IP burst limit for the unauthenticated status endpoint.
	 * This runs before order lookup; the per-order cooldown below remains the
	 * primary backend-amplification guard for clients that possess a valid key.
	 *
	 * @return bool True when the caller has exceeded its burst budget.
	 */
	private static function status_ip_throttled() {
		$key  = 'ainepay_st_ip_' . md5( self::client_ip() );
		$hits = (int) get_transient( $key );
		if ( $hits >= self::STATUS_IP_BURST ) {
			return true;
		}
		set_transient( $key, $hits + 1, self::STATUS_THROTTLE_SECONDS );
		return false;
	}

	/**
	 * Permit at most one authoritative refresh per order in the cooldown window.
	 * A repeated caller is not rejected: ajax_status() returns the locally cached
	 * state, avoiding both a poor UI and another synchronous backend request.
	 *
	 * @param int $order_id WooCommerce order id (already authorised by order key).
	 * @return bool True when a recent request already claimed the refresh window.
	 */
	private static function status_order_throttled( $order_id ) {
		$key = 'ainepay_st_o_' . (int) $order_id;
		if ( get_transient( $key ) ) {
			return true;
		}
		set_transient( $key, 1, self::STATUS_THROTTLE_SECONDS );
		return false;
	}

	/**
	 * Claim a non-blocking single-flight lock for one status refresh. The transient
	 * cooldown handles normal repeats; this DB lock closes the small get/set race
	 * when multiple PHP workers arrive simultaneously.
	 *
	 * @param int $order_id WooCommerce order id.
	 * @return string|false Lock name on success, false when another worker owns it.
	 */
	private static function acquire_status_refresh_lock( $order_id ) {
		global $wpdb;
		$scope = isset( $wpdb->prefix ) ? (string) $wpdb->prefix : '';
		$name  = 'ainepay_status_' . substr( hash( 'sha256', $scope . ':' . (int) $order_id ), 0, 40 );
		$got   = $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 0)', $name ) );
		return ( '1' === (string) $got ) ? $name : false;
	}

	/**
	 * Release a status-refresh single-flight lock.
	 *
	 * @param string $name Lock name returned by acquire_status_refresh_lock().
	 * @return void
	 */
	private static function release_status_refresh_lock( $name ) {
		global $wpdb;
		$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $name ) );
	}

	/**
	 * Best-effort per-IP burst limit for the customer-cancel endpoint. Transient
	 * backed, so a tiny race may let a couple extra through; that is acceptable
	 * for a rate limiter whose only job is to blunt a flood (correctness is still
	 * guaranteed by the per-order lock downstream).
	 *
	 * @return bool True when the caller has exceeded its burst and must be denied.
	 */
	private static function cancel_ip_throttled() {
		$key  = 'ainepay_cxl_ip_' . md5( self::client_ip() );
		$hits = (int) get_transient( $key );
		if ( $hits >= self::CANCEL_IP_BURST ) {
			return true;
		}
		set_transient( $key, $hits + 1, self::CANCEL_THROTTLE_SECONDS );
		return false;
	}

	/**
	 * Allow at most one customer cancel per order per throttle window, so a held
	 * order key cannot be replayed to flood the backend or exhaust workers.
	 *
	 * @param int $order_id WooCommerce order id.
	 * @return bool True when a cancel for this order is already in flight/recent.
	 */
	private static function cancel_order_throttled( $order_id ) {
		$key = 'ainepay_cxl_o_' . (int) $order_id;
		if ( get_transient( $key ) ) {
			return true;
		}
		set_transient( $key, 1, self::CANCEL_THROTTLE_SECONDS );
		return false;
	}

	/**
	 * Resolve the client IP, preferring WooCommerce's proxy-aware resolver.
	 *
	 * @return string
	 */
	private static function client_ip() {
		if ( class_exists( 'WC_Geolocation' ) ) {
			$ip = (string) WC_Geolocation::get_ip_address();
			if ( '' !== $ip ) {
				return $ip;
			}
		}
		return isset( $_SERVER['REMOTE_ADDR'] )
			? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) )
			: '0.0.0.0';
	}
}
