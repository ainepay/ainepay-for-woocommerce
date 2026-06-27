#!/bin/sh
set -eu

root=$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)
phpunit="$root/vendor/bin/phpunit"

if [ ! -x "$phpunit" ]; then
	echo "Missing $phpunit; run composer install first." >&2
	exit 1
fi

if [ -z "${WC_TESTS_DIR:-}" ] || [ ! -f "$WC_TESTS_DIR/tests/legacy/bootstrap.php" ]; then
	echo "Set WC_TESTS_DIR to a WooCommerce source checkout with tests/legacy/bootstrap.php." >&2
	exit 1
fi

echo "Running WooCommerce integration tests with legacy order storage..."
DISABLE_HPOS=1 "$phpunit" -c "$root/phpunit.integration.xml.dist"

echo "Running WooCommerce integration tests with HPOS..."
DISABLE_HPOS= "$phpunit" -c "$root/phpunit.integration.xml.dist"
