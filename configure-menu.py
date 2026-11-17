#!/usr/bin/env python3
"""Compatibility entrypoint for localized Telegram command configuration."""
import pathlib
import subprocess
root = pathlib.Path(__file__).resolve().parent.parent
subprocess.run(['php', 'scripts/configure-menu.php'], cwd=root, check=True)
