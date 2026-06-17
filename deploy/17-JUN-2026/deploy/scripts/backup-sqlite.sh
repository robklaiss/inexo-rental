#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
DB_PATH="${ROOT_DIR}/inexo_rental.sqlite3"
BACKUP_DIR="${ROOT_DIR}/deploy/backups"
STAMP="$(date +%Y%m%d-%H%M%S)"
BACKUP_PATH="${BACKUP_DIR}/inexo_rental-${STAMP}.sqlite3"

if [[ ! -f "${DB_PATH}" ]]; then
    printf 'No existe la base SQLite: %s\n' "${DB_PATH}" >&2
    exit 1
fi

mkdir -p "${BACKUP_DIR}"

if command -v sqlite3 >/dev/null 2>&1; then
    sqlite3 "${DB_PATH}" ".backup '${BACKUP_PATH}'"
else
    cp "${DB_PATH}" "${BACKUP_PATH}"
fi

printf '%s\n' "${BACKUP_PATH}"
