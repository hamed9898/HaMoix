#!/usr/bin/env bash
# Hamoix alternate-port installer.
# Usage:
#   curl -fsSL https://raw.githubusercontent.com/hamed9898/HaMoix/main/install-hamoix-8443.sh | sudo bash -s -- DOMAIN [EMAIL] [HTTPS_PORT]
#
# Examples:
#   ... | sudo bash -s -- panel.example.com admin@example.com
#   ... | sudo bash -s -- panel.example.com admin@example.com 9443

set -Eeuo pipefail

readonly HAMOIX_INSTALLER="https://raw.githubusercontent.com/hamed9898/HaMoix/main/install-hamoix.sh"

if [[ "${EUID}" -ne 0 ]]; then
    printf '[Hamoix][ERROR] این اسکریپت باید با دسترسی root اجرا شود.\n' >&2
    exit 1
fi

if [[ $# -lt 1 || $# -gt 3 ]]; then
    printf 'Usage: install-hamoix-8443.sh DOMAIN [EMAIL] [HTTPS_PORT]\n' >&2
    exit 2
fi

HTTPS_PORT="${3:-${HAMOIX_HTTPS_PORT:-8443}}"
if [[ ! "${HTTPS_PORT}" =~ ^[0-9]+$ ]] || (( HTTPS_PORT < 1 || HTTPS_PORT > 65535 )); then
    printf '[Hamoix][ERROR] پورت HTTPS نامعتبر است: %s\n' "${HTTPS_PORT}" >&2
    exit 2
fi

export HAMOIX_HTTPS_PORT="${HTTPS_PORT}"
# Alternate mode intentionally stays self-signed because HTTP-01 validation
# cannot use an occupied 80/443 pair or an arbitrary HTTPS port.
export HAMOIX_SSL_MODE="self-signed"

# The port is a wrapper option, not an argument understood by the main installer.
exec bash <(curl -fsSL --retry 3 "${HAMOIX_INSTALLER}") "${1}" "${2:-}"
