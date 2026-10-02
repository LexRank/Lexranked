#!/usr/bin/env bash
# Build an installable ZIP of the lexranked-core plugin (no dev files).
# Output: dist/lexranked-core-<version>.zip  → WordPress: Plugins › Add New › Upload.
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
SRC="$ROOT/wordpress/plugins/lexranked-core"
VERSION="$(sed -nE "s/^ \* Version: +([0-9.]+).*/\1/p" "$SRC/lexranked-core.php")"
OUT="$ROOT/dist"
STAGE="$(mktemp -d)"
trap 'rm -rf "$STAGE"' EXIT

mkdir -p "$STAGE/lexranked-core" "$OUT"
cp "$SRC/lexranked-core.php" "$SRC/uninstall.php" "$SRC/README.md" "$STAGE/lexranked-core/"
cp -R "$SRC/src" "$STAGE/lexranked-core/src"
find "$STAGE" -name '.gitkeep' -delete

# Refuse to package anything that looks like a secret or dev artefact.
if find "$STAGE" \( -name '.env*' -o -name 'vendor' -o -name 'tests' -o -name '*.log' \) | grep -q .; then
  echo "Refusing to package: unexpected files found" >&2
  exit 1
fi
for f in $(find "$STAGE" -name '*.php'); do php -l "$f" >/dev/null; done

ZIP="$OUT/lexranked-core-$VERSION.zip"
rm -f "$ZIP"
(cd "$STAGE" && zip -qr "$ZIP" lexranked-core)
echo "$ZIP"
