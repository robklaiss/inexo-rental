#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
RELEASE_DIR="${ROOT_DIR}/deploy/releases"
STAMP="$(date +%Y%m%d-%H%M%S)"
NAME="inexo-rental-${STAMP}"
STAGING_DIR="${RELEASE_DIR}/${NAME}"
ARCHIVE="${RELEASE_DIR}/${NAME}.tar.gz"

mkdir -p "${RELEASE_DIR}" "${STAGING_DIR}"

copy_path() {
    local path="$1"
    if [[ -e "${ROOT_DIR}/${path}" ]]; then
        mkdir -p "${STAGING_DIR}/$(dirname "${path}")"
        cp -R "${ROOT_DIR}/${path}" "${STAGING_DIR}/${path}"
    fi
}

copy_path ".htaccess"
copy_path "index.php"
copy_path "router.php"
copy_path "app.css"
copy_path "app.js"
copy_path "README.md"
copy_path "assets"
copy_path "inexo-rental---tu-partner-en-cada-obra.webflow"
copy_path "uploads"
copy_path "inexo_rental.sqlite3"
copy_path "requirements.txt"
copy_path "scripts"
copy_path "tests"

if [[ "${INCLUDE_DATA:-0}" == "1" ]]; then
    copy_path "data"
fi

find "${STAGING_DIR}" -name ".DS_Store" -delete
find "${STAGING_DIR}" -name "*.backup*" -delete
find "${STAGING_DIR}" -name "*.bak" -delete
find "${STAGING_DIR}" -name "mail.log" -delete

tar -C "${RELEASE_DIR}" -czf "${ARCHIVE}" "${NAME}"
rm -rf "${STAGING_DIR}"

printf '%s\n' "${ARCHIVE}"
