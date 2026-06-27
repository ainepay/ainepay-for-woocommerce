<?php
/**
 * Helpers for deriving AinePay identifiers from a WooCommerce order and for
 * validating store currency / amount constraints.
 *
 * Identifier strategy:
 *   - orderId = wc_{site_hash}_{order_id}  (site-scope unique, stable)
 *   - userId  = sha256( site_namespace + "|" + customer_id )   for logged-in customers
 *               sha256( site_namespace + "|guest_order_" + order_id ) + "|"  for guests
 *               (no email or other PII is sent to AinePay; the trailing "|" flags
 *               a guest so the AinePay backend can advise on refunds — see derive_user_id)
 *
 * The userId namespace is a stable, persisted per-site token (site_namespace()),
 * NOT the merchantId and NOT the home_url-derived site_hash: one merchant may run
 * several stores under the same API key, and WP customer ids are per-site, so
 * store A's customer #42 and store B's customer #42 are different people.
 * Namespacing per site keeps their userIds — and reusable balances — separate.
 * The token is URL-independent (persisted, not derived from home_url) so an
 * http→https switch or domain move never changes a customer's userId.
 *
 * Why the WP customer id (not the billing email): the userId is the sole key
 * for AinePay balance reuse, and a billing email is never proof of ownership
 * (WooCommerce does not verify it). Keying reuse on email would let anyone
 * claim another person's left-over balance just by typing their email. The WP
 * customer id requires an authenticated session, so only the account owner can
 * reproduce their userId. Guests get a per-order id and therefore never reuse.
 *
 * Backend constraints (OrderRequest.java): orderId/userId length 5..256,
 * qty scale <= 2.
 *
 * @package AinePay\WooCommerce
 */

defined( 'ABSPATH' ) || exit;

/**
 * Stateless helpers for order identifiers and currency rules.
 */
class Ainepay_Order_Helper {

	const ID_MIN_LEN = 5;
	const ID_MAX_LEN = 256;

	/**
	 * Store currencies treated as 1:1 with the stablecoin amount.
	 * First release supports USD only; extend deliberately.
	 *
	 * @var string[]
	 */
	private static $supported_currencies = array( 'USD' );

	/**
	 * Short, stable hash identifying this site, used to namespace order ids so
	 * the merchant-scope orderId never collides across sites sharing a key.
	 *
	 * @return string 8 lowercase hex chars.
	 */
	public static function site_hash() {
		$seed = home_url( '/' );
		return substr( hash( 'sha256', $seed ), 0, 8 );
	}

	/**
	 * Option holding the persistent per-site namespace token for userId derivation.
	 */
	const SITE_NAMESPACE_OPTION = 'ainepay_site_namespace';

	/**
	 * A stable, persistent token identifying this site, used to namespace the
	 * userId so that two stores sharing one merchant/API key keep their
	 * (per-site) customer ids — and therefore their reusable balances —
	 * separate.
	 *
	 * Unlike site_hash(), this MUST NOT derive from home_url: the userId is the
	 * key for AinePay balance reuse and is recomputed on every order, so a later
	 * http→https switch or domain change would otherwise alter every logged-in
	 * customer's userId and strand their reusable balance. The token is therefore
	 * generated once and persisted, surviving URL changes. (orderId can keep
	 * using the volatile site_hash() because it is stored in order meta and
	 * looked up by that stored value, never recomputed for lookup.)
	 *
	 * @return string 16 lowercase hex chars.
	 */
	public static function site_namespace() {
		$token = get_option( self::SITE_NAMESPACE_OPTION );
		if ( self::is_valid_namespace_token( $token ) ) {
			return $token;
		}

		// First use: generate a random token once and freeze it. Random (not
		// home_url-derived) so it is immune to later URL changes, and so two
		// stores never share a namespace.
		//
		// add_option() is the atomic gate: it fails (returns false) if the row
		// already exists, so when two first orders race, only the request that
		// actually inserts the row keeps its candidate; the loser re-reads the
		// persisted token. This prevents a concurrent order from deriving its
		// userId from a one-shot, never-stored namespace (which would silently
		// break that customer's future balance reuse).
		$candidate = substr( hash( 'sha256', wp_generate_uuid4() ), 0, 16 );
		if ( add_option( self::SITE_NAMESPACE_OPTION, $candidate, '', true ) ) {
			return $candidate;
		}

		$token = get_option( self::SITE_NAMESPACE_OPTION );
		return self::is_valid_namespace_token( $token ) ? $token : $candidate;
	}

	/**
	 * Whether a stored value is a well-formed namespace token.
	 *
	 * @param mixed $token Stored option value.
	 * @return bool
	 */
	private static function is_valid_namespace_token( $token ) {
		return is_string( $token ) && 16 === strlen( $token ) && ctype_xdigit( $token );
	}

	/**
	 * Derive the AinePay orderId for a WooCommerce order id.
	 *
	 * @param int|string $wc_order_id WooCommerce order id.
	 * @return string
	 */
	public static function derive_order_id( $wc_order_id, $attempt = 0 ) {
		$base = 'wc_' . self::site_hash() . '_' . $wc_order_id;
		// Attempt suffixes are kept only for backward compatibility with older
		// orders. New payments should keep one AinePay orderId per Woo order.
		return $attempt > 0 ? $base . '_a' . $attempt : $base;
	}

	/**
	 * Derive the AinePay userId.
	 *
	 * Logged-in customers are keyed on their (authenticated) WP customer id so
	 * that balance reuse is tied to account ownership; guests get a per-order
	 * key and therefore never reuse a balance across orders. Both are hashed
	 * with a per-site namespace so no raw identifier or PII leaves the site, and
	 * the "guest_order_" prefix namespaces the two so a customer id can never
	 * collide with an order id of the same numeric value.
	 *
	 * The namespace MUST be a stable, per-site token (site_namespace()), not
	 * merchant-scoped: WP customer ids are per-site, so two stores sharing one
	 * merchant/API key would otherwise map their respective customer #42 to the
	 * same userId and let one claim the other's reusable balance. It must also
	 * be URL-independent so a later http→https or domain change does not strand
	 * customers' reusable balances. Callers pass site_namespace().
	 *
	 * @param string     $namespace   Per-site namespace (Ainepay_Order_Helper::site_namespace()).
	 * @param int|string $customer_id WP customer id (0/empty for guests).
	 * @param int|string $wc_order_id WooCommerce order id (guest fallback).
	 * @return string
	 */
	public static function derive_user_id( $namespace, $customer_id, $wc_order_id ) {
		if ( (int) $customer_id > 0 ) {
			return hash( 'sha256', $namespace . '|' . (string) (int) $customer_id );
		}
		// Guests get a per-order key and therefore never reuse a balance across
		// orders. The trailing "|" marks the identifier as a guest so the AinePay
		// backend can warn the merchant at refund time that a refunded guest balance cannot
		// be spent again (the backend does not enforce this — it is an advisory).
		// The marker is appended AFTER hashing on purpose: the hash is opaque hex
		// with no "|", so "userId contains |" is an unambiguous guest test, whereas
		// the pre-hash "guest_order_" prefix is invisible once digested.
		return hash( 'sha256', $namespace . '|guest_order_' . $wc_order_id ) . '|';
	}

	/**
	 * Whether an order's AinePay balance can be reused on a later order.
	 *
	 * Reuse requires an account-derived userId, which is recorded at order
	 * placement in the _ainepay_account_user meta (see Ainepay_Gateway::
	 * process_payment). Reading the recorded flag — rather than re-checking
	 * get_customer_id() — keeps the messaging consistent with the identity
	 * that was actually used, even if the order is later linked to an account.
	 * Falls back to the live customer id for orders placed before this meta
	 * existed.
	 *
	 * @param WC_Order $order WooCommerce order.
	 * @return bool
	 */
	public static function can_reuse_balance( $order ) {
		$flag = $order->get_meta( '_ainepay_account_user' );
		if ( '' !== (string) $flag ) {
			return '1' === (string) $flag;
		}
		return $order->get_customer_id() > 0;
	}

	/**
	 * Whether the store currency is supported for 1:1 stablecoin settlement.
	 *
	 * @param string|null $currency Currency code; defaults to store currency.
	 * @return bool
	 */
	public static function is_supported_currency( $currency = null ) {
		if ( null === $currency ) {
			$currency = function_exists( 'get_woocommerce_currency' ) ? get_woocommerce_currency() : '';
		}
		return in_array( strtoupper( (string) $currency ), self::$supported_currencies, true );
	}

	/**
	 * Format an amount as the AinePay qty string: fixed 2 decimals, dot separator.
	 *
	 * @param float|string $amount Order total.
	 * @return string
	 */
	public static function format_qty( $amount ) {
		return number_format( (float) $amount, 2, '.', '' );
	}

	/**
	 * Validate that an identifier satisfies the backend length constraints.
	 *
	 * @param string $id Identifier.
	 * @return bool
	 */
	public static function is_valid_identifier( $id ) {
		$len = strlen( (string) $id );
		return $len >= self::ID_MIN_LEN && $len <= self::ID_MAX_LEN;
	}
}
