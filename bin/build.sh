#!/usr/bin/env bash
#
# Build a distributable AinePay for WooCommerce plugin zip.
#
# Produces dist/ainepay-for-woocommerce.zip containing only runtime files:
# production Composer dependencies are vendored; dev, docs, tests and tooling
# are stripped (WordPress.org must not require users to run Composer).
#
set -euo pipefail

SLUG="ainepay-for-woocommerce"
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
BUILD_DIR="${ROOT}/dist/${SLUG}"
ZIP_PATH="${ROOT}/dist/${SLUG}.zip"

echo "==> Cleaning previous build"
rm -rf "${ROOT}/dist"
mkdir -p "${BUILD_DIR}"

echo "==> Installing production Composer dependencies"
# Prefer a global `composer`; fall back to a local `composer.phar` run through
# PHP. Either way Composer is required to vendor the runtime dependencies.
if command -v composer >/dev/null 2>&1; then
  COMPOSER_CMD=(composer)
elif [ -f "${ROOT}/composer.phar" ]; then
  if command -v php >/dev/null 2>&1; then
    COMPOSER_CMD=(php "${ROOT}/composer.phar")
  else
    echo "ERROR: found composer.phar but no 'php' on PATH to run it." >&2
    echo "       Install PHP, or run this build where 'composer' is available." >&2
    exit 1
  fi
else
  echo "ERROR: Composer not found. Install 'composer' (https://getcomposer.org)" >&2
  echo "       or place 'composer.phar' in the project root, then re-run." >&2
  exit 1
fi
echo "    using: ${COMPOSER_CMD[*]}"
( cd "${ROOT}" && "${COMPOSER_CMD[@]}" install --no-dev --optimize-autoloader --no-interaction )

echo "==> Copying runtime files"
cp -R \
  "${ROOT}/ainepay-for-woocommerce.php" \
  "${ROOT}/uninstall.php" \
  "${ROOT}/readme.txt" \
  "${ROOT}/README.md" \
  "${ROOT}/LICENSE" \
  "${ROOT}/composer.json" \
  "${ROOT}/includes" \
  "${ROOT}/templates" \
  "${ROOT}/assets" \
  "${ROOT}/languages" \
  "${ROOT}/vendor" \
  "${BUILD_DIR}/"

echo "==> Stripping non-runtime artifacts from vendor"
find "${BUILD_DIR}/vendor" -type d \( -name 'tests' -o -name 'test' -o -name 'docs' \) -prune -exec rm -rf {} + 2>/dev/null || true
find "${BUILD_DIR}/vendor" -type f \( -name '*.md' -o -name 'phpunit.xml*' -o -name '.gitignore' \) -delete 2>/dev/null || true

echo "==> Creating zip"
( cd "${ROOT}/dist" && zip -rq "${ZIP_PATH}" "${SLUG}" )

echo "==> Done: ${ZIP_PATH}"
