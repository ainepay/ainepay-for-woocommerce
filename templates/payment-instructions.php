<?php
/**
 * AinePay payment instructions.
 *
 * This template can be overridden by copying it to
 * yourtheme/ainepay/payment-instructions.php
 *
 * @package AinePay\WooCommerce
 *
 * @var WC_Order $order
 * @var string   $address
 * @var string   $coin
 * @var string   $chain
 * @var string   $qty
 * @var int      $pay_expired
 * @var string   $status
 * @var bool     $is_final
 * @var bool     $can_reuse
 * @var string   $qr_svg
 */

defined( 'ABSPATH' ) || exit;

$qr_svg = isset( $qr_svg ) ? $qr_svg : '';

// Derive a single explicit state from the WooCommerce order status. Only an
// order still awaiting payment should show the payment address/QR; once it is
// paid or the window has expired/failed, the address is hidden and we show a
// clear status badge instead.
// "paid" requires an authoritative AinePay PAID backing, not just a WC success
// status: an order promoted to processing/completed without AinePay
// confirming payment must never be presented as paid.
$paid_backed = $order->has_status( array( 'completed', 'processing' ) )
	&& 'PAID' === strtoupper( (string) $order->get_meta( '_ainepay_status' ) );
if ( $paid_backed ) {
	$state       = 'paid';
	$state_label = __( 'Payment confirmed', 'ainepay-for-woocommerce' );
} elseif ( $order->has_status( 'refunded' ) ) {
	$state       = 'refunded';
	$state_label = __( 'Refunded', 'ainepay-for-woocommerce' );
} elseif ( $order->has_status( 'cancelled' ) ) {
	$state       = 'cancelled';
	$state_label = __( 'Order cancelled', 'ainepay-for-woocommerce' );
} elseif ( $order->has_status( 'failed' ) ) {
	$state       = 'expired';
	$state_label = __( 'Payment window expired', 'ainepay-for-woocommerce' );
} elseif ( $order->has_status( array( 'processing', 'completed' ) ) ) {
	// Unbacked success state: WC shows paid but AinePay has not confirmed
	// it. Never reveal the pay address here — that would invite a double payment —
	// and never claim "paid". Show a neutral "confirming" badge while the async
	// guard verifies (and the page keeps polling for the resolution).
	$state       = 'verifying';
	$state_label = __( 'Confirming payment…', 'ainepay-for-woocommerce' );
} else {
	$state       = 'awaiting';
	$state_label = __( 'Awaiting payment', 'ainepay-for-woocommerce' );
}
$show_address = ( 'awaiting' === $state ) && '' !== (string) $address;
?>
<section class="ainepay-instructions ainepay-state-<?php echo esc_attr( $state ); ?>"
	data-order-id="<?php echo esc_attr( $order->get_id() ); ?>"
	data-order-key="<?php echo esc_attr( $order->get_order_key() ); ?>"
	data-address="<?php echo esc_attr( $show_address ? $address : '' ); ?>"
	data-expires="<?php echo esc_attr( $pay_expired ); ?>"
	data-state="<?php echo esc_attr( $state ); ?>"
	data-final="<?php echo $is_final ? '1' : '0'; ?>">

	<h2><?php esc_html_e( 'Pay with AinePay', 'ainepay-for-woocommerce' ); ?></h2>

	<p class="ainepay-badge ainepay-badge-<?php echo esc_attr( $state ); ?>" role="status" aria-live="polite">
		<?php echo esc_html( $state_label ); ?>
	</p>

	<?php if ( $show_address ) : ?>
		<p class="ainepay-amount">
			<?php
			printf(
				/* translators: 1: amount, 2: coin, 3: chain. */
				esc_html__( 'Send exactly %1$s %2$s on the %3$s network to the address below.', 'ainepay-for-woocommerce' ),
				'<strong>' . esc_html( $qty ) . '</strong>',
				esc_html( $coin ),
				esc_html( $chain )
			);
			?>
		</p>

		<?php if ( '' !== $qr_svg ) : ?>
			<div class="ainepay-qr" aria-hidden="true">
				<?php echo $qr_svg; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- trusted inline SVG from BaconQrCode. ?>
			</div>
		<?php endif; ?>

		<div class="ainepay-address-row">
			<code class="ainepay-address"><?php echo esc_html( $address ); ?></code>
			<button type="button" class="button ainepay-copy" data-clipboard="<?php echo esc_attr( $address ); ?>">
				<?php esc_html_e( 'Copy', 'ainepay-for-woocommerce' ); ?>
			</button>
		</div>

		<p class="ainepay-countdown" <?php echo $pay_expired > 0 ? '' : 'style="display:none"'; ?>>
			<?php esc_html_e( 'Time remaining:', 'ainepay-for-woocommerce' ); ?>
			<span class="ainepay-countdown-value">—</span>
		</p>
	<?php endif; ?>

	<p class="ainepay-status" role="status" aria-live="polite"></p>

	<?php
	// Only offer cancel while the order is genuinely cancellable: still awaiting and
	// not yet moved past INIT at AinePay. Once the backing records PENDING (on-chain
	// payment seen) the backend would reject the cancel, so hiding the button avoids
	// a doomed click. The endpoint re-checks the same predicate as defence in depth.
	$can_cancel = ( 'awaiting' === $state )
		&& class_exists( 'Ainepay_Order_Sync' )
		&& Ainepay_Order_Sync::is_locally_cancellable( $order );
	?>
	<?php if ( $can_cancel ) : ?>
		<div class="ainepay-cancel-row">
			<button type="button" class="button ainepay-cancel">
				<?php esc_html_e( 'Cancel this order', 'ainepay-for-woocommerce' ); ?>
			</button>
			<span class="ainepay-cancel-result" role="status" aria-live="polite"></span>
		</div>
	<?php endif; ?>

	<p class="ainepay-note">
		<?php
		if ( 'paid' === $state ) {
			esc_html_e( 'Your payment has been confirmed. Thank you!', 'ainepay-for-woocommerce' );
		} elseif ( 'verifying' === $state ) {
			esc_html_e( 'We’re confirming your payment with AinePay. This page will update automatically — please don’t send another payment.', 'ainepay-for-woocommerce' );
		} elseif ( 'refunded' === $state ) {
			esc_html_e( 'This order has been refunded. The refund is processed through AinePay; please allow some time for it to arrive.', 'ainepay-for-woocommerce' );
		} elseif ( 'cancelled' === $state ) {
			esc_html_e( 'This order has been cancelled. If you would like to buy again, please place a new order.', 'ainepay-for-woocommerce' );
		} elseif ( 'expired' === $state ) {
			if ( ! empty( $can_reuse ) ) {
				esc_html_e( 'This payment window has expired. AinePay does not issue refunds; any funds you sent are kept as a balance on your AinePay account and can be reused — place the order again while signed in to apply it.', 'ainepay-for-woocommerce' );
			} else {
				esc_html_e( 'This payment window has expired. AinePay does not issue refunds; if you paid late, the funds are held by AinePay — contact the store to recover them.', 'ainepay-for-woocommerce' );
			}
		} elseif ( ! empty( $can_reuse ) ) {
			esc_html_e( 'AinePay does not issue refunds. If a payment is late or the window expires, any funds you sent are kept as a balance on your AinePay account and can be reused — simply place the order again while signed in to apply it.', 'ainepay-for-woocommerce' );
		} else {
			esc_html_e( 'AinePay does not issue refunds. Please pay before the window expires. If you pay late, the funds are held by AinePay; contact the store to recover them.', 'ainepay-for-woocommerce' );
		}
		?>
	</p>
</section>
