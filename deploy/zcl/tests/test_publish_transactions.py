from contextlib import redirect_stderr, redirect_stdout
import copy
import datetime as dt
from decimal import Decimal
import hashlib
import importlib.util
import io
import json
from pathlib import Path
import stat
import sys
import tempfile
import time
import unittest
from unittest.mock import patch


PATH = Path(__file__).resolve().parents[1] / 'publish-transactions.py'
SPEC = importlib.util.spec_from_file_location('publish_transactions', PATH)
publisher = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(publisher)
NOW = dt.datetime(2026, 9, 12, 22, 0, tzinfo=dt.timezone.utc)
ADDRESS = 't1cF1nLTs1Em1xMHn6FrLeUFsWt3aferGs5'


def digest(value):
    return hashlib.sha256(value.encode()).hexdigest()


def transaction(name, coinbase=False, values=None):
    values = [39062500] if values is None else values
    return {'txid': digest(name), 'version': 4, 'overwintered': True,
            'vin': [{'coinbase': '03010203', 'scriptSig': {'secret': 'discard'}}] if coinbase else
                   [{'txid': digest('input-' + name), 'vout': 0, 'scriptSig': {'hex': 'discard'}}],
            'vout': [{'n': index, 'valueZat': value, 'value': Decimal(value) / Decimal(100000000),
                      'scriptPubKey': {'addresses': [ADDRESS], 'type': 'pubkeyhash', 'hex': 'discard', 'asm': 'discard'}}
                     for index, value in enumerate(values)],
            'vjoinsplit': [], 'vShieldedSpend': [], 'vShieldedOutput': [], 'valueBalance': Decimal(0),
            'bindingSig': 'discard', 'hex': 'discard'}


class FakeNode:
    def __init__(self, height=110, tx_count=2):
        self.height = height
        self.blocks = {}
        self.canonical = {}
        self.calls = []
        self.pool = {}
        self.connections = 3
        self.chain_overrides = {}
        self.chain_reads = 0
        self.reorg_at_final_read = False
        self.make_blocks(0, height, tx_count, 'original')

    def make_blocks(self, first, last, tx_count, tag):
        for height in range(first, last + 1):
            block_hash = digest(f'{tag}-block-{height}')
            block = {'height': height, 'hash': block_hash,
                     'time': int(NOW.timestamp()) - (last - height) * 75,
                     'tx': [transaction(f'{tag}-tx-{height}-{i}', coinbase=i == 0) for i in range(tx_count)]}
            if height:
                block['previousblockhash'] = self.canonical[height - 1]
            self.canonical[height] = block_hash
            self.blocks[block_hash] = block

    def reorg(self, depth=3):
        self.make_blocks(self.height - depth + 1, self.height, 2, 'replacement')

    def advance(self):
        self.height += 1
        self.make_blocks(self.height, self.height, 2, 'extension')

    def __call__(self, method, *args):
        self.calls.append((method, *args))
        if method == 'getblockchaininfo':
            self.chain_reads += 1
            if self.reorg_at_final_read and self.chain_reads % 2 == 0:
                self.reorg()
            return {'chain': 'main', 'blocks': self.height, 'headers': self.height,
                    'bestblockhash': self.canonical[self.height], 'verificationprogress': Decimal(1)} | self.chain_overrides
        if method == 'getconnectioncount':
            return self.connections
        if method == 'getblock':
            block = copy.deepcopy(self.blocks[args[0]])
            block['confirmations'] = self.height - block['height'] + 1 if self.canonical.get(block['height']) == block['hash'] else -1
            if args[1] == 1:
                block['tx'] = [tx['txid'] for tx in block['tx']]
            return block
        if method == 'getblockhash':
            return self.canonical[args[0]]
        if method == 'getrawmempool':
            return copy.deepcopy(self.pool)
        raise AssertionError('Unexpected or nonpublic RPC: ' + method)


class TransactionTests(unittest.TestCase):
    def collect(self, node, cache=None):
        return publisher.collect(node, cache, now=lambda: NOW)

    def test_exact_public_amounts_all_outputs_and_no_hidden_inferences(self):
        raw = transaction('exact', values=[1, publisher.MAX_MONEY - 1])
        raw['vShieldedSpend'] = [{'nullifier': 'discard', 'proof': 'discard'}]
        raw['vShieldedOutput'] = [{'ciphertext': 'discard'}]
        raw['valueBalance'] = Decimal('12345.12345678')
        sanitized = publisher.sanitize_transaction(raw)
        self.assertEqual(sanitized['transparentOutputZat'], '2100000000000000')
        self.assertEqual(sanitized['outputs'][0]['amountZat'], '1')
        self.assertEqual(sanitized['outputs'][1]['amountZat'], '2099999999999999')
        self.assertTrue(sanitized['hasShieldedComponents'])
        self.assertEqual(sanitized['transparentInputCount'], 1)
        self.assertNotIn('discard', json.dumps(sanitized))
        self.assertFalse({'fee', 'amountSent', 'valueBalance', 'vin', 'bindingSig', 'hex'} & sanitized.keys())
        self.assertEqual(publisher.sanitize_transaction(transaction('reward', True))['transparentInputCount'], 0)
        for invalid in (True, 1.1, '1', -1, publisher.MAX_MONEY + 1):
            bad = copy.deepcopy(raw)
            bad['vout'][0]['valueZat'] = invalid
            with self.subTest(invalid=invalid), self.assertRaises(publisher.InvalidObservation):
                publisher.sanitize_transaction(bad)
        bad = transaction('mismatched')
        bad['vout'][0]['value'] = Decimal('0.39062501')
        with self.assertRaises(publisher.InvalidObservation):
            publisher.sanitize_transaction(bad)
        with self.assertRaises(publisher.InvalidObservation):
            publisher.sanitize_transaction(transaction('excess', values=[publisher.MAX_MONEY, 1]))

    def test_output_address_limits_checksum_and_script_allowlist(self):
        raw = transaction('many', values=[1] * 40)
        raw['vout'][0]['scriptPubKey']['addresses'] = [ADDRESS] * 10
        raw['vout'][1]['scriptPubKey']['type'] = 'unexpected-private-string'
        sanitized = publisher.sanitize_transaction(raw)
        self.assertEqual(sanitized['transparentOutputCount'], 40)
        self.assertEqual(sanitized['transparentOutputZat'], '40')
        self.assertEqual(len(sanitized['outputs']), 32)
        self.assertEqual(len(sanitized['outputs'][0]['addresses']), 8)
        self.assertEqual(sanitized['outputs'][1]['scriptType'], 'other')
        self.assertTrue(sanitized['outputsTruncated'])
        for bad_address in (ADDRESS[:-1] + '1', 'https://private.example/', 'zs' + 'a' * 33, 'tm' + 'a' * 33):
            raw['vout'][0]['scriptPubKey']['addresses'] = [bad_address]
            with self.assertRaises(publisher.InvalidObservation):
                publisher.sanitize_transaction(raw)
        no_address = transaction('nulldata')
        no_address['vout'][0]['scriptPubKey'] = {'type': 'nulldata', 'hex': 'discard'}
        self.assertEqual(publisher.sanitize_transaction(no_address)['outputs'][0]['addresses'], [])

    def test_shielded_component_presence_is_from_public_lists(self):
        raw = transaction('shielded')
        self.assertFalse(publisher.sanitize_transaction(raw)['hasShieldedComponents'])
        for key in ('vjoinsplit', 'vShieldedSpend', 'vShieldedOutput'):
            value = copy.deepcopy(raw)
            value[key] = [{'ciphertext': 'not-published'}]
            self.assertTrue(publisher.sanitize_transaction(value)['hasShieldedComponents'])
        del raw['vShieldedSpend']
        with self.assertRaises(publisher.InvalidObservation):
            publisher.sanitize_transaction(raw)

    def test_all_100_blocks_counted_even_when_transaction_limit_reached(self):
        node = FakeNode()
        result, cache = self.collect(node)
        self.assertEqual(result['status'], 'ok')
        self.assertEqual(result['coverage'], {'blocksScanned': 100, 'oldestHeight': 11,
                                             'transactionLimit': 100, 'transactionsTruncated': True})
        self.assertEqual(len(result['transactions']), 100)
        self.assertEqual(len([call for call in node.calls if call[0] == 'getblock']), 100)
        self.assertEqual(result['transactions'][0]['blockHeight'], 110)
        self.assertFalse(result['transactions'][0]['isCoinbase'])
        self.assertTrue(result['transactions'][1]['isCoinbase'])
        self.assertEqual(result['transactions'][-1]['confirmations'], 50)
        self.assertEqual(len(cache['blocks']), 100)
        self.assertEqual(sum(len(block['transactions']) for block in cache['blocks']), 100)
        self.assertNotIn('discard', json.dumps(cache))
        single_reward, _ = self.collect(FakeNode(tx_count=1))
        self.assertFalse(single_reward['coverage']['transactionsTruncated'])
        self.assertTrue(all(tx['isCoinbase'] for tx in single_reward['transactions']))

    def test_warm_cache_extension_reorg_and_rollback_refetch(self):
        node = FakeNode()
        _, cache = self.collect(node)
        with tempfile.TemporaryDirectory() as directory:
            path = Path(directory) / 'cache.json'
            publisher.atomic_json(path, cache, 0o600, publisher.MAX_CACHE_BYTES)
            cached = publisher.load_cache(path)
        node.calls.clear()
        result, cache = self.collect(node, cached)
        self.assertFalse(any(call[0] == 'getblock' for call in node.calls))
        node.advance()
        node.calls.clear()
        extended, extended_cache = self.collect(node, {block['hash']: block for block in cache['blocks']})
        self.assertEqual(len([call for call in node.calls if call[0] == 'getblock']), 1)
        # Advancing the window drops its oldest block and adds only the new tip.
        self.assertEqual(extended['chain']['height'], 111)
        node.reorg()
        reorged, reorg_cache = self.collect(node, {block['hash']: block for block in extended_cache['blocks']})
        self.assertNotEqual(extended['chain']['hash'], reorged['chain']['hash'])
        self.assertNotEqual(extended['transactions'][0]['txid'], reorged['transactions'][0]['txid'])
        node.height -= 10
        node.calls.clear()
        rolled_back, _ = self.collect(node, {block['hash']: block for block in reorg_cache['blocks']})
        self.assertEqual(len(rolled_back['transactions']), 100)
        self.assertTrue(any(call[0] == 'getblock' and call[2] == 2 for call in node.calls))
        self.assertEqual(rolled_back['transactions'][0]['confirmations'], 1)

    def test_mempool_own_node_time_size_and_bounds(self):
        node = FakeNode()
        for index in range(60):
            node.pool[digest(f'pending-{index}')] = {'time': int(NOW.timestamp()) - index,
                                                    'size': 200 + index, 'fee': Decimal('0.00001'),
                                                    'depends': ['not-exported'], 'height': 110}
        result, _ = self.collect(node)
        pool = result['mempool']
        self.assertEqual(pool['total'], 60)
        self.assertEqual(pool['limit'], 50)
        self.assertEqual(len(pool['transactions']), 50)
        self.assertTrue(pool['truncated'])
        self.assertEqual(pool['transactions'][0], {'txid': digest('pending-0'),
                         'localNodeSeenAt': '2026-09-12T22:00:00Z', 'sizeBytes': 200})
        self.assertNotIn('fee', json.dumps(pool))
        del node.pool[digest('pending-0')]['time']
        with self.assertRaises(KeyError):
            self.collect(node)

    def test_syncing_observation_and_mainnet_validation(self):
        for mutation in ({'verificationprogress': Decimal('.99')}, {'headers': 111}, {'initialblockdownload': True}):
            node = FakeNode()
            node.chain_overrides = mutation
            result, _ = self.collect(node)
            self.assertEqual(result['status'], 'syncing')
            self.assertFalse(result['node']['synced'])
            self.assertTrue(result['transactions'])
        node = FakeNode()
        node.connections = 0
        self.assertEqual(self.collect(node)[0]['status'], 'syncing')
        for mutation in ({'chain': 'regtest'}, {'blocks': True}, {'bestblockhash': 'bad'}, {'verificationprogress': Decimal('NaN')}):
            node = FakeNode()
            node.chain_overrides = mutation
            with self.assertRaises(publisher.InvalidObservation):
                self.collect(node)

    def test_initial_unavailable_then_success_and_preserve_on_rpc_failure_or_reorg(self):
        with tempfile.TemporaryDirectory() as directory:
            public = Path(directory) / 'api' / 'transactions.json'
            cache = Path(directory) / 'state' / 'cache.json'
            def unavailable(*_):
                raise OSError('private daemon detail')
            self.assertFalse(publisher.publish(public, cache, unavailable, lambda: NOW))
            initial = json.loads(public.read_text())
            self.assertEqual(initial['status'], 'unavailable')
            self.assertIsNone(initial['chain'])
            self.assertIsNone(initial['mempool'])
            self.assertEqual(initial['transactions'], [])
            node = FakeNode()
            self.assertTrue(publisher.publish(public, cache, node, lambda: NOW))
            self.assertEqual(stat.S_IMODE(public.stat().st_mode), 0o644)
            self.assertEqual(stat.S_IMODE(cache.stat().st_mode), 0o600)
            good = public.read_bytes()
            self.assertFalse(publisher.publish(public, cache, unavailable, lambda: NOW + dt.timedelta(seconds=30)))
            self.assertEqual(public.read_bytes(), good)
            node.reorg_at_final_read = True
            self.assertFalse(publisher.publish(public, cache, node, lambda: NOW))
            self.assertEqual(public.read_bytes(), good)
            self.assertFalse(publisher.publish(public, cache, lambda *_: None, lambda: NOW))
            self.assertEqual(public.read_bytes(), good)

    def test_corrupt_cache_is_refetched_and_no_extra_cache_fields_published(self):
        with tempfile.TemporaryDirectory() as directory:
            path = Path(directory) / 'cache.json'
            for malformed in ('[1]', '{', '{"schemaVersion":true,"blocks":[]}', 'null'):
                path.write_text(malformed)
                self.assertEqual(publisher.load_cache(path), {})
            node = FakeNode()
            _, cache = self.collect(node)
            cache['blocks'][0]['transactions'][0]['privateData'] = 'never-publish'
            path.write_text(json.dumps(cache))
            loaded = publisher.load_cache(path)
            self.assertTrue(loaded)
            result, _ = self.collect(node, loaded)
            self.assertNotIn('never-publish', json.dumps(result))
            cache['blocks'][0]['transactions'][0]['outputs'][0]['amountZat'] = '-1'
            path.write_text(json.dumps(cache))
            self.assertEqual(publisher.load_cache(path), {})

    def test_atomic_rename_failure_and_size_bounds_keep_prior_file(self):
        with tempfile.TemporaryDirectory() as directory:
            path = Path(directory) / 'transactions.json'
            path.write_bytes(b'prior-observation')
            with patch.object(publisher.os, 'replace', side_effect=OSError('failed')):
                with self.assertRaises(OSError):
                    publisher.atomic_json(path, {'safe': True}, 0o644, 100)
            self.assertEqual(path.read_bytes(), b'prior-observation')
            self.assertEqual(list(Path(directory).iterdir()), [path])
            with self.assertRaises(publisher.InvalidObservation):
                publisher.atomic_json(path, {'oversized': 'x' * 100}, 0o644, 20)
            self.assertEqual(path.read_bytes(), b'prior-observation')

    def test_rpc_allowlist_decimal_scalar_size_and_deadline(self):
        with patch.object(publisher.subprocess, 'Popen') as process:
            with self.assertRaises(publisher.InvalidObservation):
                publisher.rpc('getwalletinfo')
            process.assert_not_called()
        with patch.object(publisher, 'CLI', [sys.executable, '-c', 'print("{\\\"value\\\":0.00000001}")']):
            self.assertEqual(publisher.rpc('getblock')['value'], Decimal('.00000001'))
        with patch.object(publisher, 'CLI', [sys.executable, '-c', 'print("a"*64)']):
            self.assertEqual(publisher.rpc('getblockhash', 1), 'a' * 64)
        with patch.object(publisher, 'CLI', [sys.executable, '-c', 'print("x"*1000)']), patch.object(publisher, 'MAX_RPC_BYTES', 100):
            with self.assertRaises(publisher.InvalidObservation):
                publisher.rpc('getblock')
        with patch.object(publisher, 'CLI', [sys.executable, '-c', 'import time; time.sleep(5)']):
            with self.assertRaises(publisher.InvalidObservation):
                publisher.rpc('getblock', deadline=time.monotonic() + .1)
        with self.assertRaises(publisher.InvalidObservation):
            publisher.collect(FakeNode(), deadline=0)


if __name__ == '__main__':
    unittest.main()
