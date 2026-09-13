#!/usr/bin/env python3
"""Retain provider-returned ZCL trades and exact observed volume, never ticker sums."""
import asyncio
from contextlib import closing, contextmanager
import datetime as dt
from decimal import Decimal, localcontext
import fcntl
import hashlib
import json
import os
from pathlib import Path
import re
import sqlite3
import sys
import tempfile

SOURCE = 'wss://api.nonkyc.io'
PAIR = 'ZCL/USDT'
LAUNCHED_AT = '2026-09-12T03:23:13.198605Z'
# Public executions have millisecond precision: the first eligible millisecond is .199.
LAUNCH_MS = 1789183393199
SEED_SHA256 = 'bf3a108072893689c1d62224000fc9d4c4369503a07356e582170734aab35304'
DATABASE = Path('/var/lib/zcl-prices/volume.sqlite3')
PUBLIC = Path('/var/lib/zcl-public/api/prices/zcl-usdt-volume.json')
SEED = Path('/opt/zcl-prices/normalized-trades.json')
HOUR = 3600000
MAX_BUCKETS = 168
PAGE_SIZE = 1000
MAX_PAGES = 8
MAX_MESSAGE_BYTES = 512 * 1024
MAX_PUBLIC_BYTES = 128 * 1024
OVERLAP_MS = 5 * 60000
INITIAL_WINDOW_MS = 12 * HOUR
STALE_MS = 180000
FETCH_TIMEOUT = 30


class InvalidResponse(ValueError):
    pass


class PageBudget(Exception):
    pass


def milliseconds(value):
    if value.tzinfo is None:
        raise ValueError('Aware UTC time required')
    delta = value - dt.datetime(1970, 1, 1, tzinfo=dt.timezone.utc)
    return delta.days * 86400000 + delta.seconds * 1000 + delta.microseconds // 1000


def parse_time(value):
    if type(value) is not str or not re.fullmatch(r'\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d(?:\.\d{1,6})?Z', value):
        raise InvalidResponse('Invalid UTC timestamp')
    return milliseconds(dt.datetime.fromisoformat(value.replace('Z', '+00:00')))


def timestamp(value):
    return (dt.datetime(1970, 1, 1, tzinfo=dt.timezone.utc) + dt.timedelta(milliseconds=value)).isoformat(timespec='milliseconds').replace('+00:00', 'Z')


def request_time(value):
    return timestamp(value // 1000 * 1000).replace('.000Z', 'Z')


def decimal_text(value):
    if type(value) is not str or len(value) > 38 or not re.fullmatch(r'[0-9]{1,38}(?:\.[0-9]{1,38})?', value):
        raise InvalidResponse('Invalid positive decimal text')
    if Decimal(value) <= 0:
        raise InvalidResponse('Nonpositive execution value')
    return value


def exact_sum(left, right):
    with localcontext() as context:
        context.prec = 256
        return format(Decimal(left) + Decimal(right), 'f')


def quote_value(price, quantity):
    with localcontext() as context:
        context.prec = 256
        return format(Decimal(price) * Decimal(quantity), 'f')


def unique_object(pairs):
    result = {}
    for key, value in pairs:
        if key in result:
            raise InvalidResponse('Duplicate JSON key')
        result[key] = value
    return result


def decode(raw):
    if not isinstance(raw, (str, bytes)) or len(raw) > MAX_MESSAGE_BYTES:
        raise InvalidResponse('Oversized response')
    try:
        return json.loads(raw, object_pairs_hook=unique_object,
                          parse_constant=lambda _: (_ for _ in ()).throw(InvalidResponse('Invalid JSON constant')))
    except (UnicodeError, ValueError, RecursionError) as error:
        raise InvalidResponse('Invalid response JSON') from error


def parse_trade(raw, start_ms, end_ms):
    if type(raw) is not dict or not re.fullmatch(r'[A-Za-z0-9_-]{1,96}', str(raw.get('id', ''))):
        raise InvalidResponse('Invalid trade ID')
    if type(raw.get('id')) is not str:
        raise InvalidResponse('Invalid trade ID type')
    traded_ms = raw.get('timestampms')
    if type(traded_ms) is not int or not start_ms <= traded_ms <= end_ms:
        raise InvalidResponse('Trade outside requested interval')
    if parse_time(raw.get('timestamp')) != traded_ms:
        raise InvalidResponse('Inconsistent trade timestamp')
    if raw.get('triggeredBy') not in ('buy', 'sell'):
        raise InvalidResponse('Invalid trade side')
    price, quantity = decimal_text(raw.get('price')), decimal_text(raw.get('quantity'))
    return {'id': raw['id'], 'traded_ms': traded_ms, 'price': price, 'quantity': quantity,
            'quote': quote_value(price, quantity), 'side': raw['triggeredBy']}


async def fetch_pages(request, start_ms, end_ms, max_pages=MAX_PAGES, page_size=PAGE_SIZE):
    """A fixed cutoff makes offsets stable; exhaustion is required before coverage advances."""
    rows, seen, page_hashes = [], set(), []
    previous_time = None
    query_start = start_ms // 1000 * 1000
    for page in range(max_pages):
        params = {'symbol': PAIR, 'limit': page_size, 'offset': page * page_size,
                  'sort': 'ASC', 'from': request_time(query_start), 'till': request_time(end_ms)}
        payload = await request({'id': page + 1, 'method': 'getTrades', 'params': params})
        if (type(payload) is not dict or type(payload.get('id')) is not int or payload.get('id') != page + 1 or payload.get('error') is not None
                or type(payload.get('result')) is not dict or payload['result'].get('symbol') != PAIR):
            raise InvalidResponse('Unexpected getTrades response')
        raw_rows = payload['result'].get('data')
        if type(raw_rows) is not list or len(raw_rows) > page_size:
            raise InvalidResponse('Invalid trade page')
        page_hashes.append(hashlib.sha256(json.dumps(payload, sort_keys=True, separators=(',', ':')).encode()).hexdigest())
        for raw in raw_rows:
            row = parse_trade(raw, query_start, end_ms)
            # Repeated IDs across pages suggest ignored/unstable offsets. Fail closed.
            if row['id'] in seen or (previous_time is not None and row['traded_ms'] < previous_time):
                raise InvalidResponse('Unstable trade pagination')
            seen.add(row['id'])
            previous_time = row['traded_ms']
            if row['traded_ms'] >= LAUNCH_MS:
                rows.append(row)
        if len(raw_rows) < page_size:
            return rows, page_hashes
    raise PageBudget('Trade interval exceeded bounded pagination')


async def fetch_window_async(start_ms, end_ms):
    # Debian's python3-websockets (10.x+) provides TLS validation and message limits.
    from websockets.legacy.client import connect as websocket_connect
    async with websocket_connect(SOURCE, open_timeout=5, close_timeout=1, ping_interval=None,
                                 max_size=MAX_MESSAGE_BYTES, max_queue=1, compression=None) as socket:
        async def request(message):
            await socket.send(json.dumps(message, separators=(',', ':')))
            return decode(await asyncio.wait_for(socket.recv(), timeout=7))
        return await fetch_pages(request, start_ms, end_ms)


def fetch_window(start_ms, end_ms):
    async def bounded():
        return await asyncio.wait_for(fetch_window_async(start_ms, end_ms), timeout=FETCH_TIMEOUT)
    return asyncio.run(bounded())


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
        connection.execute('PRAGMA journal_mode=WAL')
        connection.execute('PRAGMA synchronous=FULL')
        yield connection


def initialize(connection):
    connection.executescript('''
        CREATE TABLE IF NOT EXISTS metadata (key TEXT PRIMARY KEY, value TEXT NOT NULL);
        CREATE TABLE IF NOT EXISTS trades (
          trade_id TEXT PRIMARY KEY, traded_ms INTEGER NOT NULL,
          price TEXT NOT NULL, quantity TEXT NOT NULL, quote TEXT NOT NULL, side TEXT NOT NULL);
        CREATE INDEX IF NOT EXISTS trades_time ON trades(traded_ms);
        CREATE TABLE IF NOT EXISTS hours (
          start_ms INTEGER PRIMARY KEY, quantity TEXT NOT NULL, quote TEXT NOT NULL, count INTEGER NOT NULL);
        CREATE TABLE IF NOT EXISTS totals (
          singleton INTEGER PRIMARY KEY CHECK(singleton=1), quantity TEXT NOT NULL, quote TEXT NOT NULL,
          count INTEGER NOT NULL, first_ms INTEGER, last_ms INTEGER);
        INSERT OR IGNORE INTO totals VALUES(1, '0', '0', 0, NULL, NULL);
        CREATE TABLE IF NOT EXISTS coverage (start_ms INTEGER PRIMARY KEY, end_ms INTEGER NOT NULL);
        CREATE TABLE IF NOT EXISTS attempts (
          attempted_ms INTEGER PRIMARY KEY, start_ms INTEGER NOT NULL, end_ms INTEGER NOT NULL,
          status TEXT NOT NULL, page_hashes TEXT NOT NULL);
    ''')
    expected = {'schema_version': '1', 'source': SOURCE, 'pair': PAIR, 'launched_at': LAUNCHED_AT}
    with connection:
        for key, value in expected.items():
            connection.execute('INSERT OR IGNORE INTO metadata VALUES (?, ?)', (key, value))
        current = dict(connection.execute('SELECT key, value FROM metadata'))
        if any(current.get(key) != value for key, value in expected.items()):
            raise ValueError('Incompatible volume database')


def add_rows(connection, rows):
    total = dict(connection.execute('SELECT * FROM totals WHERE singleton=1').fetchone())
    for row in rows:
        existing = connection.execute('SELECT * FROM trades WHERE trade_id=?', (row['id'],)).fetchone()
        if existing is not None:
            if any(existing[column] != row[key] for column, key in
                   [('traded_ms', 'traded_ms'), ('price', 'price'), ('quantity', 'quantity'), ('quote', 'quote'), ('side', 'side')]):
                raise InvalidResponse('Provider changed an existing execution')
            continue
        connection.execute('INSERT INTO trades VALUES (?, ?, ?, ?, ?, ?)',
                           (row['id'], row['traded_ms'], row['price'], row['quantity'], row['quote'], row['side']))
        hour = row['traded_ms'] // HOUR * HOUR
        old = connection.execute('SELECT * FROM hours WHERE start_ms=?', (hour,)).fetchone()
        connection.execute('INSERT OR REPLACE INTO hours VALUES (?, ?, ?, ?)',
                           (hour, exact_sum(old['quantity'] if old else '0', row['quantity']),
                            exact_sum(old['quote'] if old else '0', row['quote']), (old['count'] if old else 0) + 1))
        total['quantity'] = exact_sum(total['quantity'], row['quantity'])
        total['quote'] = exact_sum(total['quote'], row['quote'])
        total['count'] += 1
        total['first_ms'] = min(total['first_ms'], row['traded_ms']) if total['first_ms'] is not None else row['traded_ms']
        total['last_ms'] = max(total['last_ms'], row['traded_ms']) if total['last_ms'] is not None else row['traded_ms']
    connection.execute('UPDATE totals SET quantity=?, quote=?, count=?, first_ms=?, last_ms=? WHERE singleton=1',
                       (total['quantity'], total['quote'], total['count'], total['first_ms'], total['last_ms']))


def add_coverage(connection, start_ms, end_ms):
    # Closed query endpoints overlap; merge touching or overlapping verified intervals.
    start_ms = max(start_ms, LAUNCH_MS)
    overlaps = connection.execute('SELECT * FROM coverage WHERE start_ms<=? AND end_ms>=?', (end_ms, start_ms)).fetchall()
    for row in overlaps:
        start_ms, end_ms = min(start_ms, row['start_ms']), max(end_ms, row['end_ms'])
        connection.execute('DELETE FROM coverage WHERE start_ms=?', (row['start_ms'],))
    connection.execute('INSERT INTO coverage VALUES (?, ?)', (start_ms, end_ms))


def store_window(connection, rows, start_ms, end_ms, attempted_ms, page_hashes):
    connection.execute('BEGIN IMMEDIATE')
    try:
        add_rows(connection, rows)
        add_coverage(connection, start_ms, end_ms)
        connection.execute('INSERT OR REPLACE INTO attempts VALUES (?, ?, ?, ?, ?)',
                           (attempted_ms, start_ms, end_ms, 'ok', json.dumps(page_hashes)))
        connection.commit()
    except BaseException:
        connection.rollback()
        raise


def import_seed(connection, seed):
    if connection.execute("SELECT 1 FROM metadata WHERE key='seed_sha256'").fetchone():
        return
    raw = Path(seed).read_bytes()
    if hashlib.sha256(raw).hexdigest() != SEED_SHA256:
        raise InvalidResponse('Historical seed hash mismatch')
    payload = json.loads(raw)
    if (payload['siteLaunchedAt'] != LAUNCHED_AT or payload['pair'] != PAIR
            or payload['coverage']['exhaustedReturnedPages'] is not True or len(payload['trades']) != 800):
        raise InvalidResponse('Unexpected historical seed')
    end_ms = parse_time(payload['requestedTill'])
    rows = [parse_trade({'id': row['tradeId'], 'price': row['priceQuote'], 'quantity': row['quantityBase'],
                         'timestamp': row['tradedAt'], 'timestampms': row['tradedAtMs'],
                         'triggeredBy': row['triggeredBy']}, LAUNCH_MS, end_ms) for row in payload['trades']]
    store_window(connection, rows, LAUNCH_MS, end_ms, parse_time(payload['retrievedAt']), [SEED_SHA256])
    connection.execute("INSERT INTO metadata VALUES ('seed_sha256', ?)", (SEED_SHA256,))


def contiguous_end(connection):
    row = connection.execute('SELECT end_ms FROM coverage WHERE start_ms=?', (LAUNCH_MS,)).fetchone()
    return row['end_ms'] if row else None


def snapshot(connection, generated_ms):
    total = connection.execute('SELECT * FROM totals WHERE singleton=1').fetchone()
    intervals = connection.execute('SELECT * FROM coverage ORDER BY start_ms').fetchall()
    checked = max((row['end_ms'] for row in intervals), default=None)
    contiguous = contiguous_end(connection)
    latest = connection.execute('SELECT status FROM attempts ORDER BY attempted_ms DESC LIMIT 1').fetchone()
    current_hour = generated_ms // HOUR * HOUR
    first_hour = max(LAUNCH_MS // HOUR * HOUR, current_hour - (MAX_BUCKETS - 1) * HOUR)
    hours = {row['start_ms']: row for row in connection.execute('SELECT * FROM hours WHERE start_ms BETWEEN ? AND ?',
                                                               (first_hour, current_hour))}
    buckets = []
    for start in range(first_hour, current_hour + 1, HOUR):
        row = hours.get(start)
        lower = max(start, LAUNCH_MS)
        upper = min(start + HOUR, checked) if checked is not None else lower
        covered = upper > lower and any(part['start_ms'] <= lower and part['end_ms'] >= upper for part in intervals)
        # A completed hour must be covered all the way to its end. The current hour is in progress.
        if start < current_hour and (checked is None or checked < start + HOUR):
            covered = False
        buckets.append({'start': timestamp(start), 'volumeBase': row['quantity'] if row else '0',
                        'volumeQuote': row['quote'] if row else '0', 'tradeCount': row['count'] if row else 0,
                        'covered': covered})
    status = 'partial'
    if contiguous is not None and contiguous == checked:
        status = 'live' if 0 <= generated_ms - checked <= STALE_MS and latest and latest['status'] == 'ok' else 'stale'
    return {'schemaVersion': 1, 'pair': PAIR, 'source': 'NonKYC', 'launchedAt': LAUNCHED_AT,
            'generatedAt': timestamp(generated_ms), 'checkedThrough': timestamp(checked) if checked is not None else None,
            'coverageStart': LAUNCHED_AT if contiguous is not None else None,
            'firstTradeAt': timestamp(total['first_ms']) if total['first_ms'] is not None else None,
            'lastTradeAt': timestamp(total['last_ms']) if total['last_ms'] is not None else None,
            'tradeCount': total['count'], 'volumeBase': total['quantity'], 'volumeQuote': total['quote'],
            'bucketSeconds': 3600, 'windowStart': timestamp(first_hour), 'buckets': buckets, 'status': status}


def fsync_directory(path):
    descriptor = os.open(path, os.O_RDONLY | os.O_DIRECTORY)
    try:
        os.fsync(descriptor)
    finally:
        os.close(descriptor)


def publish(path, value):
    content = (json.dumps(value, ensure_ascii=True, separators=(',', ':'), allow_nan=False) + '\n').encode()
    if len(content) > MAX_PUBLIC_BYTES:
        raise ValueError('Public volume history exceeds bound')
    path = Path(path)
    path.parent.mkdir(mode=0o755, parents=True, exist_ok=True)
    temporary = None
    try:
        with tempfile.NamedTemporaryFile(dir=path.parent, prefix='.volume-', delete=False) as handle:
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
    directory = Path(directory)
    directory.mkdir(mode=0o700, parents=True, exist_ok=True)
    target = directory / ('volume-' + timestamp(now_ms)[:10] + '.sqlite3')
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
        for old in sorted(directory.glob('volume-????-??-??.sqlite3'))[:-3]:
            old.unlink()
    finally:
        temporary.unlink(missing_ok=True)


def run(database=DATABASE, public=PUBLIC, seed=SEED, fetch=fetch_window, now=None):
    now = now or (lambda: dt.datetime.now(dt.timezone.utc))
    database = Path(database)
    database.parent.mkdir(mode=0o700, parents=True, exist_ok=True)
    with (database.parent / 'volume.lock').open('a') as lock:
        os.fchmod(lock.fileno(), 0o600)
        try:
            fcntl.flock(lock, fcntl.LOCK_EX | fcntl.LOCK_NB)
        except BlockingIOError:
            return 'busy'
        with connect(database) as connection:
            initialize(connection)
            import_seed(connection, seed)
            started = milliseconds(now())
            previous = contiguous_end(connection) or LAUNCH_MS
            window_row = connection.execute("SELECT value FROM metadata WHERE key='window_ms'").fetchone()
            window = int(window_row[0]) if window_row else INITIAL_WINDOW_MS
            # Whole-second overlap keeps even the smallest recovery interval advancing.
            overlap = min(OVERLAP_MS, window // 4 // 1000 * 1000)
            start_ms = max(LAUNCH_MS, previous - overlap)
            end_ms = min((started - 10000) // 1000 * 1000, (start_ms + window) // 1000 * 1000)
            if end_ms > start_ms:
                try:
                    rows, hashes = fetch(start_ms, end_ms)
                    store_window(connection, rows, start_ms, end_ms, started, hashes)
                    if window < INITIAL_WINDOW_MS and len(rows) < 2 * PAGE_SIZE:
                        connection.execute("INSERT OR REPLACE INTO metadata VALUES ('window_ms', ?)",
                                           (str(min(INITIAL_WINDOW_MS, window * 2)),))
                except Exception as error:
                    reason = 'page_budget' if isinstance(error, PageBudget) else 'fetch_or_validation_failed'
                    connection.execute('INSERT OR REPLACE INTO attempts VALUES (?, ?, ?, ?, ?)',
                                       (started, start_ms, end_ms, reason, '[]'))
                    if isinstance(error, PageBudget):
                        # A smaller fixed interval on the next tick can exhaust even a busy market.
                        connection.execute("INSERT OR REPLACE INTO metadata VALUES ('window_ms', ?)", (str(max(1000, window // 2)),))
            generated = milliseconds(now())
            value = snapshot(connection, generated)
            publish(public, value)
            backup_daily(connection, database.parent / 'backups', generated)
            return value['status']


def main():
    os.umask(0o077)
    try:
        status = run()
    except Exception:
        print('Volume recorder storage or publication failed; previous history retained.', file=sys.stderr)
        return 1
    if status in ('partial', 'stale'):
        print('Observed volume coverage is incomplete or stale; retained records are published.', file=sys.stderr)
        return 1
    return 0


if __name__ == '__main__':
    sys.exit(main())
