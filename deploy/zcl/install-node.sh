#!/usr/bin/env bash
# Run as root on the x86_64 Ubuntu pool host. Never exports wallet keys.
set -euo pipefail
[[ $(uname -m) == x86_64 ]]
getent passwd zclnode >/dev/null || useradd --system --home-dir /var/lib/zclassic --shell /usr/sbin/nologin zclnode
install -d -o zclnode -g zclnode -m 0700 /var/lib/zclassic /var/lib/zclassic-backups
install -d -m 0755 /opt/zclassic /etc/zcl-pool
zcl_download=$(mktemp -d)
trap 'rm -rf "$zcl_download"' EXIT
zcl_archive=zclassicd-v2.1.2-beta6-linux-x86_64.tar.gz
curl -fL --retry 3 "https://github.com/ZclassicCommunity/zclassic/releases/download/v2.1.2-beta6/$zcl_archive" -o "$zcl_download/$zcl_archive"
printf '%s  %s\n' '1e67a34a79ff8dea6f5c739484f540ceb6d598ace214c1ab68c0d923c56b3dce' "$zcl_download/$zcl_archive" | sha256sum -c -
tar -xzf "$zcl_download/$zcl_archive" -C /opt/zclassic --strip-components=1
chmod 0755 /opt/zclassic/zclassicd /opt/zclassic/zclassic-cli
# Cookie authentication keeps RPC credentials out of the repository and logs.
cat > /etc/systemd/system/zclassic.service <<'UNIT'
[Unit]
Description=Zclassic full node for ZCL Thesis pool
After=network-online.target
Wants=network-online.target

[Service]
Type=simple
User=zclnode
Group=zclnode
UMask=0077
ExecStart=/opt/zclassic/zclassicd -datadir=/var/lib/zclassic -server=1 -gen=0 -rpcbind=127.0.0.1 -rpcallowip=127.0.0.1 -exportdir=/var/lib/zclassic-backups -printtoconsole=1
Restart=on-failure
RestartSec=15
TimeoutStopSec=180
NoNewPrivileges=true
PrivateTmp=true
ProtectSystem=strict
ProtectHome=true
ReadWritePaths=/var/lib/zclassic /var/lib/zclassic-backups

[Install]
WantedBy=multi-user.target
UNIT
systemctl daemon-reload
systemctl enable --now zclassic
