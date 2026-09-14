#!/usr/bin/env bash
# Packs the module into a distributable ZIP
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
OUT="$SCRIPT_DIR/heleketgateway-whmcs9.zip"
STAGE="$(mktemp -d)"
trap 'rm -rf "$STAGE"' EXIT

command -v zip &>/dev/null || { echo "Error: zip not installed (brew install zip)"; exit 1; }

mkdir -p "$STAGE/heleketgateway-whmcs9"
cd "$SCRIPT_DIR"
cp -R heleketgateway.php heleketgateway callback "$STAGE/heleketgateway-whmcs9/"

cd "$STAGE"
rm -f "$OUT"
zip -r "$OUT" heleketgateway-whmcs9 -x "*.DS_Store" -x "__MACOSX/*" >/dev/null

echo "Created: $OUT"
echo ""
echo "Installation for end users: unpack and copy the three entries into"
echo "  <whmcs>/modules/gateways/"
