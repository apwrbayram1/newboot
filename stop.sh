#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")/.."
if [[ -f runtime/poll.pid ]]; then
    kill "$(cat runtime/poll.pid)" 2>/dev/null || true
    rm -f runtime/poll.pid
fi
