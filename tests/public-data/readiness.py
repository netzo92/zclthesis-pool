import copy
from decimal import Decimal
import importlib.util
import json
from pathlib import Path
import unittest
from unittest.mock import patch
from types import SimpleNamespace

path = Path(__file__).resolve().parents[2] / 'deploy/zcl/publish-richlist.py'
spec = importlib.util.spec_from_file_location('richlist_publisher', path)
publisher = importlib.util.module_from_spec(spec)
spec.loader.exec_module(publisher)

class Readiness(unittest.TestCase):
    def test_cli_scalar_hash_and_json_rpc(self):
        with patch.object(publisher.subprocess, 'run', return_value=SimpleNamespace(stdout='a'*64+'\n')):
            self.assertEqual(publisher.rpc('getblockhash', 3247730), 'a'*64)
        for output in ['not a hash', 'a'*64+'\nwarning', '123']:
            with patch.object(publisher.subprocess, 'run', return_value=SimpleNamespace(stdout=output)):
                with self.assertRaises(ValueError): publisher.rpc('getblockhash', 3247730)
        with patch.object(publisher.subprocess, 'run', return_value=SimpleNamespace(stdout='{"verificationprogress":0.99999}')):
            self.assertEqual(publisher.rpc('getblockchaininfo')['verificationprogress'], Decimal('.99999'))

    def fixture(self):
        return {'chain': 'main', 'blocks': 3247730, 'headers': 3247730, 'bestblockhash': 'a'*64,
            'verificationprogress': 1, 'bootstrap_validation': {'state': 'disabled', 'tip_hold': False},
            'finalization_hold': {'held': False}}, {'height': 3247730, 'hash': 'a'*64, 'time': 1789190000}

    def live(self, chain):
        return {'schemaVersion': 1, 'ready': True, 'tipHash': chain['bestblockhash'],
            'tipHeight': chain['blocks'], 'candidateHeight': chain['blocks'] - 10,
            'requiredDepth': 10, 'requiredPeers': 2,
            'reason': '2 independent outbound peers corroborate the chain at depth >=10'}

    def test_live_supersedes_stale_cached_hold(self):
        for cached in [{'held': False}, {'held': True}, {}, None]:
            chain, header = self.fixture()
            chain.update(live_corroboration=self.live(chain), finalization_hold=cached)
            publisher.require_current_chain(chain, header, header['time'] + 60)
        del chain['finalization_hold']
        publisher.require_current_chain(chain, header, header['time'] + 60)
        chain['live_corroboration'].update(requiredPeers=3, requiredDepth=20, candidateHeight=chain['blocks'] - 20)
        publisher.require_current_chain(chain, header, header['time'] + 60)

    def test_present_live_malformed_or_missing_fields_never_fall_back(self):
        chain, header = self.fixture()
        live = self.live(chain)
        malformed = [None, False, True, 1, 'ready', [], {}]
        malformed += [{key: value for key, value in live.items() if key != omitted} for omitted in live]
        for value in malformed:
            with self.subTest(value=value):
                chain['live_corroboration'] = value
                with self.assertRaises(RuntimeError):
                    publisher.require_current_chain(chain, header, header['time'] + 60)

    def test_false_inconsistent_and_wrong_type_live_fields_fail_closed(self):
        chain, header = self.fixture()
        height = chain['blocks']
        invalid = {'schemaVersion': [True, 1.0, '1', 2, None], 'ready': [False, 1, 'true', None],
            'tipHash': ['b'*64, 'A'*64, 'a'*63, None],
            'tipHeight': [height-1, str(height), float(height), True, -1],
            'candidateHeight': [height-9, str(height-10), float(height-10), True, -1],
            'requiredDepth': [0, -1, 10.0, '10', True, height+1],
            'requiredPeers': [0, 1, 2.0, '2', True], 'reason': [None, False, [], 1]}
        for field, values in invalid.items():
            for value in values:
                with self.subTest(field=field, value=value):
                    chain['live_corroboration'] = self.live(chain)
                    chain['live_corroboration'][field] = value
                    with self.assertRaises(RuntimeError):
                        publisher.require_current_chain(chain, header, header['time'] + 60)

    def test_absent_live_requires_explicit_cached_false(self):
        for cached in [None, {}, {'held': True}, {'held': 0}, {'held': 'false'}]:
            chain, header = self.fixture()
            chain['finalization_hold'] = cached
            with self.assertRaises(RuntimeError):
                publisher.require_current_chain(chain, header, header['time'] + 60)
        del chain['finalization_hold']
        with self.assertRaises(RuntimeError):
            publisher.require_current_chain(chain, header, header['time'] + 60)

    def test_live_preserves_other_readiness_guards(self):
        mutations = [lambda c: c.update(headers=c['blocks']-1), lambda c: c.update(chain='regtest'),
            lambda c: c.update(verificationprogress=.99), lambda c: c.update(initialblockdownload=True),
            lambda c: c['bootstrap_validation'].update(tip_hold=True),
            lambda c: c['bootstrap_validation'].update(state='provisional'),
            lambda c: c.update(bootstrap_validation={}), lambda c: c.update(bestblockhash='b'*64)]
        for change in mutations:
            chain, header = self.fixture()
            chain['live_corroboration'] = self.live(chain)
            change(chain)
            with self.assertRaises(RuntimeError):
                publisher.require_current_chain(chain, header, header['time'] + 60)
        chain, header = self.fixture()
        chain['live_corroboration'] = self.live(chain)
        for field, value in [('hash', 'b'*64), ('height', chain['blocks']-1),
                ('time', header['time']-1801), ('time', header['time']+301)]:
            wrong = dict(header, **{field: value})
            with self.assertRaises(RuntimeError):
                publisher.require_current_chain(chain, wrong, header['time'])

    def test_current_anchor_and_genesis_validation(self):
        chain, header = self.fixture()
        publisher.require_current_chain(chain, header, header['time'] + 60)
        chain['bootstrap_validation']['state'] = 'validated'
        publisher.require_current_chain(chain, header, header['time'] + 60)

    def test_exact_rpc_decimal_progress(self):
        chain, header = self.fixture()
        chain['verificationprogress'] = .99999
        # rpc() deliberately preserves decimal amounts; readiness sees the
        # same Decimal representation for fractional verification progress.
        chain = json.loads(json.dumps(chain), parse_float=Decimal)
        publisher.require_current_chain(chain, header, header['time'] + 60)
        for value in [Decimal('.99'), Decimal('NaN'), Decimal('Infinity'), True, '1.0']:
            chain['verificationprogress'] = value
            with self.assertRaises(RuntimeError):
                publisher.require_current_chain(chain, header, header['time'] + 60)
    def test_sync_and_active_or_unknown_validation_holds(self):
        mutations = [lambda c: c.update(blocks=3247729), lambda c: c.update(chain='regtest'),
            lambda c: c.update(verificationprogress=float('nan')), lambda c: c.update(verificationprogress=.99),
            lambda c: c.update(verificationprogress=1.01), lambda c: c.update(initialblockdownload=True),
            lambda c: c['bootstrap_validation'].update(tip_hold=True),
            lambda c: c['bootstrap_validation'].update(state='provisional'),
            lambda c: c.update(bootstrap_validation={}), lambda c: c['finalization_hold'].update(held=True),
            lambda c: c.update(finalization_hold={})]
        for change in mutations:
            chain, header = self.fixture()
            change(chain)
            with self.assertRaises(RuntimeError):
                publisher.require_current_chain(chain, header, header['time'] + 60)

    def test_stale_future_and_mismatched_header(self):
        chain, header = self.fixture()
        for now in [header['time'] + 1801, header['time'] - 301]:
            with self.assertRaises(RuntimeError): publisher.require_current_chain(chain, header, now)
        wrong = copy.copy(header); wrong['hash'] = 'b'*64
        with self.assertRaises(RuntimeError): publisher.require_current_chain(chain, wrong, header['time'])

if __name__ == '__main__': unittest.main()
