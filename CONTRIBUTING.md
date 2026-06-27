# Contributing

Thanks for your interest in improving AinePay for WooCommerce.

## Development setup

```bash
composer install
vendor/bin/phpunit
```

The signer and the CREATE2 address validator are pinned by golden vectors in
`tests/fixtures/test-vectors.json`. If you change either, regenerate the
vectors and ensure the tests still pass:

```bash
# from a checkout that has the `ethers` package available
node tests/fixtures/gen-vectors.js > tests/fixtures/test-vectors.json
```

## Coding standards

- Follow the WordPress Coding Standards (PHPCS `WordPress` ruleset).
- Escape on output (`esc_html`, `esc_attr`, `esc_url`), sanitize on input, and
  verify nonces / capabilities for any privileged action.
- Never log secrets, signatures, or signing payloads (`Ainepay_Logger` redacts
  known sensitive keys).

## Pull requests

- Keep changes focused and include tests where practical.
- Describe user-facing changes in the PR so they can be added to the changelog.

## Reporting security issues

See [SECURITY.md](SECURITY.md). Email **support@ainepay.com** privately.
