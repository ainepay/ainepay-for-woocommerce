=== AinePay for WooCommerce ===
Contributors: ainepay
Tags: woocommerce, payment gateway, stablecoin, usdt, usdc
Requires at least: 6.2
Tested up to: 6.8
Requires PHP: 7.4
Stable tag: 0.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Accept non-custodial USDT/USDC stablecoin payments in WooCommerce through AinePay. Payment addresses are verified locally before display.

== Description ==

AinePay for WooCommerce lets your store accept **non-custodial** stablecoin payments (USDT / USDC) through [AinePay](https://ainepay.com).

When a customer chooses AinePay at checkout, the plugin creates an order with AinePay and shows the customer a payment address. Crucially, the plugin **verifies locally** — without trusting the server — that the address is deterministically derived (via CREATE2) from your own collection address. If verification fails, the address is never shown and the order is marked failed. This protects you from a tampered backend ever diverting funds.

Order status is guaranteed by active AinePay order polling. Signed webhooks speed up updates but are not the only source of truth.

= Features =

* Non-custodial USDT / USDC payments; funds settle to your own collection address.
* Local CREATE2 address verification before any address is displayed.
* HMAC-SHA256 request signing and webhook signature verification.
* Classic checkout and Cart/Checkout Blocks support.
* HPOS (High-Performance Order Storage) compatible.
* QR code, copy-to-clipboard, countdown, and live status on the order page.
* Active order polling for final consistency; signed webhooks accelerate updates.

= How payments are reused =

AinePay does not issue refunds. If a payment is late or the window expires, any
funds the customer sent remain as a reusable balance on AinePay. A logged-in
customer who places another order applies that balance automatically. Guest
orders are not linked to an account, so a guest balance is not reused across
orders.

== External services ==

This plugin connects to the AinePay API to create orders, fetch supported coins,
and query order status. It is required for the plugin to function.

* **What is sent:** your API key (in a request header), an HMAC signature, a
  timestamp, the order amount, the selected coin/chain, a site-namespaced order
  identifier, and a pseudonymous user identifier derived as
  `sha256(site + "|" + customer id)` for logged-in customers, or a
  per-order key for guests. No email address or other personal data is sent.
* **When:** when a customer places an AinePay order, when you save settings
  (to load supported coins), and when order status is queried.
* **Endpoints:** `https://api.ainepay.com` by default (configurable).
* AinePay also sends signed webhooks to `https://your-site/ainepay/notify`.

Service provider: AinePay — https://ainepay.com
Terms: https://ainepay.com/terms — Privacy: https://ainepay.com/privacy

== Installation ==

1. Upload the plugin to `/wp-content/plugins/ainepay-for-woocommerce` or install it through the Plugins screen.
2. Activate the plugin.
3. Go to WooCommerce > Settings > Payments > AinePay.
4. Enter your API Key, API Secret, Notify Secret, Merchant ID and Collection Address (from your AinePay dashboard).
5. Save to load the supported coins, then enable the coins you want to offer.
6. In your AinePay dashboard, set the notification URL to your site base (AinePay appends `/ainepay/notify`).

== Usage ==

1. Open WooCommerce > Settings > Payments > AinePay.
2. Enter your AinePay API Key, API Secret, Notify Secret, Merchant ID, Collection Address, and forwarder contract parameters.
3. Save the settings, then enable the supported coin/chain pairs you want to offer.
4. Set the AinePay dashboard notification URL to your site base URL, for example `https://example.com`. AinePay will call `https://example.com/ainepay/notify`. Webhook only accelerates updates; active polling guarantees final status.
5. Click "Test connection & configuration" to verify API reachability and local address verification.
6. Customers select AinePay at checkout, choose a coin/chain, and place the order. The plugin verifies the payment address locally before showing the address, QR code, countdown, and live status.
7. The plugin actively queries AinePay order status. Confirmed payments move the WooCommerce order to processing or completed; expired payments move it to failed.

Late payments are not refunded by AinePay. Signed-in customers can reuse their remaining balance on a later order because their identity is derived from their authenticated WordPress customer account. Guest orders use a per-order identity and cannot automatically reuse a balance; the customer should contact the store if they paid late.

= Third-party fulfilment integrations =

Do not ship or grant entitlements solely from
`woocommerce_order_status_processing` or
`woocommerce_order_status_completed`. Those hooks can be triggered by an
administrator, REST client, ERP, or another plugin before AinePay confirms PAID.

Integrations should preferably use the authoritative
`ainepay_order_paid_backed` action. Existing WooCommerce status-hook
integrations must fail closed for AinePay orders unless
`Ainepay_Order_Sync::is_paid_backed_order( $order )` returns true. This public
method is the supported contract; do not read `_ainepay_status` directly.
Callbacks on `ainepay_order_paid_backed` must be idempotent by WooCommerce order
id because process recovery can safely replay the action.

The "not paid until AinePay confirms PAID" guarantee covers the plugin payment
block, the customer email and download gates, and the `ainepay_order_paid_backed`
action; it does not rewrite WooCommerce's native status label. If someone moves
an unpaid AinePay order to processing/completed directly (bypassing payment), the
native My Account order list and order-detail title show the standard
Processing/Completed label for the short window before the async guard verifies
with AinePay and reverts the order to on-hold. No fulfilment side effect fires in
that window. Any consumer of the raw WooCommerce status must treat
`Ainepay_Order_Sync::is_paid_backed_order( $order )` as the source of truth, not
the WooCommerce status alone.

== Frequently Asked Questions ==

= Which currencies are supported? =

The store currency must be USD (or an equivalent treated 1:1 with the stablecoin amount) in this release.

= Does AinePay hold my funds? =

No. Payments go to an address deterministically derived from your own collection address; the smart contract enforces settlement to you.

= What happens if a customer pays late? =

AinePay keeps the amount as a reusable balance. A logged-in customer can place the order again to apply it; guest orders are not linked to an account and do not reuse a balance. AinePay does not issue refunds.

= How do I refund an order? =

Refunds are full-only and follow a two-step, WooCommerce-first procedure. First, open the order in WooCommerce and click Refund for the full amount; this creates the real refund object, updates your sales and stock reports, and marks the order refunded. Then issue the matching refund in the AinePay dashboard. Always do WooCommerce first: if you refund only in the AinePay dashboard, the plugin will reconcile the order status to refunded when it sees the authoritative REFUND, but that does not create a WooCommerce refund object, so your sales/net reports will not reflect it and stock is not returned. To self-heal such an order, click Refund for the full amount on the WooCommerce order page.

== Support ==

This plugin is developed and maintained by AinePay (https://ainepay.com).
For help, questions, or to report an issue, contact support@ainepay.com or open
an issue at https://github.com/ainepay/ainepay-for-woocommerce/issues.

== Changelog ==

= 0.1.0 =
* Initial release: inline payments, local CREATE2 address verification, classic + Blocks checkout, active order polling with webhook acceleration, HPOS compatibility.

== Upgrade Notice ==

= 0.1.0 =
Initial release.
