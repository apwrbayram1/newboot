#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")/.."
mkdir -p runtime
chmod 700 runtime
if [[ "$(ps -p 1 -o comm=)" == systemd ]]; then
    if ! systemctl cat nashenasadult.service >/dev/null 2>&1; then
        exec bash scripts/install-service.sh
    fi
    systemctl start nashenasadult.service
    systemctl is-active nashenasadult.service
    exit 0
fi
if pgrep -f '^python3 scripts/poll.py$' >/dev/null; then
    echo 'Bot is already running.'
    exit 0
fi
nohup python3 scripts/poll.py >>runtime/poll.log 2>&1 </dev/null &
echo "$!" >runtime/poll.pid
echo 'Bot polling started (no systemd available).'
