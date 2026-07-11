<?php
/**
 * Periodically refresh pending and locally-unbacked AinePay orders from the
 * authoritative /order endpoint. Webhooks and bounded one-shot retry chains are
 * acceleration paths; recurring polling is the final-consistency safety net.
 *
 * Uses Action Scheduler (bundled with WooCommerce) when available, falling back
 * to WP-Cron otherwise.
 *
 * @package AinePay\WooCommerce
 */

defined( 'ABSPATH' ) || exit;

/**
 * Recurring poller for pending AinePay orders.
 */
class Ainepay_Poller {

	const HOOK                 = 'ainepay_poll_pending_orders';
	const BATCH_LIMIT          = 50;
	const MAX_ORDER_AGE_DAYS   = 7;
	const CURSOR_OPTION_PREFIX = 'ainepay_poll_cursor_';

	const CRON_SCHEDULE = 'ainepay_poll_interval';

	/**
	 * Register hooks and schedule the recurring task.
	 *
	 * @return void
	 */
	public static function init() {
		$self = new self();
		add_action( self::HOOK, array( $self, 'run' ) );
		add_action( 'init', array( $self, 'ensure_scheduled' ) );
		add_filter( 'cron_schedules', array( $self, 'register_cron_schedule' ) ); // phpcs:ignore WordPress.WP.CronInterval.ChangeDetected
	}

	/**
	 * Register a WP-Cron schedule matching the configured poll interval, so the
	 * fallback runs at the same cadence as the Action Scheduler task.
	 *
	 * @param array $schedules Existing cron schedules.
	 * @return array
	 */
	public function register_cron_schedule( $schedules ) {
		$interval                         = (int) apply_filters( 'ainepay_poll_cron_interval', $this->poll_interval() );
		$schedules[ self::CRON_SCHEDULE ] = array(
			'interval' => max( 60, $interval ),
			'display'  => __( 'AinePay polling interval', 'ainepay-for-woocommerce' ),
		);
		return $schedules;
	}

	/**
	 * Ensure the recurring poll is scheduled exactly once.
	 *
	 * @return void
	 */
	public function ensure_scheduled() {
		$interval = (int) apply_filters( 'ainepay_poll_cron_interval', $this->poll_interval() );

		if ( function_exists( 'as_has_scheduled_action' ) && function_exists( 'as_schedule_recurring_action' ) ) {
			if ( ! as_has_scheduled_action( self::HOOK ) ) {
				as_schedule_recurring_action( time() + $interval, $interval, self::HOOK, array(), 'ainepay' );
			}
			return;
		}

		// WP-Cron fallback, using our custom schedule (matches $interval).
		if ( ! wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_event( time() + $interval, self::CRON_SCHEDULE, self::HOOK );
		}
	}

	/**
	 * Clear scheduled actions (called on deactivation).
	 *
	 * @return void
	 */
	public static function unschedule() {
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( self::HOOK, array(), 'ainepay' );
		}
		$timestamp = wp_next_scheduled( self::HOOK );
		if ( $timestamp ) {
			wp_unschedule_event( $timestamp, self::HOOK );
		}
	}

	/**
	 * Refresh pending orders plus repair batches for locally-unbacked states.
	 *
	 * @return void
	 */
	public function run() {
		if ( ! class_exists( 'Ainepay_Order_Sync' ) ) {
			return;
		}

		$since = '>' . ( time() - self::MAX_ORDER_AGE_DAYS * DAY_IN_SECONDS );

		// Backing-mismatch meta filter shared by the cancelled and failed repair
		// batches: pick up orders whose `_ainepay_status` is missing or is not yet a
		// consistent terminal backing (CANCEL/EXPIRED), so a cancel/settle race the
		// backend later resolves (e.g. to PAID) is re-queried.
		$unbacked_terminal_meta = array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
			'relation' => 'OR',
			array(
				'key'     => '_ainepay_status',
				'compare' => 'NOT EXISTS',
			),
			array(
				'key'     => '_ainepay_status',
				'value'   => array( 'CANCEL', 'EXPIRED' ),
				'compare' => 'NOT IN',
			),
		);
		$paid_mismatch_meta     = array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
			'relation' => 'OR',
			array(
		'key'     => '_ainepay_status',
		'compare' => 'NOT EXISTS',
		),
			array(
		'key'     => '_ainepay_status',
		'value'   => 'PAID',
		'compare' => '!=',
		),
		);
		$refund_mismatch_meta   = array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
			'relation' => 'OR',
			array(
		'key'     => '_ainepay_status',
		'compare' => 'NOT EXISTS',
		),
			array(
		'key'     => '_ainepay_status',
		'value'   => 'REFUND',
		'compare' => '!=',
		),
		);

		// Each batch is paged by a persisted rotating cursor (fetch_batch) so a head
		// of long-stuck records — backend outage, a merchant who never did the second
		// AinePay refund step, a permanent anomaly — cannot starve newer orders out of
		// the safety net: every round advances past the page just scanned and wraps at
		// the tail, guaranteeing each matching order is eventually reached.
		$orders = array();

		// Primary batch: non-terminal orders awaiting a result.
		$orders = array_merge(
			$orders,
			self::fetch_batch(
				'primary',
				array(
					'status'       => array( 'on-hold' ),
					'date_created' => $since,
				)
			)
		);

		// Repair batch: WC=cancelled orders NOT yet backed by an authoritative CANCEL.
		// Deliberately NO creation-age cutoff. A native admin cancel whose immediate
		// cancel-sync action is later dropped/never-runs leaves the order stuck at
		// WC=cancelled / AinePay=INIT with its stock held by gate_premature_restock
		// forever; an age filter would permanently exclude orders older than the
		// window. This set is self-draining: refresh_order re-drives request_cancel,
		// which moves each order to CANCEL (or repairs a settle-race PAID back to
		// processing, or EXPIRED→failed), so it leaves the unbacked-cancelled set.
		$orders = array_merge(
			$orders,
			self::fetch_batch(
				'cancelled',
				array(
					'status'     => array( 'cancelled' ),
					'meta_query' => $unbacked_terminal_meta,
				)
			)
		);

		// Repair batch: WC=failed orders NOT yet backed by a terminal status. Unlike
		// cancelled, a failed order has no coordinator driving it to a terminal backing,
		// so without an age cutoff every historical failed AinePay order would be
		// re-queried forever. The settle-race window that could flip a recently-failed
		// order to PAID is short, so the creation-age filter is retained here.
		$orders = array_merge(
			$orders,
			self::fetch_batch(
				'failed',
				array(
					'status'       => array( 'failed' ),
					'date_created' => $since,
					'meta_query'   => $unbacked_terminal_meta,
				)
			)
		);

		// Repair unbacked Woo success states independently of the bounded
		// ainepay_verify_paid Action Scheduler chain. A dropped/exhausted action must
		// not leave processing/completed permanently trusted without AinePay PAID.
		// Deliberately no creation-age cutoff: an external promotion can happen long
		// after checkout and still needs repair.
		$orders = array_merge(
			$orders,
			self::fetch_batch(
				'success',
				array(
					'status'     => array( 'processing', 'completed' ),
					'meta_query' => $paid_mismatch_meta,
				)
			)
		);

		// Repair Woo-first full refunds independently of the bounded refund-verify
		// chain. refresh_order() treats this state specially: while AinePay remains
		// PAID the Woo refund is preserved; only authoritative REFUND closes it.
		// Deliberately no creation-age cutoff: refunds commonly occur weeks later.
		$orders = array_merge(
			$orders,
			self::fetch_batch(
				'refund',
				array(
					'status'     => array( 'refunded' ),
					'meta_query' => $refund_mismatch_meta,
				)
			)
		);

		if ( empty( $orders ) ) {
			return;
		}

		$seen = array();
		foreach ( $orders as $order ) {
			$id = $order->get_id();
			if ( isset( $seen[ $id ] ) ) {
				continue;
			}
			$seen[ $id ] = true;
			if ( '' === (string) $order->get_meta( '_ainepay_order_id' ) ) {
				continue;
			}
			Ainepay_Order_Sync::refresh_order( $order );
		}

		Ainepay_Logger::debug( 'Poll batch processed', array( 'count' => count( $seen ) ) );
	}

	/**
	 * Fetch one page of a repair batch using a persisted rotating cursor, so no
	 * batch can be permanently starved by a stuck head.
	 *
	 * Each batch keeps its own offset in an option. A round reads BATCH_LIMIT rows
	 * from that offset; if a full page came back there may be more, so the cursor
	 * advances past it for next round; a short (or empty) page means the tail was
	 * reached, so the cursor wraps to 0. With a head of long-stuck records the scan
	 * therefore walks forward each round and eventually covers every matching order,
	 * instead of re-reading the same oldest 50 forever. The fixed window also caps
	 * the work and HTTP fan-out per round.
	 *
	 * @param string $key   Batch identifier (cursor namespace).
	 * @param array  $query wc_get_orders args specific to this batch (status, meta_query, date_created).
	 * @return array<int,WC_Order>
	 */
	private static function fetch_batch( $key, array $query ) {
		$option = self::CURSOR_OPTION_PREFIX . $key;
		$offset = max( 0, (int) get_option( $option, 0 ) );

		$args = array_merge(
			array(
				'payment_method' => Ainepay_Plugin::GATEWAY_ID,
				'return'         => 'objects',
				'orderby'        => 'date',
				'order'          => 'ASC',
			),
			$query,
			array(
				'limit'  => self::BATCH_LIMIT,
				'offset' => $offset,
			)
		);

		$found = wc_get_orders( $args );
		$found = is_array( $found ) ? $found : array();

		// Full page => more may follow, advance; short page => tail reached, wrap.
		$next = ( count( $found ) >= self::BATCH_LIMIT ) ? $offset + self::BATCH_LIMIT : 0;
		if ( $next !== $offset ) {
			update_option( $option, $next );
		}

		return $found;
	}

	/**
	 * Poll cadence from gateway settings. Default to 60s so status converges
	 * promptly even when webhook delivery is delayed.
	 *
	 * @return int
	 */
	private function poll_interval() {
		$settings = get_option( 'woocommerce_' . Ainepay_Plugin::GATEWAY_ID . '_settings', array() );
		$interval = is_array( $settings ) && isset( $settings['poll_interval'] )
			? (int) $settings['poll_interval']
			: 60;

		return max( 60, $interval );
	}
}
