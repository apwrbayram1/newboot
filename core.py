import contextlib
import fcntl
import json
import os
import re
import sqlite3
import time
import uuid
from datetime import datetime, timedelta
from pathlib import Path
from zoneinfo import ZoneInfo

ROOT = Path(__file__).resolve().parent.parent
PRIVATE = ROOT / 'runtime/account-bridge'
DEFAULTS = {'api_id': 0, 'api_hash': '', 'target_bot': '', 'enabled': False,
            'start_command': '/start', 'timezone': 'Asia/Tehran', 'test_seconds': 600}

class BridgeError(Exception):
    def __init__(self, code, retry_after=0):
        self.code, self.retry_after = code, retry_after
        super().__init__(code)

def prepare():
    os.umask(0o077)
    PRIVATE.mkdir(parents=True, exist_ok=True, mode=0o700)
    PRIVATE.chmod(0o700)

def read_json(name, default=None):
    try:
        return json.loads((PRIVATE / name).read_text())
    except FileNotFoundError:
        return default

def write_json(name, value):
    prepare()
    temp = PRIVATE / (name + '.' + uuid.uuid4().hex + '.tmp')
    try:
        with temp.open('x', encoding='utf-8') as file:
            os.chmod(temp, 0o600)
            json.dump(value, file, ensure_ascii=False)
            file.flush()
            os.fsync(file.fileno())
        temp.replace(PRIVATE / name)
    finally:
        temp.unlink(missing_ok=True)

def config():
    return DEFAULTS | (read_json('config.json', {}) or {})

def credentials_ready(settings):
    return (type(settings.get('api_id')) is int and settings['api_id'] > 0
            and bool(re.fullmatch(r'[0-9a-fA-F]{32}', settings.get('api_hash', ''))))

@contextlib.contextmanager
def session_lock():
    prepare()
    with (PRIVATE / 'session.lock').open('a') as lock:
        try:
            fcntl.flock(lock, fcntl.LOCK_EX | fcntl.LOCK_NB)
        except BlockingIOError:
            raise BridgeError('account_in_use_stop_bridge_service') from None
        try:
            yield
        finally:
            fcntl.flock(lock, fcntl.LOCK_UN)

def client(settings):
    if not credentials_ready(settings):
        raise BridgeError('api_credentials_required')
    from telethon import TelegramClient
    connection = TelegramClient(str(PRIVATE / 'account'), settings['api_id'], settings['api_hash'],
                                timeout=10, request_retries=1, connection_retries=2,
                                flood_sleep_threshold=0, auto_reconnect=True,
                                sequential_updates=True, device_model='Nashenas account bridge',
                                app_version='1.0', receive_updates=False)
    connection.session.save_entities = False
    return connection

def auth_state(settings=None):
    settings = settings or config()
    if not credentials_ready(settings):
        return 'not_configured'
    if (PRIVATE / 'authorized.json').exists():
        return 'authorized'
    pending = read_json('pending.json', {}) or {}
    if pending.get('expires', 0) > time.time():
        return pending.get('state', 'login_required')
    return 'login_required'

class TestSlots:
    """Single global slot, immutable ten-minute deadline, calendar-day quota."""
    def __init__(self):
        prepare()
        self.db = sqlite3.connect(PRIVATE / 'attempts.sqlite3', isolation_level=None, timeout=5)
        self.db.row_factory = sqlite3.Row
        self.db.execute('PRAGMA journal_mode=WAL')
        self.db.execute('CREATE TABLE IF NOT EXISTS attempts (token TEXT PRIMARY KEY, user_id INTEGER NOT NULL, day TEXT NOT NULL, started INTEGER NOT NULL, deadline INTEGER NOT NULL, status TEXT NOT NULL, UNIQUE(user_id,day))')
        self.db.execute("CREATE UNIQUE INDEX IF NOT EXISTS one_active_attempt ON attempts(status) WHERE status='active'")

    def expire(self):
        self.db.execute("UPDATE attempts SET status='expired' WHERE status='active' AND deadline<=?", (int(time.time()),))

    def status(self):
        self.expire()
        row = self.db.execute("SELECT deadline FROM attempts WHERE status='active'").fetchone()
        return {'busy': bool(row), 'retry_after': max(0, row['deadline'] - int(time.time())) if row else 0}

    def acquire(self, user_id, settings):
        if type(user_id) is not int or user_id <= 0:
            raise BridgeError('invalid_user')
        now = int(time.time())
        local = datetime.fromtimestamp(now, ZoneInfo(settings['timezone']))
        tomorrow = (local + timedelta(days=1)).replace(hour=0, minute=0, second=0, microsecond=0)
        self.db.execute('BEGIN IMMEDIATE')
        try:
            self.expire()
            if self.db.execute('SELECT 1 FROM attempts WHERE user_id=? AND day=?', (user_id, local.date().isoformat())).fetchone():
                raise BridgeError('daily_limit', int(tomorrow.timestamp()) - now)
            busy = self.db.execute("SELECT deadline FROM attempts WHERE status='active'").fetchone()
            if busy:
                raise BridgeError('busy', max(0, busy['deadline'] - now))
            token = uuid.uuid4().hex
            # A failed or abandoned attempt cannot silently create another attempt today.
            self.db.execute("INSERT INTO attempts VALUES(?,?,?,?,?, 'active')", (token, user_id, local.date().isoformat(), now, now + 600))
            self.db.commit()
            return {'token': token, 'deadline': now + 600, 'started': now}
        except Exception:
            self.db.rollback()
            raise

    def require(self, user_id, token):
        self.expire()
        if type(user_id) is not int or not isinstance(token, str):
            raise BridgeError('invalid_lease')
        row = self.db.execute("SELECT * FROM attempts WHERE token=? AND user_id=? AND status='active'", (token, user_id)).fetchone()
        if not row:
            raise BridgeError('expired_or_invalid_lease')
        return dict(row)

    def finish(self, user_id, token, reason='finished'):
        self.require(user_id, token)
        if reason not in ('finished', 'cancelled', 'failed'):
            raise BridgeError('invalid_finish_reason')
        self.db.execute('UPDATE attempts SET status=? WHERE token=?', (reason, token))
