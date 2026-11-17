#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")/.."
if [[ "$EUID" -ne 0 ]]; then
    echo 'Run this installer as root.' >&2
    exit 1
fi
mkdir -p runtime
chmod 700 runtime
sed "s|/opt/nashenas|$PWD|g" scripts/systemd/nashenasadult.service > /etc/systemd/system/nashenasadult.service
chmod 644 /etc/systemd/system/nashenasadult.service
systemctl daemon-reload
systemctl enable --now nashenasadult.service
systemctl is-active nashenasadult.service
