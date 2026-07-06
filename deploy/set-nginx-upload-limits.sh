#!/usr/bin/env bash
set -euo pipefail

BODY_LIMIT="${BODY_LIMIT:-16M}"
SITE_FILE="${SITE_FILE:-/etc/nginx/sites-available/default}"

if [[ ! -f "${SITE_FILE}" ]]; then
    echo "Arquivo do site nginx não encontrado: ${SITE_FILE}"
    echo "Liste os sites com:"
    echo "  ls /etc/nginx/sites-available/"
    exit 1
fi

echo "Site nginx: ${SITE_FILE}"

if grep -q 'client_max_body_size' "${SITE_FILE}"; then
    sudo sed -i -E "s/client_max_body_size\s+[^;]+;/client_max_body_size ${BODY_LIMIT};/" "${SITE_FILE}"
else
    sudo sed -i "/server {/a \\    client_max_body_size ${BODY_LIMIT};" "${SITE_FILE}"
fi

sudo nginx -t
sudo systemctl reload nginx

echo "client_max_body_size configurado para ${BODY_LIMIT}"
