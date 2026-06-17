#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
PHP_BIN="${PHP_BIN:-php}"
LOCK_FILE="${INEXO_REMINDER_LOCK_FILE:-/tmp/inexo-rental-reminders.lock}"

cd "${ROOT_DIR}"

if command -v flock >/dev/null 2>&1; then
    exec flock -n "${LOCK_FILE}" "${PHP_BIN}" scripts/send_order_reminders.php
fi

exec "${PHP_BIN}" scripts/send_order_reminders.php
