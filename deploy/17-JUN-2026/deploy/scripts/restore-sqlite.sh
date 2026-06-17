#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
DB_PATH="${ROOT_DIR}/inexo_rental.sqlite3"
SOURCE="${1:-}"
STAMP="$(date +%Y%m%d-%H%M%S)"

if [[ -z "${SOURCE}" ]]; then
    printf 'Uso: %s /ruta/al/backup.sqlite3\n' "$0" >&2
    exit 1
fi

if [[ ! -f "${SOURCE}" ]]; then
    printf 'No existe el backup: %s\n' "${SOURCE}" >&2
    exit 1
fi

if [[ -f "${DB_PATH}" ]]; then
    cp "${DB_PATH}" "${DB_PATH}.pre-restore-${STAMP}"
fi

cp "${SOURCE}" "${DB_PATH}"
printf 'Restaurado %s en %s\n' "${SOURCE}" "${DB_PATH}"
