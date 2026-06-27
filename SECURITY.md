# Security Policy

## Reporting a vulnerability

Please report security issues privately to **support@ainepay.com**. Do not open a
public issue for security reports. We aim to acknowledge reports within a few
business days.

## Security model

- **Local address verification.** The plugin never displays a payment address
  until it has independently re-derived it (CREATE2) from the merchant's
  collection address. A tampered backend cannot divert funds because a mismatch
  causes the order to fail and the address to be withheld.
- **Signed requests.** API requests are signed with HMAC-SHA256; the signing
  secret is stored server-side and never transmitted.
- **Signed webhooks.** Inbound notifications are verified against the notify
  secret with a timestamp window to prevent replay.
- **Idempotent, serialised processing.** Notification handling is serialised
  with a MySQL advisory lock and de-duplicated on `(orderId, status, updated)`.
- **Data minimisation.** The customer's email is never sent to AinePay; only a
  one-way, site-namespaced `sha256(site|customer id)` pseudonym is used (a
  per-order key for guests).
