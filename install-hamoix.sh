#!/usr/bin/env bash
# Hamoix one-command VPS installer for Ubuntu/Debian.
# Default usage:
#   curl -fsSL https://raw.githubusercontent.com/hamed9898/HaMoix/main/install-hamoix.sh | sudo bash -s -- panel.example.com admin@example.com
#
# The script installs the runtime, MariaDB, Nginx, Composer and Certbot,
# clones the Hamoix source, creates a dedicated database/user, configures
# the domain, enables HTTPS, and leaves the final admin setup to /installer/.
#
# Alternate HTTPS ports can use:
#   HAMOIX_HTTPS_PORT=8443 HAMOIX_SSL_MODE=self-signed
# The dedicated install-hamoix-8443.sh wrapper sets these safely for you.

set -Eeuo pipefail

readonly HAMOIX_REPO="hamed9898/Hamoix"
readonly HAMOIX_GITHUB="https://github.com/${HAMOIX_REPO}"
readonly APP_DIR="${HAMOIX_APP_DIR:-/var/www/hamoix}"
readonly PHP_VERSION="${HAMOIX_PHP_VERSION:-8.2}"
readonly HTTP_PORT="${HAMOIX_HTTP_PORT:-80}"
readonly HTTPS_PORT="${HAMOIX_HTTPS_PORT:-443}"
readonly SSL_MODE="${HAMOIX_SSL_MODE:-letsencrypt}"
readonly DB_NAME="${HAMOIX_DB_NAME:-hamoix}"
readonly DB_USER="${HAMOIX_DB_USER:-hamoix}"
readonly DB_CREDENTIALS_FILE="${HAMOIX_DB_CREDENTIALS_FILE:-/root/.hamoix-db-credentials}"

log() { printf '[Hamoix] %s\n' "$*"; }
fail() { printf '[Hamoix][ERROR] %s\n' "$*" >&2; exit 1; }

usage() {
    cat >&2 <<'USAGE'
Usage:
  install-hamoix.sh DOMAIN [EMAIL]

Example:
  install-hamoix.sh panel.example.com admin@example.com

DOMAIN must already point to this server. EMAIL is optional; when omitted,
Certbot registers without renewal notices.
USAGE
    exit 2
}

[[ "${EUID}" -eq 0 ]] || fail "این اسکریپت باید با دسترسی root اجرا شود."
[[ -f /etc/os-release ]] || fail "سیستم‌عامل شناسایی نشد."
source /etc/os-release
case "${ID:-}" in
    ubuntu|debian) ;;
    *) fail "این نصب‌کننده برای Ubuntu و Debian آماده شده است." ;;
esac

[[ $# -ge 1 && $# -le 2 ]] || usage
DOMAIN="${1#https://}"
DOMAIN="${DOMAIN#http://}"
DOMAIN="${DOMAIN%%/*}"
EMAIL="${2:-}"
[[ "${DOMAIN}" =~ ^[A-Za-z0-9][A-Za-z0-9.-]*[A-Za-z0-9]$ ]] || fail "دامنه نامعتبر است: ${DOMAIN}"
[[ "${DOMAIN}" != *..* ]] || fail "دامنه نامعتبر است: ${DOMAIN}"
[[ "${DB_NAME}" =~ ^[A-Za-z0-9_]+$ ]] || fail "نام دیتابیس فقط می‌تواند شامل حروف، عدد و _ باشد."
[[ "${DB_USER}" =~ ^[A-Za-z0-9_]+$ ]] || fail "نام کاربر دیتابیس فقط می‌تواند شامل حروف، عدد و _ باشد."
[[ "${HTTP_PORT}" =~ ^[0-9]+$ ]] && (( HTTP_PORT >= 1 && HTTP_PORT <= 65535 )) || fail "پورت HTTP نامعتبر است: ${HTTP_PORT}"
[[ "${HTTPS_PORT}" =~ ^[0-9]+$ ]] && (( HTTPS_PORT >= 1 && HTTPS_PORT <= 65535 )) || fail "پورت HTTPS نامعتبر است: ${HTTPS_PORT}"
case "${SSL_MODE}" in
    letsencrypt|self-signed) ;;
    *) fail "حالت SSL نامعتبر است؛ فقط letsencrypt یا self-signed مجاز است." ;;
esac
if [[ "${SSL_MODE}" == "letsencrypt" && ( "${HTTPS_PORT}" != "443" || "${HTTP_PORT}" != "80" ) ]]; then
    fail "Let's Encrypt خودکار به پورت‌های 80 و 443 نیاز دارد؛ برای پورت جایگزین از SSL_MODE=self-signed استفاده کنید."
fi

port_is_listening() {
    command -v ss >/dev/null 2>&1 || return 1
    ss -H -ltn 2>/dev/null | awk '{print $4}' | grep -Eq ":${1}$"
}

if [[ -e "${APP_DIR}" ]] && find "${APP_DIR}" -mindepth 1 -maxdepth 1 -print -quit | grep -q .; then
    fail "مسیر ${APP_DIR} خالی نیست؛ برای جلوگیری از حذف/بازنویسی اطلاعات متوقف شد."
fi

log "بررسی DNS دامنه ${DOMAIN}..."
getent ahosts "${DOMAIN}" >/dev/null || fail "دامنه به این سرور resolve نمی‌شود؛ رکورد A/AAAA و DNS را بررسی کنید."

export DEBIAN_FRONTEND=noninteractive

# Nginx must not be auto-started while apt is installing it: on alternate
# installs another panel may already own ports 80/443. Services are started
# explicitly after the Hamoix site is configured.
readonly POLICY_RC_D="/usr/sbin/policy-rc.d"
policy_rc_d_created=0
restore_policy_rc_d() {
    if (( policy_rc_d_created == 1 )); then
        rm -f "${POLICY_RC_D}"
        policy_rc_d_created=0
    fi
}
if [[ ! -e "${POLICY_RC_D}" ]]; then
    printf '#!/bin/sh\nexit 101\n' > "${POLICY_RC_D}"
    chmod 755 "${POLICY_RC_D}"
    policy_rc_d_created=1
    trap restore_policy_rc_d EXIT
fi

log "به‌روزرسانی مخازن و نصب پیش‌نیازها..."
apt-get update

# Ubuntu 22.04 ships PHP 8.1 by default, while Hamoix requires PHP 8.2+.
# The maintained Ondrej repository provides PHP 8.2 packages on Ubuntu.
if [[ "${ID}" == "ubuntu" ]]; then
    apt-get install -y software-properties-common
    add-apt-repository -y ppa:ondrej/php
    apt-get update
    PHP_PACKAGE_PREFIX="php${PHP_VERSION}"
else
    PHP_PACKAGE_PREFIX="php"
fi

apt-get install -y \
    nginx mariadb-server mariadb-client composer certbot python3-certbot-nginx \
    git curl ca-certificates unzip cron openssl iproute2 \
    "${PHP_PACKAGE_PREFIX}-fpm" "${PHP_PACKAGE_PREFIX}-cli" "${PHP_PACKAGE_PREFIX}-mysql" \
    "${PHP_PACKAGE_PREFIX}-curl" "${PHP_PACKAGE_PREFIX}-gd" "${PHP_PACKAGE_PREFIX}-zip" \
    "${PHP_PACKAGE_PREFIX}-intl" "${PHP_PACKAGE_PREFIX}-mbstring" \
    "${PHP_PACKAGE_PREFIX}-xml" "${PHP_PACKAGE_PREFIX}-bcmath" "${PHP_PACKAGE_PREFIX}-opcache"
restore_policy_rc_d
trap - EXIT

if [[ "${SSL_MODE}" == "self-signed" ]] && port_is_listening "${HTTPS_PORT}"; then
    fail "پورت HTTPS ${HTTPS_PORT} در حال استفاده است؛ یک پورت آزاد انتخاب کنید."
fi

PHP_BIN="$(command -v php)"
PHP_RUNTIME_VERSION="$(${PHP_BIN} -r 'echo PHP_MAJOR_VERSION . "." . PHP_MINOR_VERSION;')"
if ! "${PHP_BIN}" -r 'exit(version_compare(PHP_VERSION, "8.2.0", ">=") ? 0 : 1);'; then
    fail "PHP ${PHP_RUNTIME_VERSION} نصب شد، اما Hamoix به PHP 8.2 یا بالاتر نیاز دارد."
fi
PHP_FPM_SERVICE="php${PHP_RUNTIME_VERSION}-fpm"
PHP_FPM_SOCKET="/run/php/${PHP_FPM_SERVICE}.sock"
[[ -S "${PHP_FPM_SOCKET}" || -e "${PHP_FPM_SOCKET}" ]] || log "سوکت PHP-FPM بعد از راه‌اندازی سرویس ایجاد می‌شود: ${PHP_FPM_SOCKET}"

log "راه‌اندازی MariaDB و ساخت دیتابیس اختصاصی Hamoix..."
systemctl enable --now mariadb

if [[ -n "${HAMOIX_DB_PASSWORD:-}" ]]; then
    DB_PASSWORD="${HAMOIX_DB_PASSWORD}"
elif [[ -f "${DB_CREDENTIALS_FILE}" ]]; then
    DB_PASSWORD="$(awk -F= '$1 == "DB_PASSWORD" {print substr($0, index($0, "=") + 1)}' "${DB_CREDENTIALS_FILE}")"
else
    DB_PASSWORD="$(openssl rand -hex 24)"
fi
[[ "${DB_PASSWORD}" =~ ^[A-Za-z0-9._-]{12,128}$ ]] || fail "رمز دیتابیس باید ۱۲ تا ۱۲۸ کاراکتر امن داشته باشد."

mariadb --protocol=socket <<SQL
CREATE DATABASE IF NOT EXISTS \`${DB_NAME}\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS '${DB_USER}'@'localhost' IDENTIFIED BY '${DB_PASSWORD}';
ALTER USER '${DB_USER}'@'localhost' IDENTIFIED BY '${DB_PASSWORD}';
GRANT ALL PRIVILEGES ON \`${DB_NAME}\`.* TO '${DB_USER}'@'localhost';
FLUSH PRIVILEGES;
SQL

umask 077
printf 'DB_NAME=%s\nDB_USER=%s\nDB_PASSWORD=%s\n' "${DB_NAME}" "${DB_USER}" "${DB_PASSWORD}" > "${DB_CREDENTIALS_FILE}"
chmod 600 "${DB_CREDENTIALS_FILE}"

tmp_dir="$(mktemp -d)"
trap 'rm -rf -- "${tmp_dir}"' EXIT
log "کلون کردن آخرین سورس Hamoix..."
git clone --depth 1 --branch main "${HAMOIX_GITHUB}.git" "${tmp_dir}/source"
[[ -f "${tmp_dir}/source/index.php" ]] || fail "سورس Hamoix معتبر نیست."
mkdir -p "${APP_DIR}"
cp -a "${tmp_dir}/source/." "${APP_DIR}/"

cd "${APP_DIR}"
log "نصب وابستگی‌های Composer..."
COMPOSER_ALLOW_SUPERUSER=1 composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader
mkdir -p logs storage/cache
chown -R www-data:www-data "${APP_DIR}"
find "${APP_DIR}" -type d -exec chmod 755 {} +
find "${APP_DIR}" -type f -exec chmod 644 {} +

NGINX_SITE="/etc/nginx/sites-available/hamoix-${DOMAIN}.conf"
log "تنظیم Nginx برای ${DOMAIN} روی پورت HTTPS ${HTTPS_PORT}..."

if [[ "${SSL_MODE}" == "self-signed" ]]; then
    SSL_CERT="/etc/ssl/certs/hamoix-${DOMAIN}.crt"
    SSL_KEY="/etc/ssl/private/hamoix-${DOMAIN}.key"
    if [[ ! -s "${SSL_CERT}" || ! -s "${SSL_KEY}" ]]; then
        log "ساخت گواهی self-signed برای پورت جایگزین..."
        openssl req -x509 -nodes -newkey rsa:2048 -days 825 \
            -keyout "${SSL_KEY}" -out "${SSL_CERT}" \
            -subj "/CN=${DOMAIN}" -addext "subjectAltName=DNS:${DOMAIN}"
        chmod 600 "${SSL_KEY}"
        chmod 644 "${SSL_CERT}"
        chown root:root "${SSL_KEY}" "${SSL_CERT}"
    fi

    cat > "${NGINX_SITE}" <<NGINX
server {
    listen ${HTTPS_PORT} ssl;
    server_name ${DOMAIN};
    root ${APP_DIR};
    index index.php;
    client_max_body_size 32M;
    ssl_certificate ${SSL_CERT};
    ssl_certificate_key ${SSL_KEY};
    ssl_protocols TLSv1.2 TLSv1.3;

    location / {
        try_files \$uri \$uri/ /index.php?\$query_string;
    }

    location ~ \.php$ {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:${PHP_FPM_SOCKET};
    }

    location ~* ^/(vendor|storage|logs)/ {
        deny all;
        return 404;
    }

    location ~ /\.git {
        deny all;
        return 404;
    }

    location ~ /\.ht {
        deny all;
    }
}
NGINX
else
    cat > "${NGINX_SITE}" <<NGINX
server {
    listen ${HTTP_PORT};
    server_name ${DOMAIN};
    root ${APP_DIR};
    index index.php;
    client_max_body_size 32M;

    location / {
        try_files \$uri \$uri/ /index.php?\$query_string;
    }

    location ~ \.php$ {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:${PHP_FPM_SOCKET};
    }

    location ~* ^/(vendor|storage|logs)/ {
        deny all;
        return 404;
    }

    location ~ /\.git {
        deny all;
        return 404;
    }

    location ~ /\.ht {
        deny all;
    }
}
NGINX
fi

ln -sfn "${NGINX_SITE}" "/etc/nginx/sites-enabled/hamoix-${DOMAIN}.conf"
# The Debian/Ubuntu default site listens on port 80. Disable only its enabled
# link so an existing service can keep 80/443 while Hamoix uses the alternate port.
rm -f /etc/nginx/sites-enabled/default

allow_tcp_port() {
    local port="$1"
    if command -v ufw >/dev/null 2>&1 && ufw status 2>/dev/null | grep -q '^Status: active'; then
        ufw allow "${port}/tcp" >/dev/null
        log "پورت TCP ${port} در UFW باز شد."
    fi
}

if [[ "${SSL_MODE}" == "self-signed" ]]; then
    allow_tcp_port "${HTTPS_PORT}"
else
    allow_tcp_port "${HTTP_PORT}"
    allow_tcp_port "${HTTPS_PORT}"
fi

systemctl enable --now "${PHP_FPM_SERVICE}" nginx cron
nginx -t
systemctl reload nginx

if [[ "${SSL_MODE}" == "letsencrypt" ]]; then
    if [[ -n "${EMAIL}" ]]; then
        log "دریافت گواهی HTTPS از Let's Encrypt..."
        certbot --nginx --non-interactive --agree-tos --redirect --no-eff-email -m "${EMAIL}" -d "${DOMAIN}"
    else
        log "دریافت گواهی HTTPS از Let's Encrypt بدون ایمیل..."
        certbot --nginx --non-interactive --agree-tos --redirect --register-unsafely-without-email --no-eff-email -d "${DOMAIN}"
    fi
fi
nginx -t
systemctl reload nginx

CRON_LINE="* * * * * ${PHP_BIN} ${APP_DIR}/cron/cron.php >/dev/null 2>&1"
( crontab -u www-data -l 2>/dev/null | grep -vF "${APP_DIR}/cron/cron.php" || true; echo "${CRON_LINE}" ) | crontab -u www-data -

log "نصب Hamoix با موفقیت انجام شد."
if [[ "${HTTPS_PORT}" == "443" ]]; then
    PANEL_ORIGIN="https://${DOMAIN}"
else
    PANEL_ORIGIN="https://${DOMAIN}:${HTTPS_PORT}"
fi
printf '\n'
printf 'آدرس Wizard: %s/installer/\n' "${PANEL_ORIGIN}"
printf 'Database name: %s\n' "${DB_NAME}"
printf 'Database username: %s\n' "${DB_USER}"
printf 'Database password: %s\n' "${DB_PASSWORD}"
printf '\nدر Wizard همین اطلاعات دیتابیس را وارد کنید و یک رمز مدیر تعیین کنید.\n'
printf 'پس از پایان Wizard، پوشه installer به‌صورت خودکار حذف می‌شود.\n'
printf 'اطلاعات دیتابیس در %s با دسترسی root ذخیره شده است.\n' "${DB_CREDENTIALS_FILE}"
