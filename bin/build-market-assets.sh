#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
MARKET_DIR="${ROOT}/.wordpress-org"
SOURCE_DIR="${MARKET_DIR}/source"

command -v rsvg-convert >/dev/null || { echo "ERROR: rsvg-convert is required." >&2; exit 1; }

rsvg-convert -w 128 -h 128 "${SOURCE_DIR}/icon.svg" -o "${MARKET_DIR}/icon-128x128.png"
rsvg-convert -w 256 -h 256 "${SOURCE_DIR}/icon.svg" -o "${MARKET_DIR}/icon-256x256.png"

echo "WordPress.org icons rebuilt from the canonical AinePay logo."
