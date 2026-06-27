# AinePay for WooCommerce

Accept **non-custodial** USDT / USDC stablecoin payments in WooCommerce through [AinePay](https://ainepay.com).

When a customer chooses AinePay at checkout, the plugin creates an order with AinePay and shows a payment address — but only after **verifying locally** that the address is deterministically derived (via CREATE2) from your own collection address. If verification fails, the address is never displayed and the order is marked failed. This protects you even if the backend is compromised.

## Features

- Non-custodial USDT / USDC payments; funds settle to your collection address.
- Local CREATE2 address verification before any address is shown.
- HMAC-SHA256 request signing and webhook signature verification.
- Classic checkout **and** Cart/Checkout Blocks support.
- HPOS (High-Performance Order Storage) compatible.
- QR code, copy-to-clipboard, countdown, and live status polling.
- Active order polling for final consistency; webhooks only accelerate updates.

## Requirements

- WordPress 6.2+
- WooCommerce 7.0+
- PHP 7.4+
- Store currency: USD (this release)

## Installation (from source)

```bash
composer install --no-dev -o
```

Then copy the plugin folder into `wp-content/plugins/`, or run `bin/build.sh` to produce a release zip.

## Configuration

In **WooCommerce → Settings → Payments → AinePay**:

| Setting | Where to get it |
| --- | --- |
| API Key / API Secret | AinePay dashboard |
| Notify Secret | AinePay dashboard |
| Merchant ID | AinePay dashboard |
| Collection Address | AinePay dashboard (must be ACTIVE) |
| Forwarder Factory / Impl / Version / Chain ID | Pre-filled with Ethereum mainnet defaults |

Set your AinePay dashboard notification URL to your **site base** (e.g. `https://example.com`); AinePay appends `/ainepay/notify`.

## Usage

1. Install and activate the plugin, then open **WooCommerce → Settings → Payments → AinePay**.
2. Enter your AinePay API Key, API Secret, Notify Secret, Merchant ID, Collection Address, and forwarder contract parameters.
3. Save the settings. The plugin will load supported coin/chain pairs from AinePay; enable the pairs you want to offer at checkout.
4. In the AinePay dashboard, set the notification URL to your site base URL, for example `https://example.com`. AinePay will call `https://example.com/ainepay/notify`. Webhooks accelerate updates; active polling is the final consistency path.
5. Use **Test connection & configuration** to verify API reachability and local address verification.
6. Customers choose AinePay at checkout, select a coin/chain, and place the order. The plugin creates the AinePay order, verifies the returned address locally, and shows the address, QR code, countdown, and live status.
7. The plugin actively queries AinePay order status. When AinePay confirms payment, the WooCommerce order moves to `processing` or `completed`; expired payments move to `failed`.

Late payments are not refunded by AinePay. For signed-in customers, the remaining balance can be reused on a later order because the user identity is derived from their authenticated WordPress customer account. Guest orders use a per-order identity and cannot automatically reuse a balance; the customer should contact the store if they paid late.

## Refunds (full only)

Refunds are a **two-step, WooCommerce-first** procedure, and only full refunds
are supported:

1. **In WooCommerce first**, open the order and click **Refund** for the full
   amount. This creates the real `WC_Order_Refund` object, updates your sales and
   stock reports, and marks the order `refunded`.
2. **Then in the AinePay dashboard**, issue the matching refund. The plugin
   verifies out of band that AinePay reaches `REFUND` and closes the loop.

Always do WooCommerce first. If you refund only in the AinePay dashboard and skip
the WooCommerce step, the plugin will eventually reconcile the order to `refunded`
when it sees the authoritative `REFUND`, but that reconciliation only changes the
order status — it does **not** create a `WC_Order_Refund`, so your WooCommerce
sales/net reports will not reflect the refund and stock is not returned. To
self-heal an order refunded out of order, go to the WooCommerce order page and
click **Refund** for the full amount; that backfills the missing refund object.

## How it works

1. Customer selects AinePay and a coin/chain at checkout.
2. The plugin derives a site-namespaced `orderId` and a pseudonymous `userId` = `sha256(site|customerId)` for logged-in customers (or a per-order key for guests). No email or other personal data is sent — and because the key is the authenticated WP customer id, only the account owner can ever reuse their AinePay balance.
3. It calls `POST /api/merchant/pay`, then verifies the returned address with `Ainepay_Address_Validator` before display.
4. Status is guaranteed by active `/order` polling. Signed webhooks at `/ainepay/notify` only accelerate the refresh.

## Security model

- The signer reproduces the backend's exact `URLEncoder` semantics; the address validator reproduces the wallet's CREATE2 derivation. Both are pinned by golden vectors in [`tests/fixtures/test-vectors.json`](tests/fixtures/test-vectors.json).
- Webhook processing is serialised with a MySQL advisory lock and is idempotent on `(orderId, status, updated)`.

## Third-party fulfilment integrations

Do not fulfil an AinePay order solely because WooCommerce fired
`woocommerce_order_status_processing` or `woocommerce_order_status_completed`.
Those hooks can also be caused by an administrator, REST client, ERP, or another
plugin before AinePay has confirmed payment.

Prefer the dedicated authoritative action:

```php
add_action(
	'ainepay_order_paid_backed',
	function ( $order, $ainepay_transaction_id ) {
		// Queue an idempotent shipment/entitlement keyed by $order->get_id().
	},
	10,
	2
);
```

If an existing integration must remain on a WooCommerce status hook, fail closed
for AinePay orders:

```php
add_action(
	'woocommerce_order_status_processing',
	function ( $order_id, $order ) {
		if ( 'ainepay' === $order->get_payment_method()
			&& ! Ainepay_Order_Sync::is_paid_backed_order( $order ) ) {
			return;
		}

		// Existing idempotent fulfilment logic.
	},
	10,
	2
);
```

The public predicate is the supported integration API; third parties should not
read `_ainepay_status` directly. Callbacks on `ainepay_order_paid_backed` must be
idempotent because a process failure can cause safe replay.

### Scope of "must not show success" (native WooCommerce status)

The "an AinePay order is not paid until AinePay confirms PAID" guarantee covers
the plugin's own payment block, the customer email and download gates, and the
`ainepay_order_paid_backed` action. It does **not** rewrite WooCommerce's native
status label. If an administrator, REST client, or ERP moves an unpaid AinePay
order to `processing`/`completed` directly (bypassing payment), the order's raw
`get_status()` is `processing`/`completed` for the short window before the async
guard verifies with AinePay and reverts it to `on-hold`. During that window the
native My Account order list and order-detail title show the standard
"Processing/Completed" label even though the order is not yet backed by PAID.

This is an accepted integration constraint, not a paid signal: the guard reverts
the order, and no fulfilment side effect (email, download, `paid_backed`) fires.
Any consumer that reads the raw WooCommerce status — including native admin/My
Account screens and status-hook integrations — must therefore treat
`Ainepay_Order_Sync::is_paid_backed_order( $order )` as the source of truth, not
the WooCommerce status alone.

## Development

```bash
composer install
composer test
```

The fast suite uses isolated WordPress/WooCommerce stubs. Stock reconciliation
also has a real WooCommerce integration suite that runs once with legacy order
storage and once with HPOS:

```bash
export WP_TESTS_DIR=/tmp/wordpress-tests-lib
export WC_TESTS_DIR=/path/to/woocommerce/plugins/woocommerce
composer test:wc-integration
```

The WooCommerce source checkout must have its own test dependencies installed.
The integration runner uses WooCommerce's official `tests/legacy/bootstrap.php`
and its `DISABLE_HPOS` switch; the two storage modes run in separate PHP
processes so datastore initialization cannot leak between cases.

## License

GPL-2.0-or-later. See [LICENSE](LICENSE).

## Support

AinePay — support@ainepay.com
