#!/usr/bin/env bash
# Only the newly attached, explicitly named pool data disk is eligible for formatting.
set -euo pipefail
zcl_device=/dev/disk/by-id/google-zcl-pool-data
[[ -b "$zcl_device" ]]
apt-get -o DPkg::Lock::Timeout=600 install -y btrfs-progs
zcl_type=$(blkid -s TYPE -o value "$zcl_device" || true)
if [[ -z "$zcl_type" ]]; then
  [[ -z $(wipefs --noheadings --output TYPE "$zcl_device") ]] || { echo 'Disk has an existing signature; refusing to format.' >&2; exit 1; }
  mkfs.btrfs -L zcl-pool-data "$zcl_device"
elif [[ "$zcl_type" != btrfs ]]; then
  echo 'Pool disk already contains a different filesystem; refusing to format.' >&2
  exit 1
fi
install -d -m 0700 /var/lib/zcl-data /var/lib/zclassic
zcl_uuid=$(blkid -s UUID -o value "$zcl_device")
if ! grep -Fq "UUID=$zcl_uuid /var/lib/zcl-data " /etc/fstab; then
  printf 'UUID=%s /var/lib/zcl-data btrfs defaults,noatime,compress=zstd 0 0\n' "$zcl_uuid" >> /etc/fstab
fi
mountpoint -q /var/lib/zcl-data || mount /var/lib/zcl-data
btrfs subvolume show /var/lib/zcl-data/node >/dev/null 2>&1 || btrfs subvolume create /var/lib/zcl-data/node
install -d -m 0700 /var/lib/zcl-data/private-snapshots
if ! mountpoint -q /var/lib/zclassic; then
  [[ -z $(ls -A /var/lib/zclassic) ]] || { echo 'Existing node data found; refusing to hide it.' >&2; exit 1; }
  if ! grep -Fq '/var/lib/zcl-data/node /var/lib/zclassic ' /etc/fstab; then
    printf '/var/lib/zcl-data/node /var/lib/zclassic none bind 0 0\n' >> /etc/fstab
  fi
  mount /var/lib/zclassic
fi
systemctl daemon-reload
