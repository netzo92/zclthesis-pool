#!/usr/bin/env bash
# CPU-only pool host: no hash-generation or GPU software is installed.
set -euo pipefail
export DEBIAN_FRONTEND=noninteractive
apt-get update
apt-get install -y ca-certificates curl git build-essential automake autoconf libtool pkg-config \
  libmariadb-dev-compat libssl-dev libsodium-dev libgmp-dev libcurl4-openssl-dev \
  docker.io mariadb-server php-cli php-mysql php-bcmath php-curl php-xml php-mbstring \
  php-sqlite3 unzip jq python3
systemctl enable --now docker mariadb
install -d -m 0755 /opt/zcl-pool
install -d -m 0700 /etc/zcl-pool
printf 'CPU-only pool build host ready\n' > /opt/zcl-pool/bootstrap-status
