from contextlib import closing
import datetime as dt
from email.message import Message
import importlib.util
import http.client
import io
import json
from pathlib import Path
import signal
import sqlite3
import stat
import tempfile
import threading
import time
import unittest
from unittest.mock import Mock, patch


PATH = Path(__file__).resolve().parents[1] / 'record-prices.py'
SPEC = importlib.util.spec_from_file_location('record_prices', PATH)
recorder = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(recorder)
NOW = dt.datetime(2026, 9, 13, 0, 30, 2, 123000, tzinfo=dt.timezone.utc)
NOW_MS = recorder.milliseconds(NOW)


def market(**overrides):
    return {'symbol': 'ZCL/USDT', 'lastPrice': '0.342960000000000000000000001',
            'lastTradeAt': NOW_MS - 300000, 'volumeSecondary': '1298.213100',
            'changePercent': '+18.54', 'isActive': True, 'isPaused': False,
            'updatedAt': NOW_MS - 1000, 'lastPriceNumber': 0.34, 'unrelated': 'discard'} | overrides


class Response(io.BytesIO):
    def __init__(self, body, *, status=200, url=recorder.SOURCE, mime='application/json', length=None):
        super().__init__(body)
        self.status = status
        self.url = url
        self.headers = Message()
        self.headers['Content-Type'] = mime
        if length is not None:
            self.headers['Content-Length'] = str(length)

    def geturl(self):
        return self.url


class PriceTests(unittest.TestCase):
    def setUp(self):
        self.temporary = tempfile.TemporaryDirectory()
        self.addCleanup(self.temporary.cleanup)
        self.root = Path(self.temporary.name)
        self.database = self.root / 'state' / 'prices.sqlite3'
        self.public = self.root / 'public' / 'zcl-usdt.json'

    def run_recorder(self, when=NOW, payload=None, fetch=None):
        return recorder.run(self.database, self.public,
                            fetch=fetch or (lambda: market() if payload is None else payload),
                            now=lambda: when)

    def read_public(self):
        return json.loads(self.public.read_text())

    def test_exact_decimal_strings_and_distinct_exchange_observation_times(self):
        self.assertEqual(self.run_recorder(), 'ok')
        value = self.read_public()
        self.assertEqual(value['kind'], 'last-trade-observations')
        self.assertEqual(value['statusScope'], 'recorder_freshness')
        self.assertEqual(value['columns'], recorder.COLUMNS)
        self.assertEqual(value['samples'], [[
            '2026-09-13T00:30:02.123Z', '2026-09-13T00:25:02.123Z',
            '0.342960000000000000000000001', '1298.213100', '+18.54', False]])
        self.assertEqual(value['recordingStarted'], value['samples'][0][0])
        self.assertNotIn('lastPriceNumber', self.public.read_text())
        self.assertNotIn('updatedAt', self.public.read_text())
        self.assertNotIn('unrelated', self.public.read_text())

    def test_invalid_market_values_and_future_timestamps_fail_closed(self):
        invalid = [dict(symbol='ZCL/BTC'), dict(lastPrice=0.3), dict(lastPrice='0'),
                   dict(lastPrice='-1'), dict(lastPrice='NaN'), dict(lastPrice='Infinity'),
                   dict(lastPrice='1e1000000'), dict(lastPrice='1' * 97), dict(lastPrice=True),
                   dict(volumeSecondary='-1'), dict(changePercent='+NaN'),
                   dict(lastTradeAt=True), dict(lastTradeAt=NOW_MS / 1000),
                   dict(lastTradeAt=NOW_MS + 300001), dict(isActive='true'), dict(isPaused=0)]
        for overrides in invalid:
            with self.subTest(overrides=overrides), self.assertRaises(recorder.InvalidResponse):
                recorder.parse_market(market(**overrides), NOW_MS)
        self.run_recorder(payload=market(symbol='BTC/USDT'))
        self.assertEqual(self.read_public()['samples'], [])
        self.assertEqual(self.read_public()['failures'][0]['reason'], 'invalid_response')

    def test_old_trade_and_paused_market_are_successful_observations(self):
        old_trade = NOW_MS - 365 * 86400000
        self.assertEqual(self.run_recorder(payload=market(lastTradeAt=old_trade, isPaused=True)), 'ok')
        row = self.read_public()['samples'][0]
        self.assertEqual(row[1], recorder.timestamp(old_trade))
        self.assertTrue(row[-1])
        self.assertEqual(self.read_public()['failures'], [])
        data = market()
        for field in ('volumeSecondary', 'changePercent', 'isActive', 'isPaused'):
            del data[field]
        sample = recorder.parse_market(data, NOW_MS)
        self.assertIsNone(sample['volume24hQuote'])
        self.assertIsNone(sample['change24hPercent'])
        self.assertIsNone(sample['paused'])
        data['isActive'] = True
        self.assertIsNone(recorder.parse_market(data, NOW_MS)['paused'])
        data['isActive'] = False
        self.assertTrue(recorder.parse_market(data, NOW_MS)['paused'])

    def test_restart_duplicate_bucket_and_repeated_price_snapshots(self):
        fetch = Mock(return_value=market())
        self.run_recorder(fetch=fetch)
        first = self.read_public()
        self.run_recorder(when=NOW + dt.timedelta(seconds=20), fetch=fetch)
        self.assertEqual(fetch.call_count, 1)
        self.assertEqual(self.read_public()['samples'], first['samples'])
        self.run_recorder(when=NOW + dt.timedelta(minutes=1), fetch=fetch)
        self.assertEqual(fetch.call_count, 2)
        result = self.read_public()
        self.assertEqual(len(result['samples']), 2)
        self.assertEqual(result['samples'][0][1:], result['samples'][1][1:])
        self.assertEqual(result['recordingStarted'], first['recordingStarted'])
        with recorder.connect(self.database) as connection:
            self.assertEqual(connection.execute('SELECT COUNT(*) FROM observations').fetchone()[0], 2)
            self.assertEqual(connection.execute('PRAGMA journal_mode').fetchone()[0], 'wal')
            self.assertEqual(connection.execute('PRAGMA synchronous').fetchone()[0], 2)

    def test_failure_and_unobserved_gaps_remain_explicit_without_fabricated_prices(self):
        self.run_recorder()

        def unavailable():
            raise recorder.FetchFailure('network')

        self.assertEqual(self.run_recorder(when=NOW + dt.timedelta(minutes=1), fetch=unavailable), 'stale')
        self.run_recorder(when=NOW + dt.timedelta(minutes=5))
        result = self.read_public()
        self.assertEqual(result['status'], 'ok')
        self.assertEqual(result['recordCount'], 3)
        self.assertEqual([row[0] for row in result['samples']],
                         [recorder.timestamp(NOW_MS), recorder.timestamp(NOW_MS + 300000)])
        self.assertEqual(result['failures'], [{'observedAt': recorder.timestamp(NOW_MS + 60000), 'reason': 'network'}])
        self.assertFalse(result['truncated'])
        self.assertEqual(result['lastSuccessAt'], recorder.timestamp(NOW_MS + 300000))
        with recorder.connect(self.database) as connection:
            aged = recorder.snapshot(connection, NOW_MS + 600000)
        self.assertEqual(aged['status'], 'stale')

    def test_first_failure_has_no_zero_or_fake_sample_and_same_bucket_is_idempotent(self):
        fetch = Mock(side_effect=recorder.FetchFailure('http'))
        self.assertEqual(self.run_recorder(fetch=fetch), 'unavailable')
        self.run_recorder(when=NOW + dt.timedelta(seconds=15), fetch=fetch)
        self.assertEqual(fetch.call_count, 1)
        result = self.read_public()
        self.assertEqual(result['samples'], [])
        self.assertIsNone(result['lastSuccessAt'])
        self.assertEqual(result['recordCount'], 1)
        self.assertEqual(result['failures'][0]['reason'], 'http')

    def test_rolling_export_is_bounded_but_sqlite_retains_older_history(self):
        count = recorder.MAX_RECORDS + 7
        with recorder.connect(self.database) as connection:
            recorder.initialize(connection, NOW_MS)
            row = recorder.parse_market(market(), NOW_MS)
            connection.execute('BEGIN')
            for index in range(count):
                observed_ms = NOW_MS + index * 60000
                failed = index % 3 == 0
                connection.execute('''INSERT INTO observations
                    (bucket, observed_ms, last_trade_at, last_price, volume_quote, change_percent, paused, failure)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?)''',
                    (observed_ms // 60000, observed_ms,
                     None if failed else row['lastTradeAt'], None if failed else row['lastPrice'],
                     None if failed else row['volume24hQuote'], None if failed else row['change24hPercent'],
                     None if failed else 0, 'network' if failed else None))
            connection.commit()
            value = recorder.snapshot(connection, NOW_MS + (count - 1) * 60000)
            recorder.publish(self.public, value)
            self.assertEqual(connection.execute('SELECT COUNT(*) FROM observations').fetchone()[0], count)
        self.assertEqual(value['recordCount'], recorder.MAX_RECORDS)
        self.assertEqual(len(value['samples']) + len(value['failures']), recorder.MAX_RECORDS)
        self.assertGreaterEqual(min([row[0] for row in value['samples']] +
                                    [row['observedAt'] for row in value['failures']]), value['from'])
        self.assertLess(self.public.stat().st_size, recorder.MAX_PUBLIC_BYTES)
        self.assertEqual(value['recordingStarted'], recorder.timestamp(NOW_MS))

    def test_daily_online_backups_rotate_and_restore_all_committed_samples(self):
        for day in range(5):
            self.run_recorder(when=NOW + dt.timedelta(days=day))
        backups = sorted((self.database.parent / 'backups').glob('*.sqlite3'))
        self.assertEqual([path.name for path in backups],
                         ['prices-2026-09-15.sqlite3', 'prices-2026-09-16.sqlite3', 'prices-2026-09-17.sqlite3'])
        with closing(sqlite3.connect(backups[-1])) as restored:
            self.assertEqual(restored.execute('PRAGMA integrity_check').fetchone()[0], 'ok')
            self.assertEqual(restored.execute('SELECT COUNT(*) FROM observations').fetchone()[0], 5)
        before = backups[-1].read_bytes()
        self.run_recorder(when=NOW + dt.timedelta(days=4, minutes=1))
        self.assertEqual(backups[-1].read_bytes(), before)
        self.assertEqual(stat.S_IMODE(self.database.stat().st_mode), 0o600)
        self.assertEqual(stat.S_IMODE(self.database.parent.stat().st_mode), 0o700)
        self.assertEqual(stat.S_IMODE(backups[-1].stat().st_mode), 0o600)
        self.assertEqual(stat.S_IMODE(self.public.stat().st_mode), 0o644)

    def test_publication_failure_preserves_artifact_and_committed_sample_can_be_reexported(self):
        self.run_recorder()
        original = self.public.read_bytes()
        later = NOW + dt.timedelta(minutes=1)
        with patch.object(recorder.os, 'replace', side_effect=OSError('simulated write failure')):
            with self.assertRaises(OSError):
                self.run_recorder(when=later)
        self.assertEqual(self.public.read_bytes(), original)
        fetch = Mock(side_effect=AssertionError('Committed sample must not be fetched again'))
        self.run_recorder(when=later, fetch=fetch)
        self.assertEqual(len(self.read_public()['samples']), 2)
        self.assertEqual(list(self.public.parent.glob('.prices-*')), [])

    def test_failed_backup_preserves_previous_backup(self):
        self.run_recorder()
        original = next((self.database.parent / 'backups').glob('*.sqlite3'))
        before = original.read_bytes()
        with recorder.connect(self.database) as connection:
            with patch.object(recorder.os, 'replace', side_effect=OSError('simulated backup failure')):
                with self.assertRaises(OSError):
                    recorder.backup_daily(connection, original.parent, NOW_MS + 86400000)
        self.assertEqual(original.read_bytes(), before)
        self.assertEqual(list(original.parent.glob('.backup-*')), [])

    def test_overlapping_runs_are_bounded_and_do_not_duplicate_the_same_minute(self):
        entered, release = threading.Event(), threading.Event()
        errors = []

        def fetch():
            entered.set()
            if not release.wait(3):
                raise AssertionError('Test failed to release fetch')
            return market()

        def first_run():
            try:
                self.run_recorder(fetch=fetch)
            except BaseException as error:
                errors.append(error)

        thread = threading.Thread(target=first_run)
        thread.start()
        try:
            self.assertTrue(entered.wait(3))
            self.assertEqual(self.run_recorder(fetch=Mock(side_effect=AssertionError('Should not fetch'))), 'busy')
        finally:
            release.set()
            thread.join(3)
        self.assertFalse(thread.is_alive())
        self.assertEqual(errors, [])
        self.assertEqual(self.read_public()['recordCount'], 1)

    def test_http_fixed_url_size_redirect_and_invalid_json_bounds(self):
        valid = json.dumps(market()).encode()
        opener = Mock()
        opener.open.return_value = Response(valid)
        result = recorder.fetch_market(opener)
        self.assertEqual(result['lastPrice'], market()['lastPrice'])
        request = opener.open.call_args.args[0]
        self.assertEqual(request.full_url, recorder.SOURCE)
        self.assertEqual(opener.open.call_args.kwargs['timeout'], 5)
        invalid = [Response(b'x' * (recorder.MAX_RESPONSE_BYTES + 1)),
                   Response(valid, length=recorder.MAX_RESPONSE_BYTES + 1),
                   Response(valid, url='https://untrusted.invalid/'), Response(valid, status=302),
                   Response(valid, mime='text/html'), Response(b'{"lastPrice":"1","lastPrice":"2"}'),
                   Response(b'{"value":NaN}'), Response(b'[]garbage')]
        for response in invalid:
            with self.subTest(status=response.status, size=len(response.getvalue())):
                opener.open.return_value = response
                with self.assertRaises(recorder.FetchFailure):
                    recorder.fetch_market(opener)
                self.assertTrue(response.closed)
        with self.assertRaises(recorder.FetchFailure):
            recorder.NoRedirect().redirect_request(None, None, 302, None, None, 'https://untrusted.invalid/')

    def test_http_total_deadline_catches_slow_response_and_restores_signal(self):
        class SlowResponse(Response):
            def read1(self, _size):
                time.sleep(1)
                return b' '

        original = signal.getsignal(signal.SIGALRM)
        opener = Mock()
        opener.open.return_value = SlowResponse(b'')
        start = time.monotonic()
        with patch.object(recorder, 'FETCH_SECONDS', 0.05):
            with self.assertRaises(recorder.FetchFailure) as failure:
                recorder.fetch_market(opener)
        self.assertEqual(failure.exception.reason, 'network')
        self.assertLess(time.monotonic() - start, 0.5)
        self.assertEqual(signal.getsignal(signal.SIGALRM), original)
        self.assertEqual(signal.getitimer(signal.ITIMER_REAL), (0.0, 0.0))

    def test_truncated_http_response_is_recorded_as_a_network_failure(self):
        class TruncatedResponse(Response):
            def read1(self, _size):
                raise http.client.IncompleteRead(b'{"symbol":')

        opener = Mock()
        opener.open.return_value = TruncatedResponse(b'')
        self.assertEqual(self.run_recorder(fetch=lambda: recorder.fetch_market(opener)), 'unavailable')
        self.assertEqual(self.read_public()['failures'],
                         [{'observedAt': recorder.timestamp(NOW_MS), 'reason': 'network'}])
        self.assertEqual(self.read_public()['samples'], [])
        self.assertTrue(opener.open.return_value.closed)

    def test_database_failure_never_overwrites_last_public_history(self):
        self.run_recorder()
        original = self.public.read_bytes()
        with patch.object(recorder, 'connect', side_effect=sqlite3.DatabaseError('simulated corruption')):
            with self.assertRaises(sqlite3.DatabaseError):
                self.run_recorder(when=NOW + dt.timedelta(minutes=1))
        self.assertEqual(self.public.read_bytes(), original)


if __name__ == '__main__':
    unittest.main()
