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
