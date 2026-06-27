<?php
/**
 * Tests for the "non-PAID must never be treated as paid" invariant:
 *   - guard_paid_invariant(): flags + async-verifies unbacked success promotions;
 *   - gate_unbacked_email()/gate_unbacked_download(): fail-closed fulfilment gates
 *     that run at the WC use-point, before the guard can revert;
 *   - verify_paid_invariant(): reverts ONLY when the backend confirms not-paid,
 *     and never during a backend outage.
 *
 * @package AinePay\WooCommerce
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/wp-stubs.php';
require_once dirname( __DIR__ ) . '/includes/class-ainepay-order-helper.php';
require_once dirname( __DIR__ ) . '/includes/class-ainepay-order-sync.php';

/**
 * @covers Ainepay_Order_Sync
 */
class OrderSyncGuardTest extends TestCase {

	protected function setUp(): void {
		Ainepay_Test_Env::reset();
	}

	/**
	 * @param array<string,mixed> $meta Order meta.
	 * @param string              $status WC status.
	 * @return WC_Order
	 */
	private function order( array $meta = array(), $status = 'processing', $pm = 'ainepay' ) {
		static $id = 1000;
		$id++;
		return Ainepay_Test_Env::add_order(
			new WC_Order(
				array(
					'id'             => $id,
					'status'         => $status,
					'payment_method' => $pm,
					'meta'           => $meta,
				)
			)
		);
	}

	/* --- guard_paid_invariant --------------------------------------------- */

	public function test_guard_ignores_non_success_transitions() {
		$order = $this->order( array( '_ainepay_order_id' => 'OID' ), 'on-hold' );
		Ainepay_Order_Sync::guard_paid_invariant( $order->get_id(), 'pending', 'on-hold', $order );
		$this->assertSame( array(), Ainepay_Test_Env::$scheduled );
		$this->assertSame( array(), $order->notes );
	}

	public function test_guard_ignores_non_ainepay_orders() {
		$order = $this->order( array( '_ainepay_order_id' => 'OID' ), 'processing', 'stripe' );
		Ainepay_Order_Sync::guard_paid_invariant( $order->get_id(), 'on-hold', 'processing', $order );
		$this->assertSame( array(), Ainepay_Test_Env::$scheduled );
	}

	public function test_guard_passes_through_paid_backed_orders() {
		$order = $this->order(
			array(
				'_ainepay_order_id' => 'OID',
				'_ainepay_status'   => 'PAID',
			),
			'processing'
		);
		Ainepay_Order_Sync::guard_paid_invariant( $order->get_id(), 'on-hold', 'processing', $order );
		$this->assertSame( array(), Ainepay_Test_Env::$scheduled );
		$this->assertSame( array(), $order->notes );
	}

	public function test_guard_reverts_order_id_less_success_promotion_to_on_hold() {
		// An AinePay-method order promoted to a success state with no AinePay order id
		// was never created at AinePay and cannot have been paid. There is nothing to
		// query, so the guard reverts it deterministically rather than silently
		// skipping it (which would bypass verification and the fulfilment gates).
		$order = $this->order( array(), 'processing' );
		Ainepay_Order_Sync::guard_paid_invariant( $order->get_id(), 'on-hold', 'processing', $order );
		$this->assertTrue( $order->has_status( 'on-hold' ) );
		$this->assertSame( array(), Ainepay_Test_Env::$scheduled ); // No backend to verify against.
	}

	public function test_guard_respects_enforcement_filter_off_for_order_id_less_order() {
		Ainepay_Test_Env::$filter_overrides['ainepay_enforce_paid_invariant'] = false;
		$order = $this->order( array(), 'processing' );
		Ainepay_Order_Sync::guard_paid_invariant( $order->get_id(), 'on-hold', 'processing', $order );
		$this->assertTrue( $order->has_status( 'processing' ) ); // Opt-out honoured.
		$this->assertSame( array(), Ainepay_Test_Env::$scheduled );
	}

	public function test_guard_respects_enforcement_filter_off() {
		Ainepay_Test_Env::$filter_overrides['ainepay_enforce_paid_invariant'] = false;
		$order = $this->order( array( '_ainepay_order_id' => 'OID' ), 'processing' );
		Ainepay_Order_Sync::guard_paid_invariant( $order->get_id(), 'on-hold', 'processing', $order );
		$this->assertSame( array(), Ainepay_Test_Env::$scheduled );
	}

	public function test_guard_flags_and_enqueues_async_verify_for_unbacked_success() {
		$order = $this->order( array( '_ainepay_order_id' => 'OID' ), 'processing' );
		Ainepay_Order_Sync::guard_paid_invariant( $order->get_id(), 'on-hold', 'processing', $order );

		$this->assertCount( 1, Ainepay_Test_Env::$scheduled );
		$this->assertSame( Ainepay_Order_Sync::VERIFY_PAID_HOOK, Ainepay_Test_Env::$scheduled[0]['hook'] );
		$this->assertSame( array( 'OID' ), Ainepay_Test_Env::$scheduled[0]['args'] );
		$this->assertNotEmpty( $order->notes );
	}

	/* --- public third-party fulfilment contract --------------------------- */

	public function test_public_paid_backing_predicate_requires_ainepay_paid_success() {
		$backed = $this->order(
			array(
				'_ainepay_order_id' => 'OID',
				'_ainepay_status'   => 'PAID',
			),
			'processing'
		);
		$unbacked = $this->order( array( '_ainepay_order_id' => 'OID-2' ), 'processing' );
		$non_ainepay = $this->order(
			array( '_ainepay_order_id' => 'OID-3', '_ainepay_status' => 'PAID' ),
			'processing',
			'stripe'
		);

		$this->assertTrue( Ainepay_Order_Sync::is_paid_backed_order( $backed ) );
		$this->assertFalse( Ainepay_Order_Sync::is_paid_backed_order( $unbacked ) );
		$this->assertFalse( Ainepay_Order_Sync::is_paid_backed_order( $non_ainepay ) );
		$this->assertFalse( Ainepay_Order_Sync::is_paid_backed_order( null ) );
	}

	public function test_authoritative_paid_emits_dedicated_fulfilment_action_once() {
		$order = $this->order(
			array(
				'_ainepay_order_id' => 'OID',
				'_ainepay_status'   => 'PAID',
			),
			'on-hold'
		);

		Ainepay_Order_Sync::mark_order_paid( $order, 'TX-1' );
		Ainepay_Order_Sync::mark_order_paid( $order, 'TX-1' );

		$actions = array_values(
			array_filter(
				Ainepay_Test_Env::$actions,
				function ( $action ) {
					return 'ainepay_order_paid_backed' === $action['hook'];
				}
			)
		);
		$this->assertCount( 1, $actions );
		$this->assertSame( $order, $actions[0]['args'][0] );
		$this->assertSame( 'TX-1', $actions[0]['args'][1] );
		$this->assertSame( '1', $order->get_meta( '_ainepay_paid_backed_notified' ) );
	}

	public function test_repaired_external_success_emits_paid_backed_action() {
		$order = $this->order(
			array(
				'_ainepay_order_id' => 'OID',
				'_ainepay_status'   => 'PAID',
			),
			'processing'
		);

		Ainepay_Order_Sync::mark_order_paid( $order, 'TX-2' );

		$this->assertContains( 'ainepay_order_paid_backed', array_column( Ainepay_Test_Env::$actions, 'hook' ) );
	}

	public function test_unbacked_success_never_emits_paid_backed_action() {
		$order = $this->order( array( '_ainepay_order_id' => 'OID' ), 'processing' );

		Ainepay_Order_Sync::mark_order_paid( $order, 'TX-3' );

		$this->assertNotContains( 'ainepay_order_paid_backed', array_column( Ainepay_Test_Env::$actions, 'hook' ) );
	}

	/* --- gate_unbacked_email / gate_unbacked_download --------------------- */

	public function test_gate_suppresses_email_for_unbacked_ainepay_order() {
		$order = $this->order( array( '_ainepay_order_id' => 'OID' ), 'processing' );
		$this->assertFalse( Ainepay_Order_Sync::gate_unbacked_email( true, $order ) );
	}

	public function test_gate_suppresses_email_for_order_id_less_ainepay_order() {
		// A missing AinePay order id must NOT exempt the order from the fulfilment
		// gate: with no AinePay payment on record it is the most important to gate.
		$order = $this->order( array(), 'processing' );
		$this->assertFalse( Ainepay_Order_Sync::gate_unbacked_email( true, $order ) );
		$this->assertFalse( Ainepay_Order_Sync::gate_unbacked_download( true, $order ) );
	}

	public function test_gate_allows_email_for_paid_backed_order() {
		$order = $this->order(
			array(
				'_ainepay_order_id' => 'OID',
				'_ainepay_status'   => 'PAID',
			),
			'processing'
		);
		$this->assertTrue( Ainepay_Order_Sync::gate_unbacked_email( true, $order ) );
	}

	public function test_gate_allows_email_for_non_ainepay_order() {
		$order = $this->order( array( '_ainepay_order_id' => 'OID' ), 'processing', 'stripe' );
		$this->assertTrue( Ainepay_Order_Sync::gate_unbacked_email( true, $order ) );
	}

	public function test_gate_does_not_re_enable_an_already_disabled_email() {
		$order = $this->order( array( '_ainepay_order_id' => 'OID' ), 'processing' );
		$this->assertFalse( Ainepay_Order_Sync::gate_unbacked_email( false, $order ) );
	}

	public function test_gate_ignores_non_order_objects() {
		$this->assertTrue( Ainepay_Order_Sync::gate_unbacked_email( true, null ) );
	}

	public function test_gate_respects_enforcement_filter_off_for_email() {
		Ainepay_Test_Env::$filter_overrides['ainepay_enforce_paid_invariant'] = false;
		$order = $this->order( array( '_ainepay_order_id' => 'OID' ), 'processing' );
		$this->assertTrue( Ainepay_Order_Sync::gate_unbacked_email( true, $order ) );
	}

	public function test_gate_denies_download_for_unbacked_order() {
		$order = $this->order( array( '_ainepay_order_id' => 'OID' ), 'processing' );
		$this->assertFalse( Ainepay_Order_Sync::gate_unbacked_download( true, $order ) );
	}

	public function test_gate_allows_download_for_paid_backed_order() {
		$order = $this->order(
			array(
				'_ainepay_order_id' => 'OID',
				'_ainepay_status'   => 'PAID',
			),
			'completed'
		);
		$this->assertTrue( Ainepay_Order_Sync::gate_unbacked_download( true, $order ) );
	}

	/* --- gate_premature_restock ------------------------------------------- */

	public function test_restock_gate_holds_stock_for_unconfirmed_native_cancel() {
		$order = $this->order( array( '_ainepay_order_id' => 'OID' ), 'cancelled' );
		$this->assertFalse( Ainepay_Order_Sync::gate_premature_restock( true, $order ) );
	}

	public function test_restock_gate_allows_once_backend_confirmed_cancel() {
		$order = $this->order(
			array(
				'_ainepay_order_id' => 'OID',
				'_ainepay_status'   => 'CANCEL',
			),
			'cancelled'
		);
		$this->assertTrue( Ainepay_Order_Sync::gate_premature_restock( true, $order ) );
	}

	public function test_restock_gate_ignores_non_cancelled_status() {
		// EXPIRED->failed restore is already authoritative: must not be held.
		$order = $this->order( array( '_ainepay_order_id' => 'OID', '_ainepay_status' => 'EXPIRED' ), 'failed' );
		$this->assertTrue( Ainepay_Order_Sync::gate_premature_restock( true, $order ) );
	}

	public function test_restock_gate_ignores_non_ainepay_order() {
		$order = $this->order( array( '_ainepay_order_id' => 'OID' ), 'cancelled', 'stripe' );
		$this->assertTrue( Ainepay_Order_Sync::gate_premature_restock( true, $order ) );
	}

	public function test_restock_gate_respects_enforcement_filter_off() {
		Ainepay_Test_Env::$filter_overrides['ainepay_enforce_paid_invariant'] = false;
		$order = $this->order( array( '_ainepay_order_id' => 'OID' ), 'cancelled' );
		$this->assertTrue( Ainepay_Order_Sync::gate_premature_restock( true, $order ) );
	}

	public function test_restock_gate_passes_through_when_already_denied() {
		$order = $this->order( array( '_ainepay_order_id' => 'OID' ), 'cancelled' );
		$this->assertFalse( Ainepay_Order_Sync::gate_premature_restock( false, $order ) );
	}

	/* --- verify_paid_invariant -------------------------------------------- */

	public function test_verify_is_noop_when_order_missing() {
		Ainepay_Order_Sync::verify_paid_invariant( 'NOPE' );
		$this->assertSame( array(), Ainepay_Test_Env::$scheduled );
	}

	public function test_verify_is_noop_when_order_no_longer_in_success_state() {
		$this->order( array( '_ainepay_order_id' => 'OID' ), 'on-hold' );
		Ainepay_Test_Env::set_gateway(); // would error if queried.
		Ainepay_Order_Sync::verify_paid_invariant( 'OID' );
		$this->assertSame( 0, Ainepay_Test_Env::$gateway->get_api_client()->get_orders_calls ?? 0 );
	}

	public function test_verify_does_not_revert_during_backend_outage_but_retries() {
		$order = $this->order( array( '_ainepay_order_id' => 'OID' ), 'processing' );
		// No gateway installed => query_status() returns null (unreachable).
		Ainepay_Order_Sync::verify_paid_invariant( 'OID' );
		// Not reverted (never brick a manual promotion during an outage)...
		$this->assertTrue( $order->has_status( 'processing' ) );
		// ...but a retry is scheduled, so a single outage can't fail-open forever.
		$this->assertCount( 1, Ainepay_Test_Env::$scheduled );
		$this->assertSame( Ainepay_Order_Sync::VERIFY_PAID_HOOK, Ainepay_Test_Env::$scheduled[0]['hook'] );
		$this->assertSame( 1, (int) $order->get_meta( '_ainepay_paid_verify_attempts' ) );
	}

	public function test_verify_escalates_to_unverified_alert_after_max_attempts() {
		$order = $this->order(
			array( '_ainepay_order_id' => 'OID', '_ainepay_paid_verify_attempts' => Ainepay_Order_Sync::PAID_VERIFY_MAX_ATTEMPTS ),
			'processing'
		);
		// Still unreachable at the cap: alert for manual review, do NOT auto-revert.
		Ainepay_Order_Sync::verify_paid_invariant( 'OID' );
		$this->assertTrue( $order->has_status( 'processing' ) );
		$this->assertSame( '1', $order->get_meta( '_ainepay_paid_verify_failed' ) );
		$this->assertSame( 'OID', get_transient( 'ainepay_paid_unverified_' . $order->get_id() ) );
		$this->assertContains( 'ainepay_paid_unverified', array_column( Ainepay_Test_Env::$actions, 'hook' ) );
		$this->assertSame( array(), Ainepay_Test_Env::$scheduled );
	}

	public function test_verify_clears_retry_state_once_backend_confirms_not_paid() {
		$order = $this->order(
			array( '_ainepay_order_id' => 'OID', '_ainepay_paid_verify_attempts' => 3 ),
			'processing'
		);
		Ainepay_Test_Env::set_gateway(
			array( 'orders' => array( array( 'orderId' => 'OID', 'status' => 'CANCEL', 'updated' => 'u1' ) ) )
		);
		Ainepay_Order_Sync::verify_paid_invariant( 'OID' );
		$this->assertTrue( $order->has_status( 'on-hold' ) );
		$this->assertSame( 0, (int) $order->get_meta( '_ainepay_paid_verify_attempts' ) );
	}

	public function test_verify_clears_retry_state_once_backend_confirms_paid() {
		$order = $this->order(
			array( '_ainepay_order_id' => 'OID', '_ainepay_paid_verify_attempts' => 2 ),
			'processing'
		);
		Ainepay_Test_Env::set_gateway(
			array( 'orders' => array( array( 'orderId' => 'OID', 'status' => 'PAID', 'updated' => 'u1' ) ) )
		);
		Ainepay_Order_Sync::verify_paid_invariant( 'OID' );
		$this->assertSame( 'PAID', $order->get_meta( '_ainepay_status' ) );
		$this->assertSame( 0, (int) $order->get_meta( '_ainepay_paid_verify_attempts' ) );
	}

	public function test_verify_rebacks_a_genuinely_paid_order() {
		$order = $this->order( array( '_ainepay_order_id' => 'OID' ), 'processing' );
		Ainepay_Test_Env::set_gateway(
			array( 'orders' => array( array( 'orderId' => 'OID', 'status' => 'PAID', 'updated' => 'u1' ) ) )
		);
		Ainepay_Order_Sync::verify_paid_invariant( 'OID' );
		$this->assertSame( 'PAID', $order->get_meta( '_ainepay_status' ) );
		$this->assertTrue( $order->has_status( 'processing' ) );
	}

	public function test_verify_reverts_unbacked_order_when_backend_confirms_not_paid() {
		$order = $this->order( array( '_ainepay_order_id' => 'OID' ), 'processing' );
		Ainepay_Test_Env::set_gateway(
			array( 'orders' => array( array( 'orderId' => 'OID', 'status' => 'CANCEL', 'updated' => 'u1' ) ) )
		);
		Ainepay_Order_Sync::verify_paid_invariant( 'OID' );
		$this->assertTrue( $order->has_status( 'on-hold' ) );
	}

	/* --- fail-loud: the verify intent is never silently dropped ----------- */

	public function test_guard_enqueue_failure_falls_back_to_loud_scheduled_retry() {
		// The unbacked promotion fired but the immediate async enqueue fails. This
		// must NOT silently drop the verify: it falls back to schedule_paid_verify,
		// which (also unable to schedule here) fails loud and escalates to an alert.
		$order = $this->order( array( '_ainepay_order_id' => 'OID' ), 'processing' );
		Ainepay_Test_Env::$schedule_fails = true;
		Ainepay_Order_Sync::guard_paid_invariant( $order->get_id(), 'on-hold', 'processing', $order );

		$this->assertSame( array(), Ainepay_Test_Env::$scheduled );
		$this->assertSame( '1', $order->get_meta( '_ainepay_paid_verify_failed' ) );
		$this->assertSame( 'OID', get_transient( 'ainepay_paid_unverified_' . $order->get_id() ) );
		$this->assertContains( 'ainepay_paid_unverified', array_column( Ainepay_Test_Env::$actions, 'hook' ) );
	}

	public function test_verify_reschedules_when_backend_paid_but_backing_cannot_apply() {
		// query_status (no lock) sees PAID, but handle_notification cannot acquire the
		// order lock and returns BUSY without writing meta=PAID. The verify must NOT be
		// marked resolved: it stays unbacked, so reschedule rather than clear.
		$order = $this->order(
			array( '_ainepay_order_id' => 'OID', '_ainepay_paid_verify_attempts' => 2 ),
			'processing'
		);
		Ainepay_Test_Env::set_gateway(
			array( 'orders' => array( array( 'orderId' => 'OID', 'status' => 'PAID', 'updated' => 'u1' ) ) )
		);
		Ainepay_Test_Env::$lock_result = '0'; // handle_notification cannot lock -> BUSY.

		Ainepay_Order_Sync::verify_paid_invariant( 'OID' );

		$this->assertNotSame( 'PAID', strtoupper( (string) $order->get_meta( '_ainepay_status' ) ) );
		$this->assertSame( 3, (int) $order->get_meta( '_ainepay_paid_verify_attempts' ) );
		$this->assertCount( 1, Ainepay_Test_Env::$scheduled );
		$this->assertSame( Ainepay_Order_Sync::VERIFY_PAID_HOOK, Ainepay_Test_Env::$scheduled[0]['hook'] );
	}
}
