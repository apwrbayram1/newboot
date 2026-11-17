#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")/.."
if [[ "$EUID" -ne 0 ]]; then
    echo 'Run this installer as root.' >&2
    exit 1
fi
umask 077
mkdir -p runtime/account-bridge
chmod 700 runtime runtime/account-bridge
if [[ ! -x runtime/account-bridge-venv/bin/python ]]; then
    python3 -m venv runtime/account-bridge-venv
fi
runtime/account-bridge-venv/bin/python -m pip install --disable-pip-version-check -r account_bridge/requirements.txt
sed "s|/opt/nashenas|$PWD|g" scripts/systemd/nashenasadult-account-bridge.service > /etc/systemd/system/nashenasadult-account-bridge.service
chmod 644 /etc/systemd/system/nashenasadult-account-bridge.service
systemctl daemon-reload
systemctl enable --now nashenasadult-account-bridge.service
