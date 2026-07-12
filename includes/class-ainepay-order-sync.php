<?php
/**
 * Maps AinePay order status onto WooCommerce order status, with atomic,
 * idempotent processing shared by the webhook handler and the polling fallback.
 *
 * Status mapping:
 *   PAID    -> processing (or completed for virtual/downloadable, configurable)
 *   EXPIRED -> failed
 *   PENDING -> on-hold (note only)
 *
 * Concurrency: a MySQL GET_LOCK named per order serialises
 * processing. Lock contention returns a "busy" outcome so the caller can ask
 * AinePay to retry (HTTP 503) rather than silently dropping a concurrent event.
 *
 * @package AinePay\WooCommerce
 */

defined( 'ABSPATH' ) || exit;

/**
 * Outcome codes returned by handle_notification().
 */
class Ainepay_Order_Sync {

	const RESULT_OK        = 'ok';        // Processed (or no-op because already final / duplicate).
	const RESULT_BUSY      = 'busy';      // Could not acquire the lock; caller should retry.
	const RESULT_RETRY     = 'retry';     // Transient failure (e.g. query failed); caller should retry.
	const RESULT_NOT_FOUND = 'not_found'; // No local order for this AinePay orderId.

	const LOCK_TIMEOUT_SECONDS = 5;
	const MAX_IDEMPOTENCY_KEYS = 20;

	// Persistent cancel-sync (Action Scheduler) retry policy.
	const CANCEL_SYNC_HOOK           = 'ainepay_cancel_sync';
	const VERIFY_PAID_HOOK           = 'ainepay_verify_paid';
	const REFUND_VERIFY_HOOK         = 'ainepay_refund_verify';
	const CANCEL_MAX_ATTEMPTS        = 8;  // ~24h total with the backoff ladder below.
	const REFUND_MAX_ATTEMPTS        = 28; // Reachable non-REFUND checks only; ~1 week once delay caps at 6h.
	const REFUND_OUTAGE_MAX_ATTEMPTS = 8; // Consecutive backend-unreachable checks; separate from merchant grace.
	const PAID_VERIFY_MAX_ATTEMPTS   = 8;  // Same ladder; bounded retry before a manual-review alert.
	const CANCEL_GROUP               = 'ainepay';

	// Backend ApiResult.ResultCodeEnum numeric codes the cancel flow reasons about.
	const CODE_ORDER_STATUS_INVALID = 26; // Non-INIT order: re-query authority.
	const CODE_RATE_LIMITED         = 19; // Transient: back off and retry.
	const CODE_UNKNOWN_ERROR        = 1;  // Backend DB/transaction blip: transient.

	// request_cancel() outcomes (returned to admin/customer/retry callers).
	const CANCEL_DONE       = 'cancelled';   // Backend CANCEL confirmed; WC cancelled.
	const CANCEL_PAID       = 'paid';        // Settle won the race; order repaired to paid.
	const CANCEL_PENDING    = 'pending';     // On-chain payment detected; kept on-hold.
	const CANCEL_RETRY      = 'retry';       // Transient failure; retry scheduled.
	const CANCEL_FAILED     = 'failed';      // Permanent error; manual attention.
	const CANCEL_SKIPPED    = 'skipped';     // Not an AinePay order / no order id.
	const CANCEL_RECONCILED = 'reconciled';  // Terminal but NOT a cancellation (EXPIRED/REFUND).

	/**
	 * Locate the WooCommerce order for an AinePay orderId (stored in meta).
	 *
	 * @param string $ainepay_order_id AinePay order id.
	 * @return WC_Order|null
	 */
	public static function find_order( $ainepay_order_id ) {
		$orders = wc_get_orders(
			array(
				'limit'      => 1,
				'meta_key'   => '_ainepay_order_id', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value' => $ainepay_order_id,   // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
				'return'     => 'objects',
			)
		);
		if ( is_array( $orders ) && ! empty( $orders ) ) {
			return $orders[0];
		}
		return null;
	}

	/**
	 * Process a verified notification payload. Caller must have already checked
	 * the signature.
	 *
	 * @param array $fields Parsed, verified notification fields.
	 * @return string One of the RESULT_* constants.
	 */
	public static function handle_notification( array $fields ) {
		$ainepay_order_id = isset( $fields['orderId'] ) ? (string) $fields['orderId'] : '';
		if ( '' === $ainepay_order_id ) {
			return self::RESULT_NOT_FOUND;
		}

		$order = self::find_order( $ainepay_order_id );
		if ( ! $order ) {
			// Notifications for orders this site never created are expected
			// (probe orders, other channels sharing the merchant notify URL).
			Ainepay_Logger::debug( 'Notification for unknown order; acknowledging.', array( 'orderId' => $ainepay_order_id ) );
			return self::RESULT_NOT_FOUND;
		}

		$lock = self::acquire_lock( $ainepay_order_id );
		if ( ! $lock ) {
			Ainepay_Logger::debug( 'Could not acquire order lock; asking for retry.', array( 'orderId' => $ainepay_order_id ) );
			return self::RESULT_BUSY;
		}

		try {
			// Re-read inside the lock; another worker may have just finalised it.
			$order = self::find_order( $ainepay_order_id );
			if ( ! $order ) {
				return self::RESULT_NOT_FOUND;
			}

			// Settled-state guard: short-circuit ONLY when the local terminal status
			// is already backed by the matching authoritative AinePay terminal status.
			// An unbacked terminal (e.g. cancelled while _ainepay_status is empty/
			// INIT/PENDING because a settle race or a local cancel mislabelled it)
			// must still be re-queried so an authoritative PAID/CANCEL can repair it.
			//
			// One exception: a PAID-backed success order can still move to REFUND at the
			// backend (REFUND is the single legal successor of PAID, e.g. a refund issued
			// directly in the AinePay dashboard, bypassing the WooCommerce-first refund
			// flow). The backend requeues a REFUND notification for it, but PAID-backed
			// would otherwise short-circuit that notification and WC would never converge
			// to refunded. So when a (signature-verified) notification reports REFUND for
			// a PAID-backed order, do NOT short-circuit: fall through to re-query and let
			// the authoritative status be applied. The query below still confirms the real
			// status, so a spurious body can at worst trigger one extra no-op query.
			$claimed_status            = isset( $fields['status'] ) ? strtoupper( (string) $fields['status'] ) : '';
			$paid_backed_refund_notice = ( 'REFUND' === $claimed_status && self::is_paid_backed_order( $order ) );
			if ( self::is_settled_and_backed( $order ) && ! $paid_backed_refund_notice ) {
				return self::RESULT_OK;
			}

			// Always confirm the authoritative status by querying AinePay (never
			// trust the notification body alone, and the polling path carries no
			// body). Query failure -> retry (HTTP 503/500).
			$confirmed = self::query_status( $ainepay_order_id );
			if ( null === $confirmed ) {
				return self::RESULT_RETRY;
			}

			// Idempotency keyed on the *confirmed* (status, updated) so empty or
			// spoofed notification bodies never participate. A confirmed record
			// without an updated timestamp is not de-duplicated.
			$status  = isset( $confirmed['status'] ) ? (string) $confirmed['status'] : '';
			$updated = isset( $confirmed['updated'] ) ? (string) $confirmed['updated'] : '';
			$key     = ( '' !== $updated ) ? $status . ':' . $updated : '';
			// Reconciliation-first: a seen idempotency key only short-circuits when
			// the order is ALREADY consistent with the authoritative status. If the
			// local status has since drifted (e.g. a settle race or external edit
			// mislabelled a PAID order cancelled), the same (status, updated) record
			// must still be applied to repair it — the key must never skip a fix.
			if ( '' !== $key && self::seen_idempotency_key( $order, $key ) && self::is_settled_and_backed( $order ) ) {
				return self::RESULT_OK;
			}

			self::apply_status( $order, $confirmed, $fields );
			if ( '' !== $key ) {
				self::record_idempotency_key( $order, $key );
			}

			return self::RESULT_OK;
		} finally {
			self::release_lock( $lock );
		}
	}

	/**
	 * Refresh an order's status from AinePay (used by the polling fallback).
	 * Reuses the same atomic, idempotent path but tolerates being a no-op.
	 *
	 * @param WC_Order $order WooCommerce order.
	 * @return void
	 */
	public static function refresh_order( $order ) {
		if ( ! $order || self::is_settled_and_backed( $order ) ) {
			return;
		}
		$ainepay_order_id = (string) $order->get_meta( '_ainepay_order_id' );
		if ( '' === $ainepay_order_id ) {
			return;
		}

		// Poller safety-net for states whose bounded Action Scheduler verification
		// chain may have been dropped or exhausted.
		//
		// An unbacked success state must converge from the authoritative status:
		// PAID backs it; INIT/PENDING return it to on-hold; terminal statuses map
		// normally. A Woo-first refunded order is different: while the merchant has
		// not completed the second AinePay refund step, backend PAID must NOT promote
		// the Woo order back to processing. Only REFUND may be applied there.
		if ( $order->has_status( array( 'processing', 'completed', 'refunded' ) ) ) {
			self::refresh_unbacked_terminal( $order, $ainepay_order_id );
			return;
		}

		// A WC=cancelled order not yet backed by an authoritative CANCEL needs the
		// cancel-first coordinator, NOT a plain status refresh: handle_notification's
		// INIT branch is a no-op for a cancelled order, so a backend-still-INIT order
		// (e.g. a native admin cancel whose immediate reconcile action was dropped)
		// would otherwise stay stuck at WC=cancelled / AinePay=INIT with its stock
		// held by gate_premature_restock forever. request_cancel drives INIT->CANCEL,
		// repairs a settle-race PAID back to processing, and is idempotent for an
		// already-CANCEL order. Skip when a cancel-sync retry is already pending so the
		// poller does not consume the retry budget while that worker still owns it.
		if ( $order->has_status( 'cancelled' )
			&& 'CANCEL' !== strtoupper( (string) $order->get_meta( '_ainepay_status' ) )
			&& ! self::cancel_sync_is_scheduled( $ainepay_order_id ) ) {
			self::request_cancel( $order, 'retry' );
			return;
		}

		self::handle_notification( array( 'orderId' => $ainepay_order_id ) );
	}

	/**
	 * Safely repair an unbacked success/refunded state from the recurring poller.
	 *
	 * @param WC_Order $order            WooCommerce order.
	 * @param string   $ainepay_order_id AinePay order id.
	 * @return void
	 */
	private static function refresh_unbacked_terminal( $order, $ainepay_order_id ) {
		$confirmed = self::query_status( $ainepay_order_id );
		if ( null === $confirmed ) {
			return;
		}
		$status = strtoupper( isset( $confirmed['status'] ) ? (string) $confirmed['status'] : '' );

		if ( ! in_array( $status, array( 'INIT', 'PENDING', 'PAID', 'CANCEL', 'EXPIRED', 'REFUND' ), true ) ) {
			return;
		}
		// Woo-first refund is intentional. Do not undo it while AinePay is still
		// PAID; the poller only closes this state once the backend confirms REFUND.
		// If the bounded outage chain stopped, a successful poll re-arms the merchant
		// grace chain. Do not consume another merchant check while an action is already
		// pending/in-progress.
		if ( $order->has_status( 'refunded' ) && 'REFUND' !== $status ) {
			self::clear_refund_outage( $order );
			if ( ! self::refund_verify_is_scheduled( $ainepay_order_id ) ) {
				$reason = '1' === (string) $order->get_meta( '_ainepay_refund_pending' ) ? 'merchant' : 'initial';
				self::schedule_refund_verify( $order, $ainepay_order_id, $reason );
			}
			return;
		}

		$lock = self::acquire_lock( $ainepay_order_id );
		if ( ! $lock ) {
			return;
		}
		try {
			$fresh = self::find_order( $ainepay_order_id );
			if ( ! $fresh || self::is_settled_and_backed( $fresh ) ) {
				return;
			}
			// Preserve a refund that became pending while the query was in flight.
			if ( $fresh->has_status( 'refunded' ) && 'REFUND' !== $status ) {
				return;
			}
			self::apply_status( $fresh, $confirmed, array() );
			self::clear_paid_verify( $fresh );
		} finally {
			self::release_lock( $lock );
		}
	}

	/**
	 * Whether the order's local terminal status is already backed by the matching
	 * authoritative AinePay terminal status, so it can be safely short-circuited.
	 *
	 * A non-terminal status (on-hold/pending) always returns false (keep polling).
	 * A terminal WC status whose `_ainepay_status` meta does not match the expected
	 * backing status also returns false, so an authoritative re-query can repair it
	 * (e.g. a settle race mislabelled the order cancelled while it was really PAID).
	 *
	 * @param WC_Order $order WooCommerce order.
	 * @return bool
	 */
	private static function is_settled_and_backed( $order ) {
		$status = strtoupper( (string) $order->get_meta( '_ainepay_status' ) );
		if ( $order->has_status( array( 'processing', 'completed' ) ) ) {
			return self::is_paid_backed_order( $order );
		}
		if ( $order->has_status( 'failed' ) ) {
			return 'EXPIRED' === $status;
		}
		if ( $order->has_status( 'cancelled' ) ) {
			return 'CANCEL' === $status;
		}
		if ( $order->has_status( 'refunded' ) ) {
			return 'REFUND' === $status;
		}
		return false; // Non-terminal: proceed.
	}

	/**
	 * Public integration contract for fulfilment plugins.
	 *
	 * WooCommerce fires `woocommerce_order_status_processing/completed` before the
	 * later generic status-changed guard can verify an external promotion. A third
	 * party that fulfils from those status hooks MUST call this method and return
	 * without side effects unless it is true. Prefer the `ainepay_order_paid_backed`
	 * action below when the integration can use a dedicated AinePay trigger.
	 *
	 * This method deliberately returns false for non-AinePay orders: callers should
	 * invoke it only after checking the payment method, as shown in the README.
	 *
	 * @param mixed $order Expected WC_Order.
	 * @return bool True only for an AinePay order in a Woo success state backed by
	 *              authoritative `_ainepay_status=PAID`.
	 */
	public static function is_paid_backed_order( $order ) {
		return $order instanceof WC_Order
			&& Ainepay_Plugin::GATEWAY_ID === $order->get_payment_method()
			&& '' !== (string) $order->get_meta( '_ainepay_order_id' )
			&& $order->has_status( array( 'processing', 'completed' ) )
			&& 'PAID' === strtoupper( (string) $order->get_meta( '_ainepay_status' ) );
	}

	/**
	 * Whether the order is still a local cancel candidate.
	 *
	 * Cancel is a backend-decided INIT→CANCEL transition: the backend only allows
	 * an INIT order to be cancelled and returns code 26 for anything past it. So
	 * once AinePay has authoritatively moved past INIT locally — an on-chain
	 * payment was seen (PENDING) or the order settled (PAID/EXPIRED/CANCEL/REFUND)
	 * — a cancel can no longer succeed. Offering the button or making a doomed
	 * synchronous backend call in that state only confuses the user and burns a
	 * worker, so callers gate the cancel entry on this.
	 *
	 * An empty or INIT backing is still treated as cancellable and proceeds to the
	 * backend, which re-checks under its row lock so a settle race that already
	 * paid is reported as paid rather than lost as cancelled. This guards
	 * the UI and the entry points against a *known* stale state; it deliberately
	 * does NOT replace the backend's authoritative decision for INIT orders.
	 *
	 * @param mixed $order Expected WC_Order.
	 * @return bool
	 */
	public static function is_locally_cancellable( $order ) {
		if ( ! $order instanceof WC_Order
			|| Ainepay_Plugin::GATEWAY_ID !== $order->get_payment_method()
			|| '' === (string) $order->get_meta( '_ainepay_order_id' )
			|| ! $order->has_status( array( 'on-hold', 'pending' ) ) ) {
			return false;
		}
		$backed = strtoupper( (string) $order->get_meta( '_ainepay_status' ) );
		return '' === $backed || 'INIT' === $backed;
	}

	/**
	 * Query AinePay for the authoritative order status.
	 *
	 * @param string $ainepay_order_id AinePay order id.
	 * @return array|null The order record, or null on query failure.
	 */
	private static function query_status( $ainepay_order_id ) {
		$gateway = self::gateway();
		if ( ! $gateway ) {
			return null;
		}
		$result = $gateway->get_api_client()->get_orders( array( $ainepay_order_id ) );
		if ( is_wp_error( $result ) ) {
			Ainepay_Logger::error( 'Order status query failed: ' . $result->get_error_message(), array( 'orderId' => $ainepay_order_id ) );
			return null;
		}
		$orders = isset( $result['orders'] ) && is_array( $result['orders'] ) ? $result['orders'] : array();
		foreach ( $orders as $o ) {
			if ( isset( $o['orderId'] ) && (string) $o['orderId'] === $ainepay_order_id ) {
				return $o;
			}
		}
		return null;
	}

	/**
	 * Apply the confirmed AinePay status to the WooCommerce order.
	 *
	 * @param WC_Order $order     WooCommerce order.
	 * @param array    $confirmed Confirmed order record from /order.
	 * @param array    $fields    Original notification fields (for the note).
	 * @return void
	 */
	private static function apply_status( $order, $confirmed, $fields ) {
		$status = isset( $confirmed['status'] ) ? strtoupper( (string) $confirmed['status'] ) : '';

		// The `_ainepay_status` meta is the backing the settled-state guard relies
		// on (is_settled_and_backed). For terminal statuses it MUST always be
		// persisted, even when the record carries no `updated` timestamp; otherwise
		// the local terminal status stays "unbacked" and is reprocessed forever.
		// Setting it before update_status() means WC's own save() inside
		// update_status() persists the meta and the status together (no window
		// where WC=cancelled but meta is empty).
		$is_terminal = in_array( $status, array( 'PAID', 'EXPIRED', 'CANCEL', 'REFUND' ), true );
		if ( $is_terminal || isset( $confirmed['updated'] ) ) {
			$order->update_meta_data( '_ainepay_status', $status );
		}
		if ( isset( $confirmed['updated'] ) ) {
			$order->update_meta_data( '_ainepay_updated', (string) $confirmed['updated'] );
		}

		switch ( $status ) {
			case 'PAID':
				// Sole entry to a success state, and the repair path: if a settle
				// race or a local cancel previously mislabelled this order, this
				// moves it back to processing/completed. payment_complete() re-reduces
				// stock, which WC de-duplicates via _order_stock_reduced.
				$order->add_order_note( __( 'AinePay payment confirmed.', 'ainepay-for-woocommerce' ) );
				self::mark_order_paid( $order, isset( $confirmed['id'] ) ? (string) $confirmed['id'] : '' );
				break;

			case 'EXPIRED':
				// Any funds the customer sent are held by AinePay. They are only
				// reusable on a new order when the userId was account-derived at
				// placement; a guest order's userId is unique per order.
				$expired_note = Ainepay_Order_Helper::can_reuse_balance( $order )
					? __( 'AinePay payment expired. Any funds sent remain as a reusable balance on the customer\'s AinePay account.', 'ainepay-for-woocommerce' )
					: __( 'AinePay payment expired. Any funds sent are held by AinePay; a guest order cannot reuse them automatically.', 'ainepay-for-woocommerce' );
				if ( ! $order->has_status( 'failed' ) ) {
					// A native/bulk admin cancel whose stock restore gate_premature_restock()
					// held back (its `_ainepay_status` was not CANCEL) can reconcile to EXPIRED
					// instead of CANCEL. The cancelled->failed transition does not restock, and
					// the gate had blocked the cancelled-transition restock, so without this the
					// reserved stock would leak forever. Release it now, symmetric with the
					// CANCEL branch. Scoped to the held-cancelled case so a normal on-hold->failed
					// expiry keeps WooCommerce's own stock semantics unchanged.
					$held_cancelled = $order->has_status( 'cancelled' );
					$order->update_status( 'failed', $expired_note );
					if ( $held_cancelled ) {
						self::release_held_stock( $order );
					}
				}
				break;

			case 'CANCEL':
				// Backend confirmed INIT->CANCEL (or idempotent repeat). A CANCEL can
				// never apply to a paid order because the backend rejects non-INIT
				// cancels (code 26). Two cases:
				// - Not yet cancelled (cancel-first button / webhook): transition to
				// cancelled now. `_ainepay_status` is already CANCEL (set above), so
				// gate_premature_restock() lets WC core restock on this transition.
				// - Already cancelled (a native/bulk admin cancel whose stock restore
				// gate_premature_restock() held back until the backend confirmed):
				// the transition will not re-fire, so release the held stock now.
				if ( ! $order->has_status( 'cancelled' ) ) {
					$order->update_status( 'cancelled', __( 'AinePay order cancelled.', 'ainepay-for-woocommerce' ) );
				} else {
					self::release_held_stock( $order );
				}
				break;

			case 'REFUND':
				if ( ! $order->has_status( 'refunded' ) ) {
					$order->update_status( 'refunded', __( 'AinePay order refunded.', 'ainepay-for-woocommerce' ) );
				}
				// Authoritative REFUND closes the manual two-step refund loop: the
				// WC-first refund is now confirmed at AinePay, so stop verifying.
				self::clear_refund_pending( $order );
				break;

			case 'PENDING':
				// An on-chain payment was detected but not yet confirmed: this is
				// NOT terminal and NOT a success. If the order had been (mis)labelled
				// cancelled/failed — which released its stock — restore it to on-hold
				// so the stock is re-reserved and no oversell window remains while we
				// await confirmation (matches the reconciliation matrix). It must NOT
				// stay cancelled: a payment is incoming and may settle to PAID.
				// Otherwise just note the first transition to avoid timeline spam.
				if ( $order->has_status( array( 'cancelled', 'failed', 'processing', 'completed' ) ) ) {
					$order->update_status( 'on-hold', __( 'AinePay payment detected on-chain; order restored to awaiting confirmation.', 'ainepay-for-woocommerce' ) );
					$order->update_meta_data( '_ainepay_status_noted', 'PENDING' );
				} elseif ( 'PENDING' !== (string) $order->get_meta( '_ainepay_status_noted' ) ) {
					$order->add_order_note( __( 'AinePay payment detected on-chain; awaiting confirmations.', 'ainepay-for-woocommerce' ) );
					$order->update_meta_data( '_ainepay_status_noted', 'PENDING' );
				}
				break;

			case 'INIT':
				// Poller repair for an externally promoted, unbacked success state.
				// INIT is unpaid, so it must return to on-hold rather than remain a
				// Woo success merely because the bounded async verify chain ended.
				if ( $order->has_status( array( 'processing', 'completed' ) ) ) {
					$order->update_status( 'on-hold', __( 'Order restored to on-hold: AinePay confirms it is still awaiting payment.', 'ainepay-for-woocommerce' ) );
				}
				break;

			default:
				// Unknown: do not infer a state.
				break;
		}

		$order->save();
		Ainepay_Logger::debug(
			'Applied AinePay status',
			array(
				'orderId' => (string) $order->get_meta( '_ainepay_order_id' ),
				'status'  => $status,
			)
		);
	}

	/**
	 * Move a confirmed-paid order to its paid state. Fully virtual/downloadable
	 * orders go straight to `completed`; anything else lands on `processing` via
	 * payment_complete(). Shared by the webhook/poll path and by process_payment()
	 * for balance-funded orders that are PAID the instant they are created.
	 *
	 * @param WC_Order $order      WooCommerce order.
	 * @param string   $ainepay_id AinePay transaction id (for the WC transaction id).
	 * @return void
	 */
	public static function mark_order_paid( $order, $ainepay_id = '' ) {
		if ( $order->has_status( array( 'processing', 'completed' ) ) ) {
			// Repair path: an external system already promoted the order, and AinePay
			// has now authoritatively backed it. Notify dedicated integrations once.
			self::notify_paid_backed( $order, $ainepay_id );
			return;
		}
		// Decide the target before payment_complete(), which itself moves the
		// order to processing/completed and would otherwise make the completed
		// branch unreachable.
		$complete = self::should_complete( $order );
		$order->payment_complete( $ainepay_id );
		if ( $complete ) {
			$order->update_status( 'completed' );
		}
		self::notify_paid_backed( $order, $ainepay_id );
	}

	/**
	 * Emit the dedicated, authoritative fulfilment trigger once in normal execution.
	 *
	 * The marker is persisted after callbacks return so a process failure cannot
	 * permanently suppress fulfilment. A crash after an external side effect but
	 * before the marker save can cause replay, so integrations MUST use the order id
	 * as their own idempotency key.
	 *
	 * @param WC_Order $order      WooCommerce order.
	 * @param string   $ainepay_id AinePay transaction id.
	 * @return void
	 */
	private static function notify_paid_backed( $order, $ainepay_id ) {
		if ( ! self::is_paid_backed_order( $order )
			|| '1' === (string) $order->get_meta( '_ainepay_paid_backed_notified' ) ) {
			return;
		}
		do_action( 'ainepay_order_paid_backed', $order, (string) $ainepay_id );
		$order->update_meta_data( '_ainepay_paid_backed_notified', '1' );
		$order->save();
	}

	/**
	 * Whether the order should go straight to completed (all virtual/downloadable).
	 *
	 * @param WC_Order $order WooCommerce order.
	 * @return bool
	 */
	private static function should_complete( $order ) {
		foreach ( $order->get_items() as $item ) {
			$product = is_callable( array( $item, 'get_product' ) ) ? $item->get_product() : null;
			if ( ! $product || ! $product->is_virtual() ) {
				return false;
			}
		}
		return apply_filters( 'ainepay_complete_virtual_orders', true, $order );
	}

	/* --- cancellation (cancel-first coordinator + persistent retry) ------- */

	/**
	 * Cancel an INIT order at AinePay, then reconcile the WooCommerce order to the
	 * authoritative result. cancel-first: the backend decides whether the order can
	 * be cancelled; the plugin never marks an order cancelled until the backend has
	 * confirmed CANCEL. This protects the merchant: a settle race that left
	 * the order really PAID is detected and repaired to a paid state instead of
	 * being lost as cancelled. Idempotent: a repeat cancel of an already
	 * cancelled order returns success and reconciles to the same CANCEL state.
	 *
	 * Shared by the admin button, the woocommerce_order_status_cancelled safety net
	 * and the persistent retry worker.
	 *
	 * @param WC_Order $order  WooCommerce order.
	 * @param string   $source One of admin|customer|hook|retry (diagnostics only).
	 * @return string One of the CANCEL_* outcome constants.
	 */
	public static function request_cancel( $order, $source = 'admin' ) {
		if ( ! ( $order instanceof WC_Order ) || Ainepay_Plugin::GATEWAY_ID !== $order->get_payment_method() ) {
			return self::CANCEL_SKIPPED;
		}
		$oid = (string) $order->get_meta( '_ainepay_order_id' );
		if ( '' === $oid ) {
			return self::CANCEL_SKIPPED;
		}

		$gateway = self::gateway();
		if ( ! $gateway ) {
			self::schedule_cancel_sync( $order, $oid );
			return self::CANCEL_RETRY;
		}

		$lock = self::acquire_lock( $oid );
		if ( ! $lock ) {
			// Another worker (webhook/poller/cancel) holds the lock: retry shortly.
			self::schedule_cancel_sync( $order, $oid );
			return self::CANCEL_RETRY;
		}

		try {
			// Re-read inside the lock; another worker may have just finalised it.
			$order = self::find_order( $oid );
			if ( ! $order ) {
				return self::CANCEL_SKIPPED;
			}

			$result = $gateway->get_api_client()->cancel_order( $oid );

			// A success array is only trusted as CANCEL when it actually identifies
			// THIS order as cancelled. An empty body, a mismatched orderId or any
			// non-CANCEL status (a contract change, a cross-order response) must not
			// blindly cancel the WooCommerce order — fall back to an authoritative
			// query. The backend never returns success for a non-INIT order, so a
				// genuine success can never cancel a paid order.
			if ( is_array( $result ) ) {
				$rid     = isset( $result['orderId'] ) ? (string) $result['orderId'] : '';
				$rstatus = strtoupper( isset( $result['status'] ) ? (string) $result['status'] : '' );
				if ( $rid === $oid && 'CANCEL' === $rstatus ) {
					self::apply_status( $order, $result, array() );
					self::clear_cancel_pending( $order );
					return self::CANCEL_DONE;
				}
				return self::reconcile_via_query( $order, $oid );
			}

			$class = self::classify_cancel_error( $result );

			if ( 'NOT_INIT' === $class ) {
				// Order is no longer INIT (settle/expiry/already cancelled): the
				// backend is authoritative, so re-query and reconcile to the real
				// status. PAID here repairs a mislabelled order back to paid.
				return self::reconcile_via_query( $order, $oid );
			}

			if ( 'TRANSIENT' === $class ) {
				// Never touch WC on a transient failure: keep the order on-hold and
				// retry until the backend reaches a terminal state.
				self::schedule_cancel_sync( $order, $oid );
				return self::CANCEL_RETRY;
			}

			// PERMANENT: a bug or misconfiguration. Keep on-hold, flag for a human.
			$order->add_order_note(
				sprintf(
					/* translators: %s: error message. */
					__( 'AinePay cancel failed permanently: %s. Order kept on-hold for manual review.', 'ainepay-for-woocommerce' ),
					$result->get_error_message()
				)
			);
			$order->update_meta_data( '_ainepay_cancel_failed', '1' );
			$order->save();
			Ainepay_Logger::error(
				'Cancel permanent failure',
				array(
					'orderId' => $oid,
					'msg'     => $result->get_error_message(),
				)
			);
			return self::CANCEL_FAILED;
		} finally {
			self::release_lock( $lock );
		}
	}

	/**
	 * Classify a cancel WP_Error into NOT_INIT / TRANSIENT / PERMANENT.
	 *
	 * @param WP_Error $error Error from cancel_order().
	 * @return string
	 */
	private static function classify_cancel_error( $error ) {
		$data   = $error->get_error_data();
		$code   = is_array( $data ) && isset( $data['code'] ) ? (int) $data['code'] : null;
		$status = is_array( $data ) && isset( $data['status'] ) ? (int) $data['status'] : null;
		$err    = $error->get_error_code();

		if ( self::CODE_ORDER_STATUS_INVALID === $code ) {
			return 'NOT_INIT';
		}

		// Transient: HTTP 5xx/429, backend rate limiting, an unexpected (non-JSON)
		// body, a backend UNKNOWN_ERROR (a DB/transaction blip), or any transport
		// failure (a WP_Error whose code is none of our own logical codes).
		$is_transport = ! in_array( $err, array( 'ainepay_api_error', 'ainepay_config', 'ainepay_bad_request' ), true );
		if ( ( null !== $status && ( $status >= 500 || 429 === $status ) )
			|| self::CODE_RATE_LIMITED === $code
			|| self::CODE_UNKNOWN_ERROR === $code
			|| 'ainepay_bad_response' === $err
			|| $is_transport ) {
			return 'TRANSIENT';
		}

		// ainepay_config / ainepay_bad_request / PARAMETER_ERROR and other definite
		// logical errors: retrying will not help.
		return 'PERMANENT';
	}

	/**
	 * Re-query the authoritative status and reconcile. The cancel intent (pending
	 * flag) is only cleared once the query returns a KNOWN status for the matching
	 * orderId; a timeout, an empty list, a mismatch or an unknown status is treated
	 * as transient and retried, so a cancel is never silently lost.
	 *
	 * @param WC_Order $order WooCommerce order.
	 * @param string   $oid   AinePay order id.
	 * @return string One of the CANCEL_* outcome constants.
	 */
	private static function reconcile_via_query( $order, $oid ) {
		$confirmed = self::query_status( $oid );
		$status    = is_array( $confirmed ) && isset( $confirmed['status'] ) ? strtoupper( (string) $confirmed['status'] ) : '';
		if ( ! in_array( $status, array( 'PAID', 'CANCEL', 'EXPIRED', 'REFUND', 'PENDING' ), true ) ) {
			// Unknown / empty / mismatched: keep the cancel intent and retry.
			self::schedule_cancel_sync( $order, $oid );
			return self::CANCEL_RETRY;
		}
		self::apply_status( $order, $confirmed, array() );
		self::clear_cancel_pending( $order );
		if ( 'PAID' === $status ) {
			return self::CANCEL_PAID;
		}
		if ( 'PENDING' === $status ) {
			return self::CANCEL_PENDING;
		}
		if ( 'CANCEL' === $status ) {
			return self::CANCEL_DONE;
		}
		// EXPIRED / REFUND: terminal and consistent, but NOT a cancellation — report
		// distinctly so the UI does not claim the order was "cancelled".
		return self::CANCEL_RECONCILED;
	}

	/**
	 * Safety net for woocommerce_order_status_cancelled: when an AinePay order is
	 * moved to cancelled by any path that has not already confirmed a backend
	 * CANCEL, drive the backend cancel + reconciliation. WC is already cancelled
	 * here, so this cannot cancel-first; the matrix repairs the order should the
	 * backend turn out to be PAID/PENDING instead.
	 *
	 * Deliberately does NOT reconcile synchronously: this runs INSIDE the cancelled
	 * status transition, whose stock restock is still in flight, and nesting an
	 * update_status() back to processing/on-hold from here would make stock
	 * correctness depend on hook ordering and the order cache. Instead it enqueues
	 * an immediate async reconcile that runs in its own request, after this
	 * transition (and its restock) has fully committed, so a really-PAID order is
	 * repaired and its stock re-reduced cleanly and deterministically.
	 *
	 * @param int $order_id WooCommerce order id.
	 * @return void
	 */
	public static function on_wc_cancelled( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order || Ainepay_Plugin::GATEWAY_ID !== $order->get_payment_method() ) {
			return;
		}
		$oid = (string) $order->get_meta( '_ainepay_order_id' );
		if ( '' === $oid ) {
			return;
		}
		// Already backed by a confirmed backend CANCEL (e.g. this transition was
		// driven by our own reconciliation): nothing to do, avoid a retry loop.
		if ( 'CANCEL' === strtoupper( (string) $order->get_meta( '_ainepay_status' ) ) ) {
			return;
		}
		self::enqueue_cancel_sync_now( $order, $oid );
	}

	/**
	 * Enqueue an immediate (zero-delay) cancel-sync reconcile in a separate request,
	 * without consuming the backoff retry budget. Used by the cancelled-status
	 * safety net so reconciliation never nests inside the transition hook.
	 *
	 * Persists the cancel intent (`_ainepay_cancel_pending`) on success so the
	 * intent is recoverable if the action is later dropped. If the immediate enqueue
	 * itself fails, this must NEVER silently strand the cancel: it falls back to the
	 * backoff-scheduled retry, which persists the pending intent and fails loud
	 * (alert_cancel_stuck) should it also be unable to schedule. The poller
	 * additionally re-drives any unbacked WC=cancelled order, so a dropped action is
	 * recovered there as well.
	 *
	 * @param WC_Order $order WooCommerce order.
	 * @param string   $oid   AinePay order id.
	 * @return void
	 */
	private static function enqueue_cancel_sync_now( $order, $oid ) {
		if ( self::ensure_async( self::CANCEL_SYNC_HOOK, $oid, 0 ) ) {
			$order->update_meta_data( '_ainepay_cancel_pending', '1' );
			$order->save();
			return;
		}
		Ainepay_Logger::error( 'Could not enqueue immediate cancel reconcile; falling back to scheduled retry', array( 'orderId' => $oid ) );
		self::schedule_cancel_sync( $order, $oid );
	}

	/**
	 * Schedule (or async-enqueue, when $delay is 0) a background action exactly once
	 * per outstanding (hook, orderId): the single source of truth for all cancel /
	 * paid-verify / refund-verify scheduling.
	 *
	 * De-duplication uses Action Scheduler's `$unique` flag, which rejects a new
	 * action only when a matching PENDING one already exists — so repeated triggers
	 * (double-click, safety net + transient retry) never fork into parallel chains,
	 * yet a worker that is currently in-progress can still schedule its OWN next
	 * attempt (an in-progress action is not "pending"). Older Action Scheduler
	 * builds ignore the extra argument (degrading to today's no-dedup, never an
	 * error). The wp-cron fallback de-dupes itself via wp_next_scheduled() because
	 * wp_schedule_single_event() otherwise silently drops a duplicate within ~10
	 * minutes and returns false.
	 *
	 * Returns whether an action is now scheduled (or was already), so callers can
	 * fail loud instead of believing a dropped schedule succeeded.
	 *
	 * @param string $hook  Action hook.
	 * @param string $oid   AinePay order id (the sole action argument).
	 * @param int    $delay Seconds from now; 0 means enqueue an immediate async action.
	 * @return bool
	 */
	private static function ensure_async( $hook, $oid, $delay ) {
		$args  = array( (string) $oid );
		$group = self::CANCEL_GROUP;
		$delay = max( 0, (int) $delay );

		if ( 0 === $delay && function_exists( 'as_enqueue_async_action' ) ) {
			$id = (int) as_enqueue_async_action( $hook, $args, $group, true );
			return $id > 0 ? true : self::has_pending_action( $hook, $args, $group );
		}
		if ( function_exists( 'as_schedule_single_action' ) ) {
			$id = (int) as_schedule_single_action( time() + $delay, $hook, $args, $group, true );
			// A 0 id means either "$unique rejected a duplicate" (a matching action
			// already exists — fine) or a genuine store failure. Only the latter is a
			// problem, so confirm against the queue before reporting failure.
			return $id > 0 ? true : self::has_pending_action( $hook, $args, $group );
		}
		if ( function_exists( 'wp_schedule_single_event' ) ) {
			if ( function_exists( 'wp_next_scheduled' ) && false !== wp_next_scheduled( $hook, $args ) ) {
				return true; // Already pending; wp-cron would refuse the duplicate anyway.
			}
			return false !== wp_schedule_single_event( time() + $delay, $hook, $args );
		}
		return false;
	}

	/**
	 * Whether a matching Action Scheduler action is already pending or in-progress.
	 * Used only to tell a $unique-dedup (success) apart from a real scheduling
	 * failure; never to decide whether to schedule (that would let an in-progress
	 * worker suppress its own next attempt).
	 *
	 * @param string $hook  Action hook.
	 * @param array  $args  Action arguments.
	 * @param string $group Action group.
	 * @return bool
	 */
	private static function has_pending_action( $hook, $args, $group ) {
		return function_exists( 'as_has_scheduled_action' ) && as_has_scheduled_action( $hook, $args, $group );
	}

	/**
	 * Enforce the governing invariant: an AinePay order may only sit in a
	 * success state (processing/completed) when it is backed by an authoritative
	 * PAID. Hooked on woocommerce_order_status_changed so that ANY path — admin,
	 * REST, ERP, another plugin — that promotes an AinePay order to a success state
	 * without that backing is caught.
	 *
	 * Detection is cheap and synchronous (a meta read); the authoritative check is
	 * deliberately deferred to an async worker. This hook runs in the order-save
	 * request, so it must NOT make a blocking HTTP call (it would hang the admin),
	 * and a backend outage must never trap a deliberate manual promotion. So here
	 * we only note + flag + enqueue; verify_paid_invariant() does the query and
	 * reverts ONLY when the backend successfully confirms the order is not paid.
	 *
	 * Legitimate promotions (process_payment's create-as-PAID branch and
	 * mark_order_paid via reconciliation) set `_ainepay_status=PAID` first, so they
	 * short-circuit here with no note, no flag and no async work.
	 *
	 * @param int      $order_id WooCommerce order id.
	 * @param string   $from     Previous status.
	 * @param string   $to       New status.
	 * @param WC_Order $order    WooCommerce order.
	 * @return void
	 */
	public static function guard_paid_invariant( $order_id, $from, $to, $order ) {
		if ( ! in_array( $to, array( 'processing', 'completed' ), true ) ) {
			return;
		}
		if ( ! ( $order instanceof WC_Order ) || Ainepay_Plugin::GATEWAY_ID !== $order->get_payment_method() ) {
			return;
		}
		// Already backed by an authoritative PAID: legitimate, nothing to do.
		if ( 'PAID' === strtoupper( (string) $order->get_meta( '_ainepay_status' ) ) ) {
			return;
		}
		if ( ! apply_filters( 'ainepay_enforce_paid_invariant', true, $order ) ) {
			return;
		}

		$oid = (string) $order->get_meta( '_ainepay_order_id' );

		// No AinePay order id at all: the order was never created at AinePay, so it
		// cannot have been paid and there is nothing to query. This is the most
		// dangerous unbacked promotion (a manually created/edited AinePay order, or a
		// checkout whose order-creation call failed), and it must NOT be silently
		// skipped — doing so previously let it bypass both verification and the
		// fulfilment gates. There is no backend to confirm "not paid" against, and no
		// concurrent webhook/poller writer (both skip order-id-less orders), so the
		// result is deterministic: revert to on-hold now. The trailing on-hold
		// transition re-enters this guard with $to='on-hold' and returns immediately,
		// so there is no loop. on-hold keeps the same stock reservation as processing,
		// so no stock side effect occurs here.
		if ( '' === $oid ) {
			Ainepay_Logger::error(
				'Unbacked success transition with no AinePay order id; reverting to on-hold',
				array(
					'order_id' => $order_id,
					'from'     => $from,
					'to'       => $to,
				)
			);
			if ( $order->has_status( array( 'processing', 'completed' ) ) ) {
				$order->update_status(
					'on-hold',
					__( 'Order reverted to on-hold: this is an AinePay order with no AinePay payment on record, so it must not be treated as paid.', 'ainepay-for-woocommerce' )
				);
			}
			return;
		}

		// Unbacked promotion. Flag + note synchronously (cheap), then verify out of
		// band. No HTTP here: do not block the save or brick a manual override.
		$order->add_order_note( __( 'This AinePay order was moved to a paid state without a confirmed AinePay payment; verifying with AinePay…', 'ainepay-for-woocommerce' ) );
		Ainepay_Logger::error(
			'Unbacked success transition; scheduling async verification',
			array(
				'orderId' => $oid,
				'from'    => $from,
				'to'      => $to,
			)
		);
		self::enqueue_paid_verify( $order, $oid );
	}

	/**
	 * Async worker that verifies an unbacked success-state promotion against the
	 * backend and reverts the order to on-hold only when AinePay successfully
	 * confirms it is NOT paid. Runs in its own request (no blocking on the save
	 * path, no nested status change inside a transition hook).
	 *
	 * Availability rule: if the backend cannot be reached, the order is LEFT AS-IS
	 * — an outage must never trap a deliberate manual promotion. A genuinely unpaid
	 * order is corrected on a later verification/poll once the backend is reachable.
	 *
	 * @param string $oid AinePay order id.
	 * @return void
	 */
	public static function verify_paid_invariant( $oid ) {
		$oid = (string) $oid;
		if ( '' === $oid ) {
			return;
		}
		$order = self::find_order( $oid );
		if ( ! $order ) {
			return;
		}
		if ( ! $order->has_status( array( 'processing', 'completed' ) ) ) {
			self::clear_paid_verify( $order ); // Left the success state: resolved.
			return;
		}
		if ( 'PAID' === strtoupper( (string) $order->get_meta( '_ainepay_status' ) ) ) {
			self::clear_paid_verify( $order ); // Became backed in the meantime.
			return;
		}

		$confirmed = self::query_status( $oid );
		if ( null === $confirmed ) {
			// Backend unreachable: do NOT revert (never brick a deliberate manual
			// promotion during an outage), but do NOT give up either — a single
			// outage must not leave the order permanently unverified-but-paid. Retry
			// on the backoff ladder; escalate to a manual-review alert at the cap.
			Ainepay_Logger::error( 'Paid-invariant verify could not reach backend; scheduling retry', array( 'orderId' => $oid ) );
			self::schedule_paid_verify( $order, $oid );
			return;
		}
		$status = strtoupper( isset( $confirmed['status'] ) ? (string) $confirmed['status'] : '' );
		if ( 'PAID' === $status ) {
			// Genuinely paid: back it via the normal atomic path (sets meta=PAID).
			// handle_notification re-queries inside its own lock and can fail (BUSY/
			// RETRY) without writing meta=PAID. Only treat the verify as resolved when
			// it actually applied; otherwise keep verifying so we don't prematurely
			// abandon an order that is still unbacked.
			$result = self::handle_notification( array( 'orderId' => $oid ) );
			$fresh  = self::find_order( $oid );
			if ( $fresh && self::RESULT_OK === $result
				&& 'PAID' === strtoupper( (string) $fresh->get_meta( '_ainepay_status' ) ) ) {
				self::clear_paid_verify( $fresh );
			} elseif ( $fresh ) {
				Ainepay_Logger::error(
					'Paid-invariant verify saw PAID but could not back the order; scheduling retry',
					array(
						'orderId' => $oid,
						'result'  => $result,
					)
				);
				self::schedule_paid_verify( $fresh, $oid );
			}
			return;
		}

		// Backend confirmed NOT paid: protect the merchant — revert to on-hold.
		$lock = self::acquire_lock( $oid );
		if ( ! $lock ) {
			self::enqueue_paid_verify( $order, $oid ); // Busy (brief local contention); try again shortly.
			return;
		}
		try {
			$fresh = self::find_order( $oid );
			if ( $fresh
				&& $fresh->has_status( array( 'processing', 'completed' ) )
				&& 'PAID' !== strtoupper( (string) $fresh->get_meta( '_ainepay_status' ) ) ) {
				$fresh->update_status(
					'on-hold',
					__( 'Order reverted to on-hold: AinePay confirmed this order is not paid, so it must not be treated as paid.', 'ainepay-for-woocommerce' )
				);
				Ainepay_Logger::error(
					'Reverted unbacked success transition (async-verified not paid)',
					array(
						'orderId' => $oid,
						'backend' => $status,
					)
				);
			}
			if ( $fresh ) {
				self::clear_paid_verify( $fresh );
			}
		} finally {
			self::release_lock( $lock );
		}
	}

	/**
	 * Enqueue an immediate async paid-invariant verification in a separate request.
	 * On enqueue failure the intent must NOT be silently dropped: fall back to the
	 * persistent backoff schedule so an unbacked promotion is always verified (and
	 * escalated to a manual-review alert at the cap) rather than left unverified.
	 *
	 * @param WC_Order $order WooCommerce order.
	 * @param string   $oid   AinePay order id.
	 * @return void
	 */
	private static function enqueue_paid_verify( $order, $oid ) {
		if ( self::ensure_async( self::VERIFY_PAID_HOOK, $oid, 0 ) ) {
			return;
		}
		Ainepay_Logger::error( 'Could not enqueue immediate paid-invariant verify; falling back to scheduled retry', array( 'orderId' => $oid ) );
		self::schedule_paid_verify( $order, $oid );
	}

	/**
	 * Schedule the next paid-invariant verification with the backoff ladder, or
	 * escalate to a manual-review alert once the attempt cap is reached. Used when
	 * the backend is unreachable: the order is left in its (unbacked) paid state —
	 * never auto-reverted, to avoid bricking a deliberate manual promotion during an
	 * outage — but it is kept under verification so a transient outage can never
	 * leave it permanently unverified. The fail-closed email/download gates protect
	 * the customer-facing surface throughout this window.
	 *
	 * @param WC_Order $order WooCommerce order.
	 * @param string   $oid   AinePay order id.
	 * @return void
	 */
	private static function schedule_paid_verify( $order, $oid ) {
		$attempts = (int) $order->get_meta( '_ainepay_paid_verify_attempts' );
		if ( $attempts >= self::PAID_VERIFY_MAX_ATTEMPTS ) {
			$order->update_meta_data( '_ainepay_paid_verify_failed', '1' );
			$order->add_order_note( __( 'AinePay could not be reached to verify this order’s payment after repeated attempts. It remains in a paid state but is UNVERIFIED — review it manually.', 'ainepay-for-woocommerce' ) );
			$order->save();
			self::alert_paid_unverified( $order, $oid );
			return;
		}
		++$attempts;
		$order->update_meta_data( '_ainepay_paid_verify_attempts', $attempts );
		$order->save();

		$delay = self::cancel_backoff_seconds( $attempts );
		if ( ! self::ensure_async( self::VERIFY_PAID_HOOK, $oid, $delay ) ) {
			Ainepay_Logger::error( 'Could not schedule paid-invariant verify retry', array( 'orderId' => $oid ) );
			$order->update_meta_data( '_ainepay_paid_verify_failed', '1' );
			$order->add_order_note( __( 'Could not schedule the AinePay payment verification; this order is in a paid state but unverified — review it manually.', 'ainepay-for-woocommerce' ) );
			$order->save();
			self::alert_paid_unverified( $order, $oid );
		}
	}

	/**
	 * Clear the paid-verify bookkeeping once the order resolves (became backed,
	 * reverted, or left the success state). No-op when there is nothing to clear.
	 *
	 * @param WC_Order $order WooCommerce order.
	 * @return void
	 */
	private static function clear_paid_verify( $order ) {
		if ( '' === (string) $order->get_meta( '_ainepay_paid_verify_attempts' )
			&& '' === (string) $order->get_meta( '_ainepay_paid_verify_failed' ) ) {
			return;
		}
		$order->delete_meta_data( '_ainepay_paid_verify_failed' );
		if ( '' !== (string) $order->get_meta( '_ainepay_paid_verify_attempts' ) ) {
			$order->update_meta_data( '_ainepay_paid_verify_attempts', 0 );
		}
		$order->save();
	}

	/**
	 * Raise an alert when an unbacked success-state order cannot be verified against
	 * AinePay within the grace window. Mirrors alert_cancel_stuck(): a dashboard
	 * transient plus the `ainepay_paid_unverified` action for active alerting. Does
	 * NOT revert the order (see schedule_paid_verify).
	 *
	 * @param WC_Order $order WooCommerce order.
	 * @param string   $oid   AinePay order id.
	 * @return void
	 */
	private static function alert_paid_unverified( $order, $oid ) {
		Ainepay_Logger::error( 'Paid-invariant unverified after grace window; manual attention required.', array( 'orderId' => $oid ) );
		set_transient( 'ainepay_paid_unverified_' . $order->get_id(), $oid, WEEK_IN_SECONDS );
		do_action( 'ainepay_paid_unverified', $order, $oid );
	}

	/**
	 * Whether an order is an AinePay order NOT (yet) backed by an authoritative
	 * PAID. WooCommerce fires fulfilment side effects (stock, customer emails,
	 * downloads) on the success transition BEFORE guard_paid_invariant runs (it is
	 * on the later woocommerce_order_status_changed hook), so those side effects are
	 * gated fail-closed on this predicate to avoid telling a customer an unpaid
	 * order succeeded. Legitimate paid promotions set `_ainepay_status=PAID` first,
	 * so they are never gated.
	 *
	 * A missing `_ainepay_order_id` does NOT make the order "safe": an AinePay-method
	 * order in a success state with no AinePay order on record (a manually created/
	 * edited order, or a checkout whose order-creation call failed) was certainly not
	 * paid, so it is the MOST important case to gate. Only an authoritative
	 * `_ainepay_status=PAID` clears the gate.
	 *
	 * @param mixed $order Expected WC_Order; other types return false.
	 * @return bool
	 */
	private static function is_unbacked_ainepay_order( $order ) {
		if ( ! ( $order instanceof WC_Order ) ) {
			return false;
		}
		if ( Ainepay_Plugin::GATEWAY_ID !== $order->get_payment_method() ) {
			return false;
		}
		if ( 'PAID' === strtoupper( (string) $order->get_meta( '_ainepay_status' ) ) ) {
			return false;
		}
		return (bool) apply_filters( 'ainepay_enforce_paid_invariant', true, $order );
	}

	/**
	 * Filter (woocommerce_email_enabled_customer_processing_order /
	 * _customer_completed_order): suppress the "order received/complete" customer
	 * email for an AinePay order AinePay has not confirmed paid. This fires
	 * during the transition, before the guard can revert, so it must gate here.
	 *
	 * @param bool  $enabled Whether the email is enabled.
	 * @param mixed $object  The email object (usually a WC_Order).
	 * @return bool
	 */
	public static function gate_unbacked_email( $enabled, $object ) {
		if ( $enabled && self::is_unbacked_ainepay_order( $object ) ) {
			return false;
		}
		return $enabled;
	}

	/**
	 * Filter (woocommerce_order_is_download_permitted): deny downloadable-product
	 * access for an AinePay order that is not yet confirmed paid.
	 *
	 * @param bool     $permitted Whether downloads are permitted.
	 * @param WC_Order $order     WooCommerce order.
	 * @return bool
	 */
	public static function gate_unbacked_download( $permitted, $order ) {
		if ( $permitted && self::is_unbacked_ainepay_order( $order ) ) {
			return false;
		}
		return $permitted;
	}

	/**
	 * Filter (woocommerce_can_restore_order_stock): fail-closed gate that holds back
	 * WC's stock restore on a still-unconfirmed AinePay cancellation.
	 *
	 * The dedicated cancel button is cancel-first (the backend confirms CANCEL while
	 * the order is still on-hold), but a NATIVE WooCommerce admin/bulk "Cancelled"
	 * status change marks WC cancelled — and restocks — before the backend is asked.
	 * If that order was actually settling/PAID, releasing stock here opens an
	 * oversell window until the async reconcile repairs it. So while the order is
	 * cancelled in WC but NOT yet backed by an authoritative CANCEL, we keep the
	 * stock reserved. Once apply_status() records `_ainepay_status=CANCEL` the gate
	 * opens (and release_held_stock() restores stock for the already-cancelled
	 * case); a settle race that repairs the order to PAID keeps its reserved stock,
	 * which payment_complete() de-duplicates via `_order_stock_reduced`.
	 *
	 * Scoped to the cancelled status only, so EXPIRED->failed and REFUND->refunded
	 * restores (already authoritative) are never affected.
	 *
	 * @param bool  $can_restore Whether WC may restore stock.
	 * @param mixed $order       Expected WC_Order.
	 * @return bool
	 */
	public static function gate_premature_restock( $can_restore, $order ) {
		if ( ! $can_restore || ! ( $order instanceof WC_Order ) ) {
			return $can_restore;
		}
		if ( Ainepay_Plugin::GATEWAY_ID !== $order->get_payment_method() ) {
			return $can_restore;
		}
		if ( '' === (string) $order->get_meta( '_ainepay_order_id' ) ) {
			return $can_restore;
		}
		if ( ! apply_filters( 'ainepay_enforce_paid_invariant', true, $order ) ) {
			return $can_restore;
		}
		if ( $order->has_status( 'cancelled' )
			&& 'CANCEL' !== strtoupper( (string) $order->get_meta( '_ainepay_status' ) ) ) {
			return false;
		}
		return $can_restore;
	}

	/**
	 * Action (woocommerce_order_status_cancelled, priority 20): re-assert the
	 * order's stock-reduced marker after WC core has cleared it on a cancel whose
	 * physical restore gate_premature_restock() blocked.
	 *
	 * WC's wc_maybe_increase_stock_levels (priority 10) calls wc_increase_stock_levels()
	 * — which our gate short-circuits, so the items stay physically reduced — but then,
	 * when the marker was set, runs set_stock_reduced( false ). That desyncs the order
	 * marker from reality: the stock is still reduced, yet the marker says otherwise,
	 * which would make release_held_stock() skip the later restore and leak the
	 * reservation. Runs at priority 20 so it always follows WC's clear.
	 *
	 * Re-asserts only when BOTH the gate is holding this cancel AND the stock is still
	 * physically reduced (some line item still carries `_reduced_stock`). The physical
	 * check is essential: an order legitimately restocked before this cancel (e.g. an
	 * admin on-hold -> pending transition, whose wc_maybe_increase_stock_levels the gate
	 * does NOT block because it is scoped to the cancelled status) has no reduced items,
	 * and re-marking it reduced would make a later PAID repair skip re-reduction and
	 * oversell.
	 *
	 * @param int $order_id WooCommerce order id.
	 * @return void
	 */
	public static function reassert_held_stock_marker( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order instanceof WC_Order ) {
			return;
		}
		// gate_premature_restock() returns false only while it is holding this cancel;
		// that is exactly when WC blocked the physical restore but cleared the marker.
		if ( false !== self::gate_premature_restock( true, $order ) ) {
			return;
		}
		if ( ! self::order_stock_is_physically_reduced( $order ) ) {
			return;
		}
		$order->get_data_store()->set_stock_reduced( $order, true );
	}

	/**
	 * Ground truth for whether an order's stock is still physically reduced: WooCommerce
	 * stamps `_reduced_stock` on each line item when it reduces stock and deletes that
	 * meta when it restores the item, so a surviving `_reduced_stock` means the units are
	 * still held. Mirrors the line-item scoping WC uses in wc_increase_stock_levels().
	 *
	 * @param WC_Order $order WooCommerce order.
	 * @return bool
	 */
	private static function order_stock_is_physically_reduced( $order ) {
		foreach ( $order->get_items() as $item ) {
			if ( is_callable( array( $item, 'is_type' ) ) && ! $item->is_type( 'line_item' ) ) {
				continue;
			}
			// Float compare, not int: WC types this value int|float (wc_stock_amount()
			// lets the woocommerce_stock_amount filter return floats) and its own
			// restore path treats any truthy value as reduced, so an (int) cast would
			// misread a fractional quantity like 0.5 as "not reduced".
			if ( (float) $item->get_meta( '_reduced_stock', true ) > 0 ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Release the stock that gate_premature_restock() held back, once a native/bulk
	 * cancel is confirmed CANCEL by the backend. The order is already in the
	 * cancelled status here, so WC's own restock transition will not fire again.
	 * No-op unless stock is currently reduced, so it is safe to call more than once.
	 *
	 * `_order_stock_reduced` is an internal WooCommerce datastore prop, so it must be
	 * read/written through the data store (get_meta() routes it through a getter that
	 * returns a bool, not the legacy 'yes' string, and behaves differently under HPOS).
	 *
	 * @param WC_Order $order WooCommerce order.
	 * @return void
	 */
	private static function release_held_stock( $order ) {
		$store = $order->get_data_store();
		if ( ! $store->get_stock_reduced( $order ) ) {
			return;
		}
		if ( function_exists( 'wc_increase_stock_levels' ) ) {
			wc_increase_stock_levels( $order );
		}
		// Clear the marker (passing the order object so the in-memory copy is updated
		// too) to match the restored stock, so the trailing apply_status() save() does
		// not re-persist a reduced flag.
		$store->set_stock_reduced( $order, false );
	}

	/**
	 * Action Scheduler worker for persistent cancel retries.
	 *
	 * @param string $ainepay_order_id AinePay order id.
	 * @return void
	 */
	public static function handle_cancel_sync( $ainepay_order_id ) {
		$ainepay_order_id = (string) $ainepay_order_id;
		if ( '' === $ainepay_order_id ) {
			return;
		}
		$order = self::find_order( $ainepay_order_id );
		if ( ! $order ) {
			return;
		}
		// Already reconciled to a backed terminal state: stop retrying.
		if ( self::is_settled_and_backed( $order ) ) {
			self::clear_cancel_pending( $order );
			return;
		}
		self::request_cancel( $order, 'retry' );
	}

	/**
	 * Schedule the next cancel retry with exponential backoff, or give up to manual
	 * handling once the attempt cap is reached. Never marks the order cancelled.
	 *
	 * @param WC_Order $order WooCommerce order.
	 * @param string   $oid   AinePay order id.
	 * @return void
	 */
	private static function schedule_cancel_sync( $order, $oid ) {
		$attempts = (int) $order->get_meta( '_ainepay_cancel_attempts' );
		if ( $attempts >= self::CANCEL_MAX_ATTEMPTS ) {
			$order->update_meta_data( '_ainepay_cancel_failed', '1' );
			$order->add_order_note( __( 'AinePay cancel could not be confirmed after repeated retries; manual reconciliation required. Order kept on-hold.', 'ainepay-for-woocommerce' ) );
			$order->save();
			self::alert_cancel_stuck( $order, $oid );
			return;
		}
		++$attempts;
		$order->update_meta_data( '_ainepay_cancel_attempts', $attempts );
		$order->update_meta_data( '_ainepay_cancel_pending', '1' );
		$order->save();

		$delay = self::cancel_backoff_seconds( $attempts );
		if ( ! self::ensure_async( self::CANCEL_SYNC_HOOK, $oid, $delay ) ) {
			// A dropped schedule would silently strand the cancel (the poller mirrors
			// backend status, it does not re-drive a cancel). Fail loud instead.
			Ainepay_Logger::error( 'Could not schedule cancel retry', array( 'orderId' => $oid ) );
			$order->update_meta_data( '_ainepay_cancel_failed', '1' );
			$order->add_order_note( __( 'Could not schedule the AinePay cancel retry; this order needs manual reconciliation. Order kept on-hold.', 'ainepay-for-woocommerce' ) );
			$order->save();
			self::alert_cancel_stuck( $order, $oid );
		}
	}

	/**
	 * Exponential backoff ladder (seconds), capped at 6h.
	 *
	 * @param int $attempt 1-based attempt number.
	 * @return int
	 */
	private static function cancel_backoff_seconds( $attempt ) {
		$ladder = array(
			1 => MINUTE_IN_SECONDS,
			2 => 5 * MINUTE_IN_SECONDS,
			3 => 15 * MINUTE_IN_SECONDS,
			4 => HOUR_IN_SECONDS,
		);
		return isset( $ladder[ $attempt ] ) ? $ladder[ $attempt ] : 6 * HOUR_IN_SECONDS;
	}

	/**
	 * Clear the pending-cancel bookkeeping once an order reconciles to a terminal
	 * (or PENDING) state, so a future legitimate flow starts clean. Also clears any
	 * prior `_ainepay_cancel_failed` flag: reaching an authoritative resolution means
	 * the earlier failure (permanent error, retry-cap or schedule drop) has been
	 * overcome, so a stale flag must not keep an already-reconciled order surfaced as
	 * "cancel failed" in admin UI/alerts.
	 *
	 * @param WC_Order $order WooCommerce order.
	 * @return void
	 */
	private static function clear_cancel_pending( $order ) {
		$order->delete_meta_data( '_ainepay_cancel_pending' );
		$order->delete_meta_data( '_ainepay_cancel_failed' );
		if ( '' !== (string) $order->get_meta( '_ainepay_cancel_attempts' ) ) {
			$order->update_meta_data( '_ainepay_cancel_attempts', 0 );
		}
		$order->save();
	}

	/**
	 * Raise an alert when a cancel is stuck after the retry cap. Records an order
	 * note (already done by the caller) plus a dashboard transient, and fires the
	 * `ainepay_cancel_stuck` action so a site can wire active alerting (email/IM)
	 * without this plugin hardcoding a channel.
	 *
	 * @param WC_Order $order WooCommerce order.
	 * @param string   $oid   AinePay order id.
	 * @return void
	 */
	private static function alert_cancel_stuck( $order, $oid ) {
		Ainepay_Logger::error( 'Cancel stuck after max retries; manual attention required.', array( 'orderId' => $oid ) );
		set_transient( 'ainepay_cancel_stuck_' . $order->get_id(), $oid, WEEK_IN_SECONDS );
		do_action( 'ainepay_cancel_stuck', $order, $oid );
	}

	/* --- manual refund closure (full refunds only) ------------------------ */

	/**
	 * Close the loop on the manual two-step refund (req: full refunds only). The
	 * gateway does not implement process_refund(), so a merchant refunds the order
	 * in WooCommerce first and then issues the matching refund in the AinePay
	 * dashboard. WC alone cannot tell whether that second step happened, so when an
	 * AinePay order is fully refunded in WC we flag it and verify out of band that
	 * AinePay reaches REFUND, alerting if it never does.
	 *
	 * Hooked on woocommerce_order_fully_refunded so partial refunds (out of scope)
	 * are ignored. A backend-driven REFUND (apply_status) uses update_status() and
	 * does NOT create a WC refund object, so it never re-enters here.
	 *
	 * @param int $order_id  WooCommerce order id.
	 * @param int $refund_id WC refund id (unused).
	 * @return void
	 */
	public static function on_wc_fully_refunded( $order_id, $refund_id = 0 ) {
		$order = wc_get_order( $order_id );
		if ( ! $order || Ainepay_Plugin::GATEWAY_ID !== $order->get_payment_method() ) {
			return;
		}
		$oid = (string) $order->get_meta( '_ainepay_order_id' );
		if ( '' === $oid ) {
			return;
		}
		if ( ! apply_filters( 'ainepay_track_manual_refund', true, $order ) ) {
			return;
		}
		// Already backed by an authoritative REFUND (e.g. the refund originated at
		// AinePay and we mirrored it): nothing to verify.
		$ainepay_status = strtoupper( (string) $order->get_meta( '_ainepay_status' ) );
		if ( 'REFUND' === $ainepay_status ) {
			return;
		}
		// Only a PAID-backed order can ever reach REFUND at AinePay. Refunding a
		// never-paid order in WC (INIT/PENDING/EXPIRED/CANCEL, or no AinePay status
		// at all) would otherwise start a verify chain that can never converge and,
		// once the merchant budget is exhausted, fire a false ainepay_refund_stuck
		// alert. Record a note instead so the timeline still reflects the WC refund.
		// Note: at this hook the order is already WC-refunded but the plugin meta is
		// untouched, so a genuinely paid order still reads PAID here.
		if ( 'PAID' !== $ainepay_status ) {
			$order->add_order_note( __( 'Order refunded in WooCommerce, but AinePay never confirmed this order as paid, so there is no AinePay payment to refund. No AinePay refund verification was started.', 'ainepay-for-woocommerce' ) );
			$order->save();
			return;
		}
		$order->update_meta_data( '_ainepay_refund_pending', '1' );
		$order->add_order_note( __( 'Order fully refunded in WooCommerce. Issue the matching refund in the AinePay dashboard; AinePay will confirm it shortly.', 'ainepay-for-woocommerce' ) );
		$order->save();
		self::schedule_refund_verify( $order, $oid, 'initial' );
	}

	/**
	 * Warn on a partial refund: AinePay refunds are full-refund only. WC lets a
	 * merchant record a partial refund (manual refund, since the gateway declares
	 * no `refunds` support), but there is no partial-refund path at AinePay, so a
	 * partial WC refund can never reconcile to an authoritative REFUND. Rather than
	 * silently leave WC and AinePay out of sync — or start a verify chain that can
	 * never converge and would falsely alert as "refund stuck" — record a one-time
	 * order note telling the merchant this is unsupported. No verification is
	 * scheduled and no refund-pending flag is set.
	 *
	 * Hooked on woocommerce_order_partially_refunded. A subsequent partial refund
	 * that finally makes the order fully refunded fires woocommerce_order_fully_
	 * refunded as well, which then starts the normal full-refund closure.
	 *
	 * @param int $order_id  WooCommerce order id.
	 * @param int $refund_id WC refund id (unused).
	 * @return void
	 */
	public static function on_wc_partially_refunded( $order_id, $refund_id = 0 ) {
		$order = wc_get_order( $order_id );
		if ( ! $order || Ainepay_Plugin::GATEWAY_ID !== $order->get_payment_method() ) {
			return;
		}
		if ( '' === (string) $order->get_meta( '_ainepay_order_id' ) ) {
			return;
		}
		// Note once: repeated partial refunds would otherwise spam the timeline with
		// the same warning.
		if ( '1' === (string) $order->get_meta( '_ainepay_partial_refund_noted' ) ) {
			return;
		}
		$order->update_meta_data( '_ainepay_partial_refund_noted', '1' );
		$order->add_order_note( __( 'A partial refund was recorded in WooCommerce, but AinePay supports full refunds only — there is no matching partial refund at AinePay. WooCommerce and AinePay will not reconcile for this partial amount; refund the full order if an AinePay refund is required.', 'ainepay-for-woocommerce' ) );
		$order->save();
	}

	/**
	 * Action Scheduler worker: verify that a WC-refunded order has reached REFUND at
	 * AinePay, and keep the verification alive until it does (or escalate). Never
	 * touches WC funds; it only confirms the backend and clears the pending flag.
	 *
	 * @param string $oid AinePay order id.
	 * @return void
	 */
	public static function verify_refund( $oid ) {
		$oid = (string) $oid;
		if ( '' === $oid ) {
			return;
		}
		$order = self::find_order( $oid );
		if ( ! $order ) {
			return;
		}
		// Resolved already (flag cleared, status changed, or backend confirmed).
		if ( '1' !== (string) $order->get_meta( '_ainepay_refund_pending' ) ) {
			return;
		}
		if ( 'REFUND' === strtoupper( (string) $order->get_meta( '_ainepay_status' ) ) ) {
			self::clear_refund_pending( $order );
			return;
		}

		$confirmed = self::query_status( $oid );
		if ( null === $confirmed ) {
			// Backend outage has its own retry budget. It must never consume the
			// merchant's grace budget for completing the manual AinePay refund.
			self::schedule_refund_verify( $order, $oid, 'outage' );
			return;
		}
		self::clear_refund_outage( $order );
		$status = strtoupper( isset( $confirmed['status'] ) ? (string) $confirmed['status'] : '' );
		if ( 'REFUND' === $status ) {
			// Confirmed: reconcile via the shared path, which records meta=REFUND and
			// clears the pending flag in the REFUND branch.
			self::handle_notification( array( 'orderId' => $oid ) );
			return;
		}

		// Still not refunded at AinePay (merchant has not issued it yet, or it
		// failed): consume only the merchant-wait budget. A successful backend
		// observation, not elapsed outage time, advances this counter.
		self::schedule_refund_verify( $order, $oid, 'merchant' );
	}

	/**
	 * Schedule the next refund verification using independent budgets:
	 * - merchant: backend was reachable but still not REFUND;
	 * - outage: backend could not be queried;
	 * - initial: first schedule, consumes neither budget.
	 *
	 * @param WC_Order $order WooCommerce order.
	 * @param string   $oid   AinePay order id.
	 * @param string   $reason One of initial|merchant|outage.
	 * @return void
	 */
	private static function schedule_refund_verify( $order, $oid, $reason ) {
		$attempts = 0;
		if ( 'merchant' === $reason ) {
			$attempts = (int) $order->get_meta( '_ainepay_refund_merchant_attempts' );
			if ( $attempts >= self::REFUND_MAX_ATTEMPTS ) {
				$order->update_meta_data( '_ainepay_refund_failed', '1' );
				$order->add_order_note( __( 'AinePay refund is still not complete after the merchant grace period; verify it in the AinePay dashboard. (WooCommerce already shows this order refunded.)', 'ainepay-for-woocommerce' ) );
				$order->save();
				self::alert_refund_stuck( $order, $oid );
				return;
			}
			++$attempts;
			$order->update_meta_data( '_ainepay_refund_merchant_attempts', $attempts );
		} elseif ( 'outage' === $reason ) {
			$attempts = (int) $order->get_meta( '_ainepay_refund_outage_attempts' );
			if ( $attempts >= self::REFUND_OUTAGE_MAX_ATTEMPTS ) {
				$order->update_meta_data( '_ainepay_refund_outage_failed', '1' );
				$order->add_order_note( __( 'AinePay could not be reached after repeated refund verification attempts. Merchant refund grace was not consumed; recurring polling will continue reconciliation.', 'ainepay-for-woocommerce' ) );
				$order->save();
				self::alert_refund_unreachable( $order, $oid );
				return;
			}
			++$attempts;
			$order->update_meta_data( '_ainepay_refund_outage_attempts', $attempts );
		} else {
			$attempts = 1; // First check soon; no persisted budget is consumed.
		}
		$order->update_meta_data( '_ainepay_refund_pending', '1' );
		$order->save();

		$delay = self::cancel_backoff_seconds( $attempts );
		if ( ! self::ensure_async( self::REFUND_VERIFY_HOOK, $oid, $delay ) ) {
			Ainepay_Logger::error( 'Could not schedule refund verify', array( 'orderId' => $oid ) );
			$order->update_meta_data( '_ainepay_refund_outage_failed', '1' );
			$order->add_order_note( __( 'Could not schedule the AinePay refund verification. Merchant refund grace was not exhausted; recurring polling will continue reconciliation.', 'ainepay-for-woocommerce' ) );
			$order->save();
			self::alert_refund_unreachable( $order, $oid );
		}
	}

	/**
	 * Whether a refund verification is already queued/running. Used by the recurring
	 * poller only, so it cannot consume merchant budget once per poll while the
	 * existing worker is still responsible for scheduling its next attempt.
	 *
	 * @param string $oid AinePay order id.
	 * @return bool
	 */
	private static function refund_verify_is_scheduled( $oid ) {
		$args = array( (string) $oid );
		if ( self::has_pending_action( self::REFUND_VERIFY_HOOK, $args, self::CANCEL_GROUP ) ) {
			return true;
		}
		return function_exists( 'wp_next_scheduled' )
			&& false !== wp_next_scheduled( self::REFUND_VERIFY_HOOK, $args );
	}

	/**
	 * Whether a cancel-sync action is already pending/in-progress for this order.
	 * Lets the poller avoid re-driving (and consuming the retry budget on) a cancel
	 * that the dedicated cancel-sync worker still owns.
	 *
	 * @param string $oid AinePay order id.
	 * @return bool
	 */
	private static function cancel_sync_is_scheduled( $oid ) {
		$args = array( (string) $oid );
		if ( self::has_pending_action( self::CANCEL_SYNC_HOOK, $args, self::CANCEL_GROUP ) ) {
			return true;
		}
		return function_exists( 'wp_next_scheduled' )
			&& false !== wp_next_scheduled( self::CANCEL_SYNC_HOOK, $args );
	}

	/**
	 * Clear backend-outage bookkeeping after any successful authoritative query.
	 *
	 * @param WC_Order $order WooCommerce order.
	 * @return void
	 */
	private static function clear_refund_outage( $order ) {
		if ( '' === (string) $order->get_meta( '_ainepay_refund_outage_attempts' )
			&& '' === (string) $order->get_meta( '_ainepay_refund_outage_failed' ) ) {
			return;
		}
		$order->delete_meta_data( '_ainepay_refund_outage_failed' );
		delete_transient( 'ainepay_refund_unreachable_' . $order->get_id() );
		if ( '' !== (string) $order->get_meta( '_ainepay_refund_outage_attempts' ) ) {
			$order->update_meta_data( '_ainepay_refund_outage_attempts', 0 );
		}
		$order->save();
	}

	/**
	 * Clear the refund-pending bookkeeping once AinePay confirms REFUND (or the
	 * order leaves the refunded state). No-op when there is nothing to clear.
	 *
	 * @param WC_Order $order WooCommerce order.
	 * @return void
	 */
	private static function clear_refund_pending( $order ) {
		if ( '' === (string) $order->get_meta( '_ainepay_refund_pending' )
			&& '' === (string) $order->get_meta( '_ainepay_refund_merchant_attempts' )
			// Legacy mixed counter from pre-split versions; never used for new budget.
			&& '' === (string) $order->get_meta( '_ainepay_refund_attempts' )
			&& '' === (string) $order->get_meta( '_ainepay_refund_outage_attempts' )
			&& '' === (string) $order->get_meta( '_ainepay_refund_outage_failed' )
			&& '' === (string) $order->get_meta( '_ainepay_refund_failed' ) ) {
			return;
		}
		$order->delete_meta_data( '_ainepay_refund_pending' );
		$order->delete_meta_data( '_ainepay_refund_failed' );
		$order->delete_meta_data( '_ainepay_refund_outage_failed' );
		delete_transient( 'ainepay_refund_stuck_' . $order->get_id() );
		delete_transient( 'ainepay_refund_unreachable_' . $order->get_id() );
		if ( '' !== (string) $order->get_meta( '_ainepay_refund_merchant_attempts' ) ) {
			$order->update_meta_data( '_ainepay_refund_merchant_attempts', 0 );
		}
		// Clear the legacy mixed counter on authoritative resolution.
		if ( '' !== (string) $order->get_meta( '_ainepay_refund_attempts' ) ) {
			$order->update_meta_data( '_ainepay_refund_attempts', 0 );
		}
		if ( '' !== (string) $order->get_meta( '_ainepay_refund_outage_attempts' ) ) {
			$order->update_meta_data( '_ainepay_refund_outage_attempts', 0 );
		}
		$order->save();
	}

	/**
	 * Raise an alert when a manual refund is not confirmed at AinePay after the
	 * grace window. Mirrors alert_cancel_stuck(): a dashboard transient plus the
	 * `ainepay_refund_stuck` action so a site can wire active alerting.
	 *
	 * @param WC_Order $order WooCommerce order.
	 * @param string   $oid   AinePay order id.
	 * @return void
	 */
	private static function alert_refund_stuck( $order, $oid ) {
		Ainepay_Logger::error( 'Manual refund not confirmed at AinePay after grace window; manual attention required.', array( 'orderId' => $oid ) );
		set_transient( 'ainepay_refund_stuck_' . $order->get_id(), $oid, WEEK_IN_SECONDS );
		do_action( 'ainepay_refund_stuck', $order, $oid );
	}

	/**
	 * Raise a connectivity-specific alert without claiming the merchant omitted
	 * the second refund step. Recurring poller repair remains the final backstop.
	 *
	 * @param WC_Order $order WooCommerce order.
	 * @param string   $oid   AinePay order id.
	 * @return void
	 */
	private static function alert_refund_unreachable( $order, $oid ) {
		Ainepay_Logger::error( 'AinePay unreachable during refund verification; merchant grace remains intact.', array( 'orderId' => $oid ) );
		set_transient( 'ainepay_refund_unreachable_' . $order->get_id(), $oid, WEEK_IN_SECONDS );
		do_action( 'ainepay_refund_unreachable', $order, $oid );
	}

	/* --- idempotency ----------------------------------------------------- */

	/**
	 * Whether an idempotency key has already been processed for this order.
	 *
	 * @param WC_Order $order WooCommerce order.
	 * @param string   $key   Idempotency key.
	 * @return bool
	 */
	private static function seen_idempotency_key( $order, $key ) {
		$keys = self::idempotency_keys( $order );
		return in_array( $key, $keys, true );
	}

	/**
	 * Read the stored idempotency keys as a clean list (no empty placeholders).
	 *
	 * @param WC_Order $order WooCommerce order.
	 * @return string[]
	 */
	private static function idempotency_keys( $order ) {
		$stored = $order->get_meta( '_ainepay_idempotency_keys' );
		if ( ! is_array( $stored ) ) {
			return array();
		}
		return array_values( array_filter( array_map( 'strval', $stored ), 'strlen' ) );
	}

	/**
	 * Record an idempotency key, keeping only the most recent N.
	 *
	 * @param WC_Order $order WooCommerce order.
	 * @param string   $key   Idempotency key.
	 * @return void
	 */
	private static function record_idempotency_key( $order, $key ) {
		if ( '' === (string) $key ) {
			return;
		}
		$keys   = self::idempotency_keys( $order );
		$keys[] = (string) $key;
		if ( count( $keys ) > self::MAX_IDEMPOTENCY_KEYS ) {
			$keys = array_slice( $keys, -self::MAX_IDEMPOTENCY_KEYS );
		}
		$order->update_meta_data( '_ainepay_idempotency_keys', $keys );
		$order->save();
	}

	/* --- locking (MySQL GET_LOCK) --------------------------------------- */

	/**
	 * Acquire a per-order advisory lock.
	 *
	 * @param string $ainepay_order_id AinePay order id.
	 * @return string|false The lock name on success, false on contention/failure.
	 */
	private static function acquire_lock( $ainepay_order_id ) {
		global $wpdb;
		$name = self::lock_name( $ainepay_order_id );
		$got  = $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, %d)', $name, self::LOCK_TIMEOUT_SECONDS ) );
		return ( '1' === (string) $got ) ? $name : false;
	}

	/**
	 * Release a previously acquired lock.
	 *
	 * @param string $name Lock name.
	 * @return void
	 */
	private static function release_lock( $name ) {
		global $wpdb;
		$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $name ) );
	}

	/**
	 * Build a lock name scoped to this site and order (<= 64 chars for MySQL).
	 *
	 * @param string $ainepay_order_id AinePay order id.
	 * @return string
	 */
	private static function lock_name( $ainepay_order_id ) {
		global $wpdb;
		$prefix  = isset( $wpdb->prefix ) ? (string) $wpdb->prefix : '';
		$blog_id = function_exists( 'get_current_blog_id' ) ? (int) get_current_blog_id() : 0;
		$scope   = $prefix . '|' . $blog_id . '|' . (string) $ainepay_order_id;
		return 'ainepay_' . substr( hash( 'sha256', $scope ), 0, 40 );
	}

	/**
	 * Get the AinePay gateway instance.
	 *
	 * @return Ainepay_Gateway|null
	 */
	private static function gateway() {
		if ( ! function_exists( 'WC' ) || ! WC()->payment_gateways() ) {
			return null;
		}
		$gateways = WC()->payment_gateways()->payment_gateways();
		return isset( $gateways[ Ainepay_Plugin::GATEWAY_ID ] ) ? $gateways[ Ainepay_Plugin::GATEWAY_ID ] : null;
	}
}
