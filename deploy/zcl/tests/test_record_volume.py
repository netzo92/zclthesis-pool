import asyncio
import datetime as dt
import importlib.util
import json
from pathlib import Path
import sqlite3
import tempfile
import unittest

SPEC = importlib.util.spec_from_file_location('record_volume', Path(__file__).resolve().parents[1] / 'record-volume.py')
m = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(m)
SEED = Path(__file__).resolve().parents[3] / 'data/price-history/nonkyc-20260913/normalized-trades.json'


def raw(identifier='a', time=None, price='0.1', quantity='0.2'):
    time = time if time is not None else m.LAUNCH_MS + 10000
    return {'id': identifier, 'price': price, 'quantity': quantity, 'timestampms': time,
            'timestamp': m.timestamp(time), 'triggeredBy': 'buy'}


def row(identifier='a', time=None, **kwargs):
    value = raw(identifier, time, **kwargs)
    return m.parse_trade(value, m.LAUNCH_MS, value['timestampms'])


def response(message, values):
    return {'id': message['id'], 'result': {'symbol': m.PAIR, 'data': values}}


class VolumeTests(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory()
        self.path = Path(self.tmp.name)
        self.context = m.connect(self.path / 'volume.sqlite3')
        self.db = self.context.__enter__()
        m.initialize(self.db)

    def tearDown(self):
        self.context.__exit__(None, None, None)
        self.tmp.cleanup()

    def test_seed_exact_totals_and_idempotence(self):
        m.import_seed(self.db, SEED)
        value = m.snapshot(self.db, m.parse_time('2026-09-13T00:29:00Z'))
        self.assertEqual(value['tradeCount'], 800)
        self.assertEqual(value['coverageStart'], m.LAUNCHED_AT)
        self.assertEqual(value['checkedThrough'], '2026-09-13T00:28:06.000Z')
        self.assertEqual(value['status'], 'live')
        self.assertTrue(all(bucket['covered'] for bucket in value['buckets']))
        # Independent sum of immutable input rather than the collector's persisted aggregates.
        from decimal import Decimal, localcontext
        source = json.loads(SEED.read_text())['trades']
        with localcontext() as c:
            c.prec = 256
            base = sum(Decimal(t['quantityBase']) for t in source)
            quote = sum(Decimal(t['quantityBase']) * Decimal(t['priceQuote']) for t in source)
        self.assertEqual(Decimal(value['volumeBase']), base)
        self.assertEqual(Decimal(value['volumeQuote']), quote)
        m.import_seed(self.db, SEED)
        self.assertEqual(m.snapshot(self.db, m.parse_time('2026-09-13T00:29:00Z')), value)

    def test_seed_tampering_rejected_without_coverage(self):
        fake = self.path / 'seed.json'
        fake.write_text(SEED.read_text() + ' ')
        with self.assertRaises(m.InvalidResponse):
            m.import_seed(self.db, fake)
        self.assertIsNone(m.contiguous_end(self.db))
        self.assertEqual(self.db.execute('SELECT count(*) FROM trades').fetchone()[0], 0)

    def test_overlap_dedup_and_exact_decimal_arithmetic(self):
        end = m.LAUNCH_MS + 60000
        trade = row(price='12345678901234567890.123456789', quantity='0.000000001')
        m.store_window(self.db, [trade], m.LAUNCH_MS, end, end, ['a'])
        m.store_window(self.db, [trade, row('b')], m.LAUNCH_MS, end + 60000, end + 60000, ['b'])
        value = m.snapshot(self.db, end + 60000)
        self.assertEqual(value['tradeCount'], 2)
        self.assertEqual(value['volumeBase'], '0.200000001')
        self.assertEqual(value['volumeQuote'], '12345678901.254567890123456789')

    def test_conflicting_id_rolls_back_whole_window(self):
        end = m.LAUNCH_MS + 60000
        m.store_window(self.db, [row()], m.LAUNCH_MS, end, end, [])
        with self.assertRaises(m.InvalidResponse):
            m.store_window(self.db, [row('b'), row(price='9')], m.LAUNCH_MS, end + 60000, end + 60000, [])
        self.assertEqual(self.db.execute('SELECT count(*) FROM trades').fetchone()[0], 1)
        self.assertEqual(m.contiguous_end(self.db), end)

    def test_hour_boundaries_gaps_and_empty_observed_hours(self):
        first = m.LAUNCH_MS // m.HOUR * m.HOUR
        m.store_window(self.db, [row('a', first + m.HOUR - 1), row('b', first + m.HOUR)],
                       m.LAUNCH_MS, first + 2 * m.HOUR, first + 2 * m.HOUR, [])
        m.store_window(self.db, [], first + 3 * m.HOUR, first + 4 * m.HOUR,
                       first + 4 * m.HOUR, [])
        value = m.snapshot(self.db, first + 4 * m.HOUR + 30000)
        self.assertEqual(value['status'], 'partial')
        self.assertEqual([b['tradeCount'] for b in value['buckets']], [1, 1, 0, 0, 0])
        self.assertEqual([b['covered'] for b in value['buckets']], [True, True, False, True, False])
        m.store_window(self.db, [], first + 2 * m.HOUR, first + 3 * m.HOUR, first + 4 * m.HOUR + 1, [])
        self.assertEqual(m.contiguous_end(self.db), first + 4 * m.HOUR)

    def test_failed_fetch_retains_totals_and_retries_same_gap(self):
        calls = []
        def failing(start, end):
            calls.append((start, end))
            raise TimeoutError()
        now = lambda: dt.datetime.fromisoformat('2026-09-13T01:00:00+00:00')
        public = self.path / 'public.json'
        self.assertEqual(m.run(self.path / 'run.sqlite3', public, SEED, failing, now), 'stale')
        first = json.loads(public.read_text())
        self.assertEqual(first['tradeCount'], 800)
        self.assertEqual(first['checkedThrough'], '2026-09-13T00:28:06.000Z')
        self.assertFalse(first['buckets'][-1]['covered'])
        self.assertEqual(m.run(self.path / 'run.sqlite3', public, SEED, failing, now), 'stale')
        self.assertEqual(calls[0], calls[1])
        self.assertEqual(json.loads(public.read_text())['volumeQuote'], first['volumeQuote'])

    def test_recovery_fills_outage_before_marking_live(self):
        calls = []
        def empty(start, end):
            calls.append((start, end))
            return [], ['hash']
        now = lambda: dt.datetime.fromisoformat('2026-09-13T01:00:00+00:00')
        public = self.path / 'recovery.json'
        self.assertEqual(m.run(self.path / 'recover.sqlite3', public, SEED, empty, now), 'live')
        value = json.loads(public.read_text())
        self.assertEqual(value['checkedThrough'], '2026-09-13T00:59:50.000Z')
        self.assertEqual(calls[0][0], m.parse_time('2026-09-13T00:23:06Z'))
        self.assertEqual(value['tradeCount'], 800)
        self.assertEqual(value['coverageStart'], m.LAUNCHED_AT)

    def test_page_budget_shrinks_next_window_preserving_coverage(self):
        def overfull(start, end):
            raise m.PageBudget()
        now = lambda: dt.datetime.fromisoformat('2026-09-14T01:00:00+00:00')
        db = self.path / 'busy.sqlite3'
        m.run(db, self.path / 'busy.json', SEED, overfull, now)
        with m.connect(db) as connection:
            self.assertEqual(int(connection.execute("SELECT value FROM metadata WHERE key='window_ms'").fetchone()[0]), m.INITIAL_WINDOW_MS // 2)
            self.assertEqual(m.contiguous_end(connection), m.parse_time('2026-09-13T00:28:06Z'))

    def test_smallest_recovery_window_advances_and_grows_to_catch_up(self):
        db = self.path / 'recover-small.sqlite3'
        with m.connect(db) as connection:
            m.initialize(connection)
            m.import_seed(connection, SEED)
            connection.execute("INSERT INTO metadata VALUES ('window_ms', '1000')")
        start = m.parse_time('2026-09-13T00:30:00Z')
        prior = m.parse_time('2026-09-13T00:28:06Z')
        for tick in range(20):
            current = start + tick * 60000
            now = lambda current=current: dt.datetime.fromisoformat(m.timestamp(current).replace('Z', '+00:00'))
            result = m.run(db, self.path / 'recovery-small.json', SEED, lambda a, b: ([], ['hash']), now)
            value = json.loads((self.path / 'recovery-small.json').read_text())
            checked = m.parse_time(value['checkedThrough'])
            self.assertGreater(checked, prior)
            prior = checked
        self.assertEqual(result, 'live')
        self.assertEqual(prior, current - 10000)
        with m.connect(db) as connection:
            self.assertEqual(int(connection.execute("SELECT value FROM metadata WHERE key='window_ms'").fetchone()[0]), m.INITIAL_WINDOW_MS)

    def test_pagination_complete_with_ties_and_precise_launch_exclusion(self):
        values = [raw('pre', m.LAUNCH_MS - 1), raw('a'), raw('b')]
        calls = []
        async def request(message):
            calls.append(message)
            offset = message['params']['offset']
            return response(message, values[offset:offset + 2])
        rows, hashes = asyncio.run(m.fetch_pages(request, m.LAUNCH_MS, (m.LAUNCH_MS + 60000) // 1000 * 1000, page_size=2))
        self.assertEqual([r['id'] for r in rows], ['a', 'b'])
        self.assertEqual([c['params']['offset'] for c in calls], [0, 2])
        self.assertEqual(len(hashes), 2)
        self.assertEqual(calls[0]['params']['from'], '2026-09-12T03:23:13Z')
        self.assertEqual(calls[0]['params']['till'], calls[1]['params']['till'])

    def test_exact_page_multiple_requires_empty_terminal_page(self):
        calls = []
        async def request(message):
            calls.append(message)
            return response(message, [raw('a'), raw('b')] if message['params']['offset'] == 0 else [])
        rows, _ = asyncio.run(m.fetch_pages(request, m.LAUNCH_MS, m.LAUNCH_MS + 60000, page_size=2))
        self.assertEqual(len(calls), 2)
        self.assertEqual(len(rows), 2)

    def test_ignored_offset_and_overfull_interval_rejected(self):
        async def repeat(message):
            return response(message, [raw('a'), raw('b')])
        with self.assertRaises(m.InvalidResponse):
            asyncio.run(m.fetch_pages(repeat, m.LAUNCH_MS, m.LAUNCH_MS + 60000, page_size=2))
        with self.assertRaises(m.PageBudget):
            asyncio.run(m.fetch_pages(repeat, m.LAUNCH_MS, m.LAUNCH_MS + 60000, page_size=2, max_pages=1))

    def test_invalid_timestamp_pair_and_decimal_rejected(self):
        for bad in [float('nan'), '-1', 'Infinity', '0', '1e5', '0.0']:
            with self.subTest(bad=bad), self.assertRaises(m.InvalidResponse):
                m.parse_trade(raw(price=bad), m.LAUNCH_MS, m.LAUNCH_MS + 60000)
        value = raw()
        value['timestampms'] += 1
        with self.assertRaises(m.InvalidResponse):
            m.parse_trade(value, m.LAUNCH_MS, m.LAUNCH_MS + 60000)
        async def wrong(message):
            return {'id': message['id'], 'result': {'symbol': 'ZEC/USDT', 'data': []}}
        with self.assertRaises(m.InvalidResponse):
            asyncio.run(m.fetch_pages(wrong, m.LAUNCH_MS, m.LAUNCH_MS + 60000))
        with self.assertRaises(m.InvalidResponse):
            m.decode('{"result": {}, "result": {}}')

    def test_bounded_chart_retains_all_time_totals(self):
        later = m.LAUNCH_MS + 300 * m.HOUR
        m.store_window(self.db, [row()], m.LAUNCH_MS, later, later, [])
        value = m.snapshot(self.db, later)
        self.assertEqual(len(value['buckets']), 168)
        self.assertEqual(value['windowStart'], value['buckets'][0]['start'])
        self.assertEqual(value['tradeCount'], 1)
        self.assertEqual(sum(bucket['tradeCount'] for bucket in value['buckets']), 0)

    def test_unobserved_hours_are_not_zero_volume_claims(self):
        value = m.snapshot(self.db, m.LAUNCH_MS + 3 * m.HOUR)
        self.assertEqual(value['status'], 'partial')
        self.assertIsNone(value['checkedThrough'])
        self.assertIsNone(value['coverageStart'])
        self.assertTrue(all(not bucket['covered'] for bucket in value['buckets']))

    def test_daily_backup_integrity_and_private_storage(self):
        m.import_seed(self.db, SEED)
        m.backup_daily(self.db, self.path / 'backups', m.LAUNCH_MS)
        backup = next((self.path / 'backups').glob('volume-*.sqlite3'))
        with sqlite3.connect(backup) as db:
            self.assertEqual(db.execute('PRAGMA integrity_check').fetchone()[0], 'ok')
            self.assertEqual(db.execute('SELECT count(*) FROM trades').fetchone()[0], 800)
        self.assertEqual((self.path / 'volume.sqlite3').stat().st_mode & 0o777, 0o600)
        m.publish(self.path / 'public.json', m.snapshot(self.db, m.LAUNCH_MS + 86400000))
        self.assertEqual((self.path / 'public.json').stat().st_mode & 0o777, 0o644)


if __name__ == '__main__':
    unittest.main()
