#!/usr/bin/env bash
# Runs the end-to-end smoke suite against the local dev site (see run.php).
set -euo pipefail
cd "$(dirname "$0")/../.."
WP_ROOT="${WP_ROOT:-/home/jfox/Projects/filebase/filebase}" BASE="${BASE:-http://127.0.0.1:8090}" php tests/smoke/run.php
