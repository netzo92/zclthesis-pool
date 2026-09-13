#!/usr/bin/env python3
"""Durably record NonKYC last-price observations, not trades or OHLC candles."""
from contextlib import contextmanager, closing
import datetime as dt
from decimal import Decimal, InvalidOperation
import fcntl
import http.client
import json
import os
from pathlib import Path
import re
import signal
import sqlite3
import sys
import tempfile
import threading
import time
import urllib.error
import urllib.request


SOURCE = 'https://api.nonkyc.io/api/v2/market/getbysymbol/ZCL_USDT'
STATE = Path('/var/lib/zcl-prices')
DATABASE = STATE / 'prices.sqlite3'
PUBLIC = Path('/var/lib/zcl-public/api/prices/zcl-usdt.json')
INTERVAL_SECONDS = 60
WINDOW_DAYS = 7
MAX_RECORDS = WINDOW_DAYS * 24 * 60
MAX_RESPONSE_BYTES = 256 * 1024
MAX_PUBLIC_BYTES = 4 * 1024 * 1024
FETCH_SECONDS = 10
STALE_SECONDS = 180
FAILURES = frozenset({'network', 'http', 'invalid_response'})
COLUMNS = ['observedAt', 'lastTradeAt', 'lastPrice', 'volume24hQuote',
           'change24hPercent', 'paused']


class InvalidResponse(ValueError):
    pass


class FetchFailure(Exception):
    def __init__(self, reason):
        if reason not in FAILURES:
            raise ValueError('Unknown failure reason')
        self.reason = reason
        super().__init__(reason)


def utc_now():
    return dt.datetime.now(dt.timezone.utc)


def milliseconds(value):
    if not isinstance(value, dt.datetime) or value.tzinfo is None:
        raise ValueError('An aware timestamp is required')
    epoch = value.astimezone(dt.timezone.utc) - dt.datetime(1970, 1, 1, tzinfo=dt.timezone.utc)
    return epoch.days * 86400000 + epoch.seconds * 1000 + epoch.microseconds // 1000


def timestamp(epoch_ms):
    value = dt.datetime(1970, 1, 1, tzinfo=dt.timezone.utc) + dt.timedelta(milliseconds=epoch_ms)
    return value.isoformat(timespec='milliseconds').replace('+00:00', 'Z')


def exact_decimal(value, *, positive=False, signed=False):
    """Keep the provider's exact decimal text, including trailing zeroes."""
    pattern = r'[+-]?[0-9]+(?:\.[0-9]+)?' if signed else r'[0-9]+(?:\.[0-9]+)?'
    if type(value) is not str or len(value) > 96 or not re.fullmatch(pattern, value):
        raise InvalidResponse('Invalid decimal string')
    try:
        number = Decimal(value)
    except InvalidOperation as error:
        raise InvalidResponse('Invalid decimal string') from error
    if not number.is_finite() or (positive and number <= 0):
        raise InvalidResponse('Invalid decimal value')
    return value


def parse_market(payload, observed_ms):
    if type(payload) is not dict or payload.get('symbol') != 'ZCL/USDT':
        raise InvalidResponse('Unexpected market')
    price = exact_decimal(payload.get('lastPrice'), positive=True)
    trade_ms = payload.get('lastTradeAt')
    if (type(trade_ms) is not int or not 1480000000000 <= trade_ms <= observed_ms + 300000):
        raise InvalidResponse('Invalid last trade timestamp')
    volume = payload.get('volumeSecondary')
    change = payload.get('changePercent')
    if volume is not None:
        volume = exact_decimal(volume)
    if change is not None:
        change = exact_decimal(change, signed=True)
    for field in ('isPaused', 'isActive'):
        if field in payload and type(payload[field]) is not bool:
            raise InvalidResponse('Invalid market flag')
    paused = None
    if payload.get('isPaused') is True or payload.get('isActive') is False:
        paused = True
    elif payload.get('isPaused') is False and payload.get('isActive') is True:
        paused = False
    return {'lastTradeAt': timestamp(trade_ms), 'lastPrice': price,
            'volume24hQuote': volume, 'change24hPercent': change, 'paused': paused}


def unique_object(pairs):
    value = {}
    for key, item in pairs:
        if key in value:
            raise InvalidResponse('Duplicate JSON key')
        value[key] = item
    return value


class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, request, response, code, message, headers, new_url):
        if response is not None:
            response.close()
        raise FetchFailure('http')


@contextmanager
def fetch_deadline():
    """Bound DNS and slow responses in the single-threaded production process."""
    enabled = threading.current_thread() is threading.main_thread()
    if enabled:
        previous_handler = signal.getsignal(signal.SIGALRM)
        previous_timer = signal.getitimer(signal.ITIMER_REAL)

        def expired(_signum, _frame):
            raise FetchFailure('network')

        signal.signal(signal.SIGALRM, expired)
        signal.setitimer(signal.ITIMER_REAL, FETCH_SECONDS)
    try:
        yield
    finally:
        if enabled:
            signal.setitimer(signal.ITIMER_REAL, 0)
            signal.signal(signal.SIGALRM, previous_handler)
            signal.setitimer(signal.ITIMER_REAL, *previous_timer)


def fetch_market(opener=None):
    opener = opener or urllib.request.build_opener(urllib.request.ProxyHandler({}), NoRedirect())
    request = urllib.request.Request(SOURCE, headers={
        'Accept': 'application/json', 'Accept-Encoding': 'identity',
        'User-Agent': 'zclthesis-price-recorder/1'})
    try:
        with fetch_deadline(), opener.open(request, timeout=5) as response:
            if response.status != 200 or response.geturl() != SOURCE:
                raise FetchFailure('http')
            if response.headers.get_content_type() != 'application/json':
                raise FetchFailure('invalid_response')
            length = response.headers.get('Content-Length')
            if length is not None and (not length.isdigit() or int(length) > MAX_RESPONSE_BYTES):
                raise FetchFailure('invalid_response')
            deadline = time.monotonic() + FETCH_SECONDS
            chunks, size = [], 0
            while True:
                if time.monotonic() >= deadline:
                    raise FetchFailure('network')
                chunk = response.read1(min(4096, MAX_RESPONSE_BYTES + 1 - size))
                size += len(chunk)
                if size > MAX_RESPONSE_BYTES:
                    raise FetchFailure('invalid_response')
                if not chunk:
                    break
                chunks.append(chunk)
            try:
                return json.loads(b''.join(chunks).decode('utf-8'), parse_float=Decimal,
                                  parse_constant=lambda _value: (_ for _ in ()).throw(InvalidResponse('Invalid JSON number')),
                                  object_pairs_hook=unique_object)
            except (ValueError, UnicodeError, RecursionError) as error:
                raise FetchFailure('invalid_response') from error
    except FetchFailure:
        raise
    except urllib.error.HTTPError as error:
        error.close()
        raise FetchFailure('http') from error
    except (OSError, urllib.error.URLError, http.client.HTTPException) as error:
        raise FetchFailure('network') from error


@contextmanager
def connect(database):
    database = Path(database)
    database.parent.mkdir(mode=0o700, parents=True, exist_ok=True)
    if database.is_symlink():
        raise OSError('Database must be a regular file')
    descriptor = os.open(database, os.O_CREAT | os.O_RDWR, 0o600)
    os.close(descriptor)
    database.chmod(0o600)
    with closing(sqlite3.connect(database, timeout=5, isolation_level=None)) as connection:
        connection.row_factory = sqlite3.Row
        connection.execute('PRAGMA busy_timeout=5000')
        connection.execute('PRAGMA journal_mode=WAL')
        connection.execute('PRAGMA synchronous=FULL')
        yield connection


def initialize(connection, started_ms):
    connection.executescript('''
        CREATE TABLE IF NOT EXISTS metadata (key TEXT PRIMARY KEY, value TEXT NOT NULL);
        CREATE TABLE IF NOT EXISTS observations (
            bucket INTEGER PRIMARY KEY,
            observed_ms INTEGER NOT NULL CHECK (bucket = observed_ms / 60000),
            last_trade_at TEXT,
            last_price TEXT,
            volume_quote TEXT,
            change_percent TEXT,
            paused INTEGER CHECK (paused IS NULL OR paused IN (0, 1)),
            failure TEXT CHECK (failure IS NULL OR failure IN ('network', 'http', 'invalid_response')),
            CHECK ((failure IS NULL AND last_trade_at IS NOT NULL AND last_price IS NOT NULL)
                OR (failure IS NOT NULL AND last_trade_at IS NULL AND last_price IS NULL
                    AND volume_quote IS NULL AND change_percent IS NULL AND paused IS NULL))
        );
        CREATE INDEX IF NOT EXISTS successful_observations ON observations(bucket) WHERE failure IS NULL;
    ''')
    connection.execute('BEGIN IMMEDIATE')
    try:
        for key, value in {'schema_version': '1', 'source': SOURCE, 'pair': 'ZCL/USDT',
                           'recording_started': timestamp(started_ms)}.items():
            connection.execute('INSERT OR IGNORE INTO metadata(key, value) VALUES (?, ?)', (key, value))
        metadata = dict(connection.execute('SELECT key, value FROM metadata').fetchall())
        if (metadata.get('schema_version') != '1' or metadata.get('source') != SOURCE
                or metadata.get('pair') != 'ZCL/USDT'):
            raise ValueError('Incompatible price database')
        connection.commit()
    except BaseException:
        connection.rollback()
        raise


def record(connection, observed_ms, sample=None, failure=None):
    if (sample is None) == (failure is None) or (failure is not None and failure not in FAILURES):
        raise ValueError('A sample or failure is required')
    sample = sample or {}
    connection.execute('BEGIN IMMEDIATE')
    try:
        cursor = connection.execute('''INSERT INTO observations
            (bucket, observed_ms, last_trade_at, last_price, volume_quote, change_percent, paused, failure)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?) ON CONFLICT(bucket) DO NOTHING''',
            (observed_ms // 60000, observed_ms, sample.get('lastTradeAt'), sample.get('lastPrice'),
             sample.get('volume24hQuote'), sample.get('change24hPercent'), sample.get('paused'), failure))
        inserted = cursor.rowcount == 1
        connection.commit()
        return inserted
    except BaseException:
        connection.rollback()
        raise


def snapshot(connection, generated_ms):
    upper = generated_ms // 60000
    lower = upper - MAX_RECORDS + 1
    connection.execute('BEGIN')
    try:
        started = connection.execute("SELECT value FROM metadata WHERE key='recording_started'").fetchone()[0]
        latest = connection.execute('SELECT observed_ms, failure FROM observations ORDER BY bucket DESC LIMIT 1').fetchone()
        success = connection.execute('SELECT observed_ms FROM observations WHERE failure IS NULL ORDER BY bucket DESC LIMIT 1').fetchone()
        rows = connection.execute('SELECT * FROM observations WHERE bucket BETWEEN ? AND ? ORDER BY bucket ASC LIMIT ?',
                                  (lower, upper, MAX_RECORDS + 1)).fetchall()
        connection.commit()
    except BaseException:
        connection.rollback()
        raise
    samples, failures = [], []
    for row in rows[-MAX_RECORDS:]:
        observed = timestamp(row['observed_ms'])
        if row['failure'] is not None:
            failures.append({'observedAt': observed, 'reason': row['failure']})
        else:
            samples.append([observed, row['last_trade_at'], row['last_price'], row['volume_quote'],
                            row['change_percent'], None if row['paused'] is None else bool(row['paused'])])
    status = 'unavailable' if success is None else 'ok'
    if success is not None and (latest['failure'] is not None
                               or generated_ms - success['observed_ms'] > STALE_SECONDS * 1000
                               or success['observed_ms'] > generated_ms + 300000):
        status = 'stale'
    return {'schemaVersion': 1, 'kind': 'last-trade-observations',
            'asset': 'ZCL', 'pair': 'ZCL/USDT', 'quote': 'USDT', 'source': SOURCE,
            'generatedAt': timestamp(generated_ms), 'recordingStarted': started,
            'lastAttemptAt': None if latest is None else timestamp(latest['observed_ms']),
            'lastSuccessAt': None if success is None else timestamp(success['observed_ms']),
            'status': status, 'statusScope': 'recorder_freshness',
            'intervalSeconds': INTERVAL_SECONDS, 'windowDays': WINDOW_DAYS,
            'from': timestamp(lower * 60000), 'to': timestamp(generated_ms),
            'recordCount': min(len(rows), MAX_RECORDS), 'truncated': len(rows) > MAX_RECORDS,
            'columns': COLUMNS, 'samples': samples, 'failures': failures}


def fsync_directory(path):
    descriptor = os.open(path, os.O_RDONLY | os.O_DIRECTORY)
    try:
        os.fsync(descriptor)
    finally:
        os.close(descriptor)


def publish(path, value):
    content = (json.dumps(value, ensure_ascii=True, separators=(',', ':'), allow_nan=False) + '\n').encode('utf-8')
    if len(content) > MAX_PUBLIC_BYTES:
        raise ValueError('Public price history exceeds size bound')
    path = Path(path)
    path.parent.mkdir(mode=0o755, parents=True, exist_ok=True)
    temporary = None
    try:
        with tempfile.NamedTemporaryFile(dir=path.parent, prefix='.prices-', delete=False) as handle:
            temporary = Path(handle.name)
            os.fchmod(handle.fileno(), 0o644)
            handle.write(content)
            handle.flush()
            os.fsync(handle.fileno())
        os.replace(temporary, path)
        fsync_directory(path.parent)
    finally:
        if temporary is not None:
            temporary.unlink(missing_ok=True)


def backup_daily(connection, directory, now_ms):
    """Keep three online SQLite backups on this disk; these are not off-site backups."""
    directory = Path(directory)
    directory.mkdir(mode=0o700, parents=True, exist_ok=True)
    target = directory / ('prices-' + timestamp(now_ms)[:10] + '.sqlite3')
    if target.exists():
        return
    descriptor, name = tempfile.mkstemp(dir=directory, prefix='.backup-')
    os.close(descriptor)
    temporary = Path(name)
    try:
        with closing(sqlite3.connect(temporary)) as destination:
            connection.backup(destination, pages=256, sleep=0.05)
            destination.execute('PRAGMA journal_mode=DELETE')
            destination.commit()
        with temporary.open('rb') as handle:
            os.fsync(handle.fileno())
        os.replace(temporary, target)
        fsync_directory(directory)
        for old in sorted(directory.glob('prices-????-??-??.sqlite3'))[:-3]:
            old.unlink()
        fsync_directory(directory)
    finally:
        temporary.unlink(missing_ok=True)


def run(database=DATABASE, public=PUBLIC, fetch=fetch_market, now=utc_now):
    database, public = Path(database), Path(public)
    database.parent.mkdir(mode=0o700, parents=True, exist_ok=True)
    with (database.parent / 'record.lock').open('a') as lock:
        os.fchmod(lock.fileno(), 0o600)
        try:
            fcntl.flock(lock, fcntl.LOCK_EX | fcntl.LOCK_NB)
        except BlockingIOError:
            return 'busy'
        with connect(database) as connection:
            started_ms = milliseconds(now())
            initialize(connection, started_ms)
            existing = connection.execute('SELECT 1 FROM observations WHERE bucket=?',
                                          (started_ms // 60000,)).fetchone()
            if existing is None:
                failure, sample = None, None
                try:
                    payload = fetch()
                    observed_ms = milliseconds(now())
                    sample = parse_market(payload, observed_ms)
                except FetchFailure as error:
                    observed_ms = milliseconds(now())
                    failure = error.reason
                except (InvalidResponse, ValueError, TypeError):
                    observed_ms = milliseconds(now())
                    failure = 'invalid_response'
                record(connection, observed_ms, sample, failure)
            generated_ms = milliseconds(now())
            value = snapshot(connection, generated_ms)
            publish(public, value)
            backup_daily(connection, database.parent / 'backups', generated_ms)
            return value['status']


def main():
    os.umask(0o077)
    try:
        status = run()
    except Exception:
        # Never print raw upstream responses, headers, or local configuration.
        print('Price recorder storage or publication failed; existing history retained.', file=sys.stderr)
        return 1
    if status in ('stale', 'unavailable'):
        print('Price observation unavailable; failure recorded and history retained.', file=sys.stderr)
        return 1
    return 0


if __name__ == '__main__':
    sys.exit(main())
