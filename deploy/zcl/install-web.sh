#!/usr/bin/env bash
set -euo pipefail
export DEBIAN_FRONTEND=noninteractive
apt-get -o DPkg::Lock::Timeout=600 update
apt-get -o DPkg::Lock::Timeout=600 install -y caddy composer php-fpm php-gd php-intl php-zip nodejs npm
getent passwd zclpool >/dev/null || useradd --system --home-dir /var/lib/zclpool --create-home --shell /usr/sbin/nologin zclpool
install -d -m 0755 /var/lib/zcl-public /var/lib/zcl-public/api /var/lib/zcl-public/api/richlist
install -d -o zclpool -g zclpool -m 0750 /var/log/yiimp
install -d -m 0755 /opt/zcl-pool/thesis
if [[ ! -f /opt/zcl-pool/thesis/server.mjs ]]; then
  git clone https://github.com/netzo92/zclthesis.git /opt/zcl-pool/thesis
fi
npm --prefix /opt/zcl-pool/thesis/scripts ci --ignore-scripts --no-audit --no-fund
COMPOSER_ALLOW_SUPERUSER=1 composer --working-dir=/opt/zcl-pool/source/yiimp2 install --no-dev --prefer-dist --no-interaction --no-scripts
install -d -o www-data -g www-data -m 0770 /opt/zcl-pool/source/yiimp2/runtime /opt/zcl-pool/source/yiimp2/web/assets
usermod -a -G zclpool www-data
usermod -a -G www-data caddy
install -m 0644 /opt/zcl-pool/source/deploy/zcl/Caddyfile /etc/caddy/Caddyfile
caddy validate --config /etc/caddy/Caddyfile --adapter caddyfile
install -m 0644 /opt/zcl-pool/source/deploy/zcl/zcl-node-stats.service /etc/systemd/system/
install -m 0644 /opt/zcl-pool/source/deploy/zcl/zcl-node-stats.timer /etc/systemd/system/
install -m 0644 /opt/zcl-pool/source/deploy/zcl/zcl-richlist.service /etc/systemd/system/
install -m 0644 /opt/zcl-pool/source/deploy/zcl/zcl-richlist.timer /etc/systemd/system/
systemctl daemon-reload
systemctl enable --now zcl-node-stats.timer zcl-richlist.timer
systemctl restart caddy
