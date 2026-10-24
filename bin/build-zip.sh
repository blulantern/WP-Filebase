#!/usr/bin/env bash
# Build the installable plugin zip from a git ref (default: HEAD).
#   bin/build-zip.sh [ref]   ->  dist/wp-filebase-<version>.zip
# Only runtime files ship: dev tooling, tests, docs and CI configs are stripped.
set -euo pipefail
cd "$(dirname "$0")/.."
REF="${1:-HEAD}"
VERSION=$(git show "$REF:wp-filebase.php" | sed -nE "s/^\s*define\('WPFB_VERSION', '([^']+)'\);/\1/p")
[ -n "$VERSION" ] || { echo "could not read WPFB_VERSION from $REF" >&2; exit 1; }
OUT="$PWD/dist/wp-filebase-$VERSION.zip"
STAGE=$(mktemp -d)
trap 'rm -rf "$STAGE"' EXIT

git archive --format=tar --prefix=wp-filebase/ "$REF" | tar -x -C "$STAGE"
(
  cd "$STAGE/wp-filebase"
  rm -rf tests docs bin screens .superpowers \
    .gitignore .wp-env.json composer.json composer.lock package.json \
    phpcs.xml.dist phpstan.neon.dist phpunit.xml.dist playwright.config.js
)
mkdir -p dist
rm -f "$OUT"
( cd "$STAGE" && zip -qr -X "$OUT" wp-filebase )
echo "built $OUT ($(du -h "$OUT" | cut -f1), $(unzip -l "$OUT" | tail -1 | awk '{print $2}') files)"
