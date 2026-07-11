#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
SLUG="ainepay-for-woocommerce"
BUILD_DIR="${ROOT}/dist/${SLUG}"
ZIP_PATH="${ROOT}/dist/${SLUG}.zip"
MAIN_FILE="${ROOT}/ainepay-for-woocommerce.php"
README_FILE="${ROOT}/readme.txt"

php_header_version="$(sed -nE 's/^ \* Version:[[:space:]]+([^[:space:]]+).*/\1/p' "${MAIN_FILE}")"
constant_version="$(sed -nE "s/^define\( 'AINEPAY_WC_VERSION', '([^']+)' \);/\1/p" "${MAIN_FILE}")"
stable_tag="$(sed -nE 's/^Stable tag:[[:space:]]+([^[:space:]]+).*/\1/p' "${README_FILE}")"

if [[ -z "${php_header_version}" || "${php_header_version}" != "${constant_version}" || "${php_header_version}" != "${stable_tag}" ]]; then
	echo "ERROR: release versions differ: header=${php_header_version:-missing}, constant=${constant_version:-missing}, stable_tag=${stable_tag:-missing}" >&2
	exit 1
fi

if [[ ! -d "${BUILD_DIR}" || ! -f "${ZIP_PATH}" ]]; then
	echo "ERROR: release artifact missing; run bin/build.sh first." >&2
	exit 1
fi

required=(ainepay-for-woocommerce.php uninstall.php readme.txt LICENSE composer.json includes templates assets languages vendor)
for entry in "${required[@]}"; do
	[[ -e "${BUILD_DIR}/${entry}" ]] || { echo "ERROR: package missing ${entry}" >&2; exit 1; }
done

forbidden_pattern='(^|/)(\.git|\.github|tests?|integration-tests|log|logs|review|node_modules)(/|$)|(^|/)(composer\.lock|phpunit[^/]*|phpcs\.xml[^/]*|composer\.phar|\.env[^/]*)$|\.(log|cache)$'
if find "${BUILD_DIR}" -mindepth 1 -print | sed "s#^${BUILD_DIR}/##" | grep -E "${forbidden_pattern}"; then
	echo "ERROR: package contains development or sensitive artifacts." >&2
	exit 1
fi

top_levels="$(unzip -Z1 "${ZIP_PATH}" | cut -d/ -f1 | sort -u)"
if [[ "${top_levels}" != "${SLUG}" ]]; then
	echo "ERROR: ZIP must contain exactly one top-level directory named ${SLUG}." >&2
	exit 1
fi

echo "Release verification passed for ${SLUG} ${php_header_version}."
shasum -a 256 "${ZIP_PATH}"
