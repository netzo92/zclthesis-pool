"""No node or network: exact next-height reward context and failure isolation."""
import importlib.util
from pathlib import Path
from decimal import Decimal
import subprocess
import unittest

spec = importlib.util.spec_from_file_location('node_stats', Path(__file__).resolve().parents[2] / 'deploy/zcl/publish-node-stats.py')
module = importlib.util.module_from_spec(spec)
spec.loader.exec_module(module)

class RewardContext(unittest.TestCase):
    def context(self, value=Decimal('0.78125000'), change=None):
        calls=[]
        def rpc(method, *args, **kwargs):
            calls.append((method, args, kwargs))
            if method == 'getblocksubsidy':
                if isinstance(value, Exception): raise value
                return {'miner': value}
            return change or {'blocks': 3249433, 'bestblockhash': 'a'*64}
        return module.next_block_subsidy(3249433, 'a'*64, '2026-09-13T18:00:00Z', rpc), calls

    def test_explicit_next_height_exact_amount_and_tip_binding(self):
        data,calls=self.context()
        self.assertEqual(data['status'],'ok')
        self.assertEqual(data['subsidyZat'],'78125000')
        self.assertEqual(data['height'],3249434)
        self.assertEqual(calls[0],('getblocksubsidy',(3249434,),{'exact':True,'timeout':5}))
        self.assertEqual(calls[1][0],'getblockchaininfo')
        self.assertEqual(data['tipHash'],'a'*64)
        self.assertEqual(data['basis'],'next-height-subsidy-excluding-transaction-fees')

    def test_exact_zatoshi_zero_and_supply_bound(self):
        for value,expected in [(Decimal('.00000001'),'1'),(Decimal('0'),'0'),(21000000,'2100000000000000')]:
            with self.subTest(value=value):self.assertEqual(self.context(value)[0]['subsidyZat'],expected)

    def test_bad_amounts_do_not_turn_into_money(self):
        for value in [True,0.78125,'0.78125',Decimal('NaN'),Decimal('Infinity'),Decimal('-.1'),Decimal('.000000001'),21000001,None]:
            with self.subTest(value=value):
                data,_=self.context(value);self.assertEqual(data['status'],'unavailable');self.assertIsNone(data['subsidyZat'])

    def test_tip_changes_and_invalid_context_suppress_projection(self):
        for changed in [{'blocks':3249434,'bestblockhash':'a'*64},{'blocks':3249433,'bestblockhash':'b'*64},{'blocks':True,'bestblockhash':'a'*64}]:
            self.assertEqual(self.context(change=changed)[0]['status'],'unavailable')
        def forbidden(*args,**kwargs):raise AssertionError('Invalid context must not call RPC')
        for height,hash_ in [(True,'a'*64),(-1,'a'*64),(1,'bad'),(2147483647,'a'*64)]:
            self.assertEqual(module.next_block_subsidy(height,hash_,'2026-09-13T18:00:00Z',forbidden)['status'],'unavailable')

    def test_rpc_timeout_only_removes_context(self):
        data,_=self.context(subprocess.TimeoutExpired('RPC',5))
        self.assertEqual(data['status'],'unavailable');self.assertIsNone(data['subsidyZat'])

if __name__ == '__main__':unittest.main()
