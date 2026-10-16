#!/usr/bin/env bash
# php -l every plugin PHP file (getID3 excluded until Task 6 replaces it). Exit 1 on any failure.
cd "$(dirname "$0")/.."
fail=0
while IFS= read -r -d '' f; do php -l "$f" >/dev/null 2>&1 || { echo "FAIL $f"; fail=1; }; done < <(find classes lib extras/phpthumb.bmp.php extras/phpthumb.functions.php extras/progressbar.class.php wp-filebase.php thumbnail.php -name '*.php' -print0)
[ $fail -eq 0 ] && echo "lint OK"; exit $fail
