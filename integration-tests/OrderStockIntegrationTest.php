<?php
/**
 * Real WooCommerce stock lifecycle coverage for cancellation reconciliation.
 *
 * This suite is executed twice by bin/test-wc-integration.sh: once with legacy
 * order storage and once with HPOS. WooCommerce selects the datastore during its
 * bootstrap, so the modes intentionally run in separate PHP processes.
 *
 * @package AinePay\WooCommerce
 */

use Automattic\WooCommerce\Utilities\OrderUtil;

/**
 * @covers Ainepay_Order_Sync
 */
class OrderStockIntegrationTest extends WC_Unit_Test_Case {

	/** @var mixed Previous global stock-management option. */
	private $previous_manage_stock;

	public function setUp(): void {
		parent::setUp();
		$this->previous_manage_stock = get_option( 'woocommerce_manage_stock', null );
		update_option( 'woocommerce_manage_stock', 'yes' );
	}

	public function tearDown(): void {
		if ( null === $this->previous_manage_stock ) {
			delete_option( 'woocommerce_manage_stock' );
		} else {
			update_option( 'woocommerce_manage_stock', $this->previous_manage_stock );
		}
		parent::tearDown();
	}

	/**
	 * Invoke the private authoritative status application path without mocking
	 * WooCommerce's order, product, item, or stock data stores.
	 *
	 * @param WC_Order $order  Order under test.
	 * @param string   $status Authoritative AinePay status.
	 * @return void
	 */
	private function apply_status( $order, $status ) {
		$method = new ReflectionMethod( 'Ainepay_Order_Sync', 'apply_status' );
		$method->setAccessible( true );
		$method->invoke(
			null,
			$order,
			array(
				'orderId' => (string) $order->get_meta( '_ainepay_order_id' ),
				'status'  => $status,
				'updated' => 'integration-test',
				'id'      => 'tx-integration',
			),
			array()
		);
	}

	/**
	 * Create an on-hold AinePay order whose stock has already been reduced.
	 *
	 * @return array{0:WC_Order,1:int}
	 */
	private function reduced_stock_order() {
		$product = new WC_Product_Simple();
		$product->set_name( 'AinePay stock integration product' );
		$product->set_regular_price( '10.00' );
		$product->set_manage_stock( true );
		$product->set_stock_quantity( 5 );
		$product->set_stock_status( 'instock' );
		$product_id = $product->save();

		$order = wc_create_order();
		$order->set_payment_method( Ainepay_Plugin::GATEWAY_ID );
		$order->set_status( 'on-hold' );
		$order->update_meta_data( '_ainepay_order_id', 'OID-INTEGRATION-' . $order->get_id() );
		$order->add_product( $product, 1 );
		$order->calculate_totals();
		$order->save();

		wc_maybe_reduce_stock_levels( $order->get_id() );

		$order = wc_get_order( $order->get_id() );
		$this->assertSame( 4, wc_get_product( $product_id )->get_stock_quantity() );
		$this->assertTrue( (bool) $order->get_data_store()->get_stock_reduced( $order->get_id() ) );

		return array( $order, $product_id );
	}

	public function test_suite_uses_requested_order_storage_mode() {
		$expected_hpos = empty( getenv( 'DISABLE_HPOS' ) );
		$this->assertSame( $expected_hpos, OrderUtil::custom_orders_table_usage_is_enabled() );
		$this->assertNotFalse(
			has_filter(
				'woocommerce_can_restore_order_stock',
				array( 'Ainepay_Order_Sync', 'gate_premature_restock' )
			)
		);
	}

	public function test_native_cancel_holds_stock_until_authoritative_cancel_then_releases_once() {
		list( $order, $product_id ) = $this->reduced_stock_order();

		// Native admin/bulk cancellation fires WooCommerce's real restock hook.
		// The plugin filter must hold the stock while AinePay is unconfirmed.
		$order->update_status( 'cancelled' );
		$order = wc_get_order( $order->get_id() );
		$this->assertSame( 4, wc_get_product( $product_id )->get_stock_quantity() );
		$this->assertTrue( (bool) $order->get_data_store()->get_stock_reduced( $order->get_id() ) );

		// The backend confirmation arrives after WC is already cancelled. The
		// plugin must explicitly release the held stock and clear the datastore
		// marker, because the cancelled transition will not fire a second time.
		$this->apply_status( $order, 'CANCEL' );
		$order = wc_get_order( $order->get_id() );
		$this->assertSame( 5, wc_get_product( $product_id )->get_stock_quantity() );
		$this->assertFalse( (bool) $order->get_data_store()->get_stock_reduced( $order->get_id() ) );

		// Duplicate CANCEL notifications are idempotent.
		$this->apply_status( $order, 'CANCEL' );
		$this->assertSame( 5, wc_get_product( $product_id )->get_stock_quantity() );
	}

	public function test_cancel_after_legit_restock_does_not_falsely_hold_then_paid_re_reduces() {
		// Regression: the priority-20 marker re-assert must key off physical stock, not
		// just the gate. Here the order is legitimately restocked BEFORE it is cancelled
		// (on-hold -> pending fires WC's restock, which the cancelled-scoped gate does
		// not block), so no unit is actually held. If the re-assert falsely marked it
		// reduced, the later PAID repair's wc_maybe_reduce_stock_levels would skip the
		// re-reduction and oversell.
		list( $order, $product_id ) = $this->reduced_stock_order();

		// Legitimate restock: pending restores stock and clears the reduced marker.
		$order->update_status( 'pending' );
		$order = wc_get_order( $order->get_id() );
		$this->assertSame( 5, wc_get_product( $product_id )->get_stock_quantity() );
		$this->assertFalse( (bool) $order->get_data_store()->get_stock_reduced( $order->get_id() ) );

		// A native cancel now must NOT re-assert a reduced marker: nothing is held.
		$order->update_status( 'cancelled' );
		$order = wc_get_order( $order->get_id() );
		$this->assertFalse( (bool) $order->get_data_store()->get_stock_reduced( $order->get_id() ) );

		// Authoritative PAID must physically re-reduce stock exactly once (no oversell).
		$this->apply_status( $order, 'PAID' );
		$order = wc_get_order( $order->get_id() );
		$this->assertTrue( $order->has_status( array( 'processing', 'completed' ) ) );
		$this->assertSame( 4, wc_get_product( $product_id )->get_stock_quantity() );
		$this->assertTrue( (bool) $order->get_data_store()->get_stock_reduced( $order->get_id() ) );
	}

	public function test_paid_repair_from_cancelled_keeps_stock_reduced_exactly_once() {
		list( $order, $product_id ) = $this->reduced_stock_order();

		// A native cancellation is held locally while the backend is settling.
		$order->update_status( 'cancelled' );
		$order = wc_get_order( $order->get_id() );
		$this->assertSame( 4, wc_get_product( $product_id )->get_stock_quantity() );

		// Authoritative PAID repairs cancelled -> processing via payment_complete().
		// WooCommerce must observe the existing stock-reduced marker and neither
		// restore nor reduce the product a second time.
		$this->apply_status( $order, 'PAID' );
		$order = wc_get_order( $order->get_id() );
		$this->assertTrue( $order->has_status( array( 'processing', 'completed' ) ) );
		$this->assertSame( 4, wc_get_product( $product_id )->get_stock_quantity() );
		$this->assertTrue( (bool) $order->get_data_store()->get_stock_reduced( $order->get_id() ) );

		$this->apply_status( $order, 'PAID' );
		$this->assertSame( 4, wc_get_product( $product_id )->get_stock_quantity() );
	}

	/**
	 * Create an AinePay order holding one downloadable product, so the download
	 * permission and customer email gates can be exercised against real WooCommerce.
	 *
	 * @param string $ainepay_status Backing _ainepay_status meta ('' = unbacked).
	 * @return WC_Order
	 */
	private function downloadable_order( $ainepay_status = '' ) {
		$product = new WC_Product_Simple();
		$product->set_name( 'AinePay downloadable' );
		$product->set_regular_price( '10.00' );
		$product->set_virtual( true );
		$product->set_downloadable( true );
		$product->save();

		$order = wc_create_order();
		$order->set_payment_method( Ainepay_Plugin::GATEWAY_ID );
		$order->set_status( 'on-hold' );
		$order->update_meta_data( '_ainepay_order_id', 'OID-FULFIL-' . $order->get_id() );
		if ( '' !== $ainepay_status ) {
			$order->update_meta_data( '_ainepay_status', $ainepay_status );
		}
		$order->add_product( $product, 1 );
		$order->calculate_totals();
		$order->save();

		return wc_get_order( $order->get_id() );
	}

	public function test_unbacked_success_transition_fail_closes_customer_email_and_downloads() {
		// Real WooCommerce fires status_{to}/{from}_to_{to} (email + download grant)
		// BEFORE the generic status_changed guard can revert an unbacked promotion.
		// The use-point filters must therefore deny through WP's real hook chain.
		$order = $this->downloadable_order();
		$order->update_status( 'processing' ); // genuine WC transition, no PAID backing.
		$order = wc_get_order( $order->get_id() );

		$this->assertFalse(
			(bool) apply_filters( 'woocommerce_email_enabled_customer_processing_order', true, $order ),
			'Customer "processing" email must be suppressed for an unbacked AinePay order.'
		);
		$this->assertFalse(
			(bool) apply_filters( 'woocommerce_order_is_download_permitted', true, $order ),
			'Downloads must be denied for an unbacked AinePay order.'
		);
	}

	public function test_paid_backed_success_transition_allows_customer_email_and_downloads() {
		// Once AinePay authoritatively backs the order PAID, the same real hook chain
		// must allow the fulfilment side effects it previously held closed.
		$order = $this->downloadable_order( 'PAID' );
		$order->update_status( 'processing' );
		$order = wc_get_order( $order->get_id() );

		$this->assertTrue(
			(bool) apply_filters( 'woocommerce_email_enabled_customer_processing_order', true, $order ),
			'Customer "processing" email must be allowed once AinePay confirms PAID.'
		);
		$this->assertTrue(
			(bool) apply_filters( 'woocommerce_order_is_download_permitted', true, $order ),
			'Downloads must be permitted once AinePay confirms PAID.'
		);
	}
}
