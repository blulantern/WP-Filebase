#!/usr/bin/env bash
# Quality gates for WP-Filebase. Usage: bin/gates.sh [lint|compat|stan|unit|all]
set -euo pipefail
cd "$(dirname "$0")/.."
# Tooling comes from this plugin's own `composer install` when present, else from the sibling SecureFilebase checkout.
if [ -x "$PWD/vendor/bin/phpcs" ]; then V="$PWD/vendor"; else V="$PWD/../secure-filebase/vendor"; fi
TOOLS="${WPFB_TOOLS:-$V/bin}"
STUBS="${WPFB_STUBS:-$V/php-stubs/wordpress-stubs/wordpress-stubs.php}"
AUTOLOAD="${WPFB_AUTOLOAD:-$V/autoload.php}"

lint() {
  echo "== php -l"
  local fail=0
  while IFS= read -r -d '' f; do
    php -l "$f" >/dev/null 2>&1 || { echo "FAIL $f"; fail=1; }
  done < <(find . -name '*.php' -not -path './node_modules/*' -not -path './vendor/*' -not -path './tests/smoke/out/*' -not -path './.superpowers/*' -print0)
  [ "$fail" -eq 0 ] && echo "lint OK"
  return $fail
}
compat() {
  echo "== PHPCompatibilityWP 8.1-"
  "$TOOLS/phpcs" -q --report=summary . && echo "compat OK"
}
stan() {
  echo "== PHPStan level 1"
  sed "s#%rootDir%/../../php-stubs/wordpress-stubs/wordpress-stubs.php#$STUBS#" phpstan.neon.dist > phpstan.neon
  "$TOOLS/phpstan" analyse -c phpstan.neon --autoload-file="$AUTOLOAD" --memory-limit=2G --no-progress && echo "stan OK"
}
unit() {
  echo "== PHPUnit unit suite"
  "$TOOLS/phpunit" --testsuite unit
}
case "${1:-all}" in
  lint) lint ;; compat) compat ;; stan) stan ;; unit) unit ;;
  all) lint && compat && stan && unit ;;
  *) echo "usage: $0 [lint|compat|stan|unit|all]"; exit 2 ;;
esac
