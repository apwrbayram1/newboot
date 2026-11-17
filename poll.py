#!/usr/bin/env python3
import json
import logging
import pathlib
import subprocess
import time
import fcntl
import threading
import requests

ROOT = pathlib.Path(__file__).resolve().parent.parent
SETTINGS = dict(line.split('=', 1) for line in (ROOT / '.env').read_text().splitlines() if '=' in line)
API = 'https://api.telegram.org/bot' + SETTINGS['BOT_TOKEN'] + '/'
logging.basicConfig(level=logging.INFO, format='%(asctime)s %(levelname)s %(message)s')
lock = (ROOT / 'runtime/poll.lock').open('w')
fcntl.flock(lock, fcntl.LOCK_EX | fcntl.LOCK_NB)
state = ROOT / 'runtime/offset'
offset = int(state.read_text()) if state.exists() else 0
session = requests.Session()
def session_timer():
    while True:
        try:
            tick = subprocess.run(['php', 'scripts/platform-tick.php'], cwd=ROOT, capture_output=True, text=True, timeout=90)
            if tick.returncode:
                with (ROOT / 'runtime/handler-errors.log').open('a') as errors:
                    errors.write(tick.stderr)
                logging.error('Session timer failed; see runtime/handler-errors.log')
        except Exception as error:
            logging.error('Session timer interrupted (%s)', type(error).__name__)
        time.sleep(5)

threading.Thread(target=session_timer, daemon=True).start()
logging.info('Polling started for @%s', SETTINGS['BOT_USERNAME'])
while True:
    try:
        result = session.post(API + 'getUpdates', json={'offset': offset, 'timeout': 5, 'allowed_updates': ['message', 'callback_query', 'pre_checkout_query', 'message_reaction']}, timeout=35).json()
        if not result.get('ok'):
            logging.error('Telegram rejected polling: %s', result.get('description'))
            time.sleep(5)
            continue
        # Answer time-sensitive checkout queries before slower updates in this batch.
        # Offsets are still committed strictly in original delivery order below.
        checked = set()
        for update in result['result']:
            if 'pre_checkout_query' in update:
                checkout = subprocess.run(['php', 'scripts/handle-update.php'], cwd=ROOT, input=json.dumps(update), text=True, capture_output=True, timeout=8)
                if checkout.returncode == 0:
                    checked.add(update['update_id'])
                else:
                    with (ROOT / 'runtime/handler-errors.log').open('a') as errors:
                        errors.write(checkout.stderr)
        for update in result['result']:
            if update['update_id'] in checked:
                offset = update['update_id'] + 1
                state.write_text(str(offset))
                continue
            processed = subprocess.run(['php', 'scripts/handle-update.php'], cwd=ROOT, input=json.dumps(update), text=True, capture_output=True, timeout=120)
            if processed.returncode:
                # PHP errors may contain request data: keep details in private runtime storage.
                with (ROOT / 'runtime/handler-errors.log').open('a') as errors:
                    errors.write(processed.stderr)
                logging.error('Update %s failed; see runtime/handler-errors.log; will retry', update['update_id'])
                time.sleep(2)
                break
            else:
                logging.info('Handled update %s', update['update_id'])
            offset = update['update_id'] + 1
            state.write_text(str(offset))
    except Exception as error:
        # Never print exception URLs, which could expose the bot token.
        logging.error('Polling interrupted (%s); retrying', type(error).__name__)
        time.sleep(5)
