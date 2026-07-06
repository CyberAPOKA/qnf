#!/usr/bin/env bash
set -euo pipefail

UPLOAD_LIMIT="${UPLOAD_LIMIT:-15M}"
POST_LIMIT="${POST_LIMIT:-16M}"

PHP_VERSION="$(php -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;')"
FPM_INI="/etc/php/${PHP_VERSION}/fpm/php.ini"

echo "PHP version: ${PHP_VERSION}"
echo "CLI php.ini: $(php --ini | awk -F: '/Loaded Configuration File/{print $2}' | xargs)"

if [[ ! -f "${FPM_INI}" ]]; then
    echo "Arquivo do PHP-FPM não encontrado: ${FPM_INI}"
    echo "Tente localizar manualmente com:"
    echo "  sudo find /etc/php -name php.ini"
    exit 1
fi

echo "PHP-FPM php.ini: ${FPM_INI}"

sudo cp "${FPM_INI}" "${FPM_INI}.bak.$(date +%Y%m%d%H%M%S)"

sudo sed -i -E "s/^(;)?upload_max_filesize\s*=.*/upload_max_filesize = ${UPLOAD_LIMIT}/" "${FPM_INI}"
sudo sed -i -E "s/^(;)?post_max_size\s*=.*/post_max_size = ${POST_LIMIT}/" "${FPM_INI}"

if ! grep -q '^upload_max_filesize' "${FPM_INI}"; then
    echo "upload_max_filesize = ${UPLOAD_LIMIT}" | sudo tee -a "${FPM_INI}" >/dev/null
fi

if ! grep -q '^post_max_size' "${FPM_INI}"; then
    echo "post_max_size = ${POST_LIMIT}" | sudo tee -a "${FPM_INI}" >/dev/null
fi

sudo systemctl restart "php${PHP_VERSION}-fpm"

echo ""
echo "Valores ativos no PHP-FPM:"
php-fpm"${PHP_VERSION}" -i 2>/dev/null | grep -E 'upload_max_filesize|post_max_size' || true
php -i | grep -E 'upload_max_filesize|post_max_size'
