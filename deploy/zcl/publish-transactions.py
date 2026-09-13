#!/usr/bin/env python3
"""Publish bounded public-chain observations; never call wallet or index RPCs."""
import datetime as dt
from decimal import Decimal
import fcntl
import hashlib
import json
import math
import os
from pathlib import Path
import re
import selectors
import subprocess
import tempfile
import time


PUBLIC = Path('/var/lib/zcl-public/api/transactions.json')
STATE = Path('/var/lib/zcl-transactions')
CACHE = STATE / 'cache.json'
CLI = ['/usr/sbin/runuser', '-u', 'zclnode', '--', '/opt/zclassic/zclassic-cli',
       '-datadir=/var/lib/zclassic', '-rpcclienttimeout=10']
RPC_METHODS = frozenset({'getblockchaininfo', 'getconnectioncount', 'getblock', 'getblockhash', 'getrawmempool'})
MAX_BLOCKS = 100
MAX_TRANSACTIONS = 100
MAX_OUTPUTS = 32
MAX_ADDRESSES = 8
MAX_MEMPOOL = 50
MAX_MONEY = 2100000000000000  # src/amount.h: 21,000,000 * COIN.
MAX_RPC_BYTES = 16 * 1024 * 1024
MAX_PUBLIC_BYTES = 2 * 1024 * 1024
MAX_CACHE_BYTES = 4 * 1024 * 1024
COLLECTION_SECONDS = 60
SCRIPT_TYPES = frozenset({'pubkey', 'pubkeyhash', 'scripthash', 'multisig', 'nulldata', 'other'})
BASE58 = '123456789ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz'


class InvalidObservation(ValueError):
    pass


def integer(value, minimum=0, maximum=MAX_MONEY):
    if type(value) is not int or not minimum <= value <= maximum:
        raise InvalidObservation('Invalid integer')
    return value


def boolean(value):
    if type(value) is not bool:
        raise InvalidObservation('Invalid boolean')
    return value


def block_hash(value):
    if type(value) is not str or not re.fullmatch(r'[0-9a-f]{64}', value):
        raise InvalidObservation('Invalid hash')
    return value


def timestamp(epoch):
    integer(epoch, 0, 253402300799)
    return dt.datetime.fromtimestamp(epoch, dt.timezone.utc).isoformat().replace('+00:00', 'Z')


def address(value):
    if type(value) is not str or len(value) != 35 or any(char not in BASE58 for char in value):
        raise InvalidObservation('Invalid transparent address')
    decoded = 0
    for char in value:
        decoded = decoded * 58 + BASE58.index(char)
    raw = decoded.to_bytes((decoded.bit_length() + 7) // 8, 'big')
    if (len(raw) != 26 or raw[:2] not in (b'\x1c\xb8', b'\x1c\xbd')
            or hashlib.sha256(hashlib.sha256(raw[:-4]).digest()).digest()[:4] != raw[-4:]):
        raise InvalidObservation('Invalid transparent address checksum or network')
    return value


def amount_string(value):
    if type(value) is not str or not re.fullmatch(r'0|[1-9][0-9]{0,15}', value):
        raise InvalidObservation('Invalid base-unit amount')
    return str(integer(int(value)))


def rpc(method, *args, deadline=None):
    if method not in RPC_METHODS:
        raise InvalidObservation('RPC method is not public allowlisted')
    expires = min(time.monotonic() + 12, deadline if deadline is not None else math.inf)
    if expires <= time.monotonic():
        raise InvalidObservation('Collection deadline exceeded')
    process = subprocess.Popen(CLI + [method, *map(str, args)], stdout=subprocess.PIPE,
                               stderr=subprocess.DEVNULL)
    chunks = []
    size = 0
    try:
        with selectors.DefaultSelector() as selector:
            selector.register(process.stdout, selectors.EVENT_READ)
            while True:
                remaining = expires - time.monotonic()
                if remaining <= 0 or not selector.select(remaining):
                    raise InvalidObservation('RPC deadline exceeded')
                chunk = os.read(process.stdout.fileno(), 65536)
                if not chunk:
                    break
                size += len(chunk)
                if size > MAX_RPC_BYTES:
                    raise InvalidObservation('RPC response exceeds bound')
                chunks.append(chunk)
        if process.wait(timeout=max(0.001, expires - time.monotonic())) != 0:
            raise InvalidObservation('Public node RPC failed')
        raw = b''.join(chunks).decode('utf-8')
        if method == 'getblockhash':
            return block_hash(raw.strip())  # CLI string results are unquoted.
        return json.loads(raw, parse_float=Decimal)
    finally:
        if process.poll() is None:
            process.kill()
        process.wait()
        process.stdout.close()


def sanitize_transaction(transaction):
    txid = block_hash(transaction['txid'])
    vin, vout = transaction['vin'], transaction['vout']
    if type(vin) is not list or type(vout) is not list:
        raise InvalidObservation('Missing decoded transparent components')
    coinbase = any(type(entry) is dict and 'coinbase' in entry for entry in vin)
    if coinbase:
        if len(vin) != 1 or type(vin[0]['coinbase']) is not str or not re.fullmatch(r'(?:[0-9a-f]{2})+', vin[0]['coinbase']):
            raise InvalidObservation('Invalid coinbase input')
    else:
        for entry in vin:
            block_hash(entry['txid'])
            integer(entry['vout'], 0, 0xffffffff)
    outputs = []
    total = 0
    truncated = len(vout) > MAX_OUTPUTS
    for index, output in enumerate(vout):
        if integer(output['n']) != index:
            raise InvalidObservation('Inconsistent output index')
        amount = integer(output['valueZat'])
        # valueZat is emitted directly from CAmount; never derive it from a float.
        if 'value' in output:
            value = output['value']
            if type(value) not in (Decimal, int) or not Decimal(value).is_finite() or Decimal(value) * 100000000 != amount:
                raise InvalidObservation('Inconsistent RPC amount representations')
        total = integer(total + amount)
        script = output['scriptPubKey']
        addresses = script.get('addresses', [])
        if type(addresses) is not list:
            raise InvalidObservation('Invalid output addresses')
        for item in addresses:
            address(item)
        script_type = script.get('type')
        if type(script_type) is not str:
            raise InvalidObservation('Invalid script type')
        if index < MAX_OUTPUTS:
            truncated = truncated or len(addresses) > MAX_ADDRESSES
            outputs.append({'n': index, 'amountZat': str(amount),
                            'addresses': addresses[:MAX_ADDRESSES],
                            'scriptType': script_type if script_type in SCRIPT_TYPES else 'other'})
    version = integer(transaction['version'], 1, 0x7fffffff)
    joinsplits = transaction.get('vjoinsplit')
    if type(joinsplits) is not list:
        raise InvalidObservation('Missing public JoinSplit component list')
    spends = transaction.get('vShieldedSpend', [])
    shielded_outputs = transaction.get('vShieldedOutput', [])
    if type(spends) is not list or type(shielded_outputs) is not list:
        raise InvalidObservation('Invalid public Sapling component list')
    if version >= 4 and (transaction.get('overwintered') is not True
                         or 'vShieldedSpend' not in transaction or 'vShieldedOutput' not in transaction):
        raise InvalidObservation('Missing public Sapling components')
    return {'txid': txid, 'isCoinbase': coinbase, 'transparentInputCount': 0 if coinbase else len(vin),
            'transparentOutputCount': len(vout), 'transparentOutputZat': str(total),
            'outputs': outputs, 'outputsTruncated': truncated,
            'hasShieldedComponents': bool(joinsplits or spends or shielded_outputs)}


def cached_transaction(value):
    """Reconstruct the allowlist so a malformed cache cannot publish extra fields."""
    total = amount_string(value['transparentOutputZat'])
    count = integer(value['transparentOutputCount'], 0, 200000)
    outputs = value['outputs']
    if type(outputs) is not list or len(outputs) != min(count, MAX_OUTPUTS):
        raise InvalidObservation('Invalid cached outputs')
    clean = []
    for index, output in enumerate(outputs):
        if integer(output['n']) != index or output['scriptType'] not in SCRIPT_TYPES:
            raise InvalidObservation('Invalid cached script')
        addresses = output['addresses']
        if type(addresses) is not list or len(addresses) > MAX_ADDRESSES:
            raise InvalidObservation('Invalid cached addresses')
        clean.append({'n': index, 'amountZat': amount_string(output['amountZat']),
                      'addresses': [address(item) for item in addresses], 'scriptType': output['scriptType']})
    subtotal = sum(int(output['amountZat']) for output in clean)
    if subtotal > int(total) or count <= MAX_OUTPUTS and subtotal != int(total):
        raise InvalidObservation('Inconsistent cached output total')
    truncated = boolean(value['outputsTruncated'])
    if count > MAX_OUTPUTS and not truncated:
        raise InvalidObservation('Missing cache truncation flag')
    coinbase = boolean(value['isCoinbase'])
    inputs = integer(value['transparentInputCount'], 0, 200000)
    if coinbase and inputs:
        raise InvalidObservation('Invalid cached coinbase')
    return {'txid': block_hash(value['txid']), 'isCoinbase': coinbase,
            'transparentInputCount': inputs, 'transparentOutputCount': count,
            'transparentOutputZat': total, 'outputs': clean, 'outputsTruncated': truncated,
            'hasShieldedComponents': boolean(value['hasShieldedComponents'])}


def block_metadata(value):
    height = integer(value['height'], 0, 0x7fffffff)
    previous = block_hash(value['previousblockhash']) if height else None
    epoch = integer(value['time'], 0, 253402300799)
    timestamp(epoch)
    return {'hash': block_hash(value['hash']), 'height': height, 'time': epoch,
            'previousblockhash': previous, 'transactionCount': integer(value['transactionCount'], 1, 200000)}


def load_cache(path):
    try:
        with path.open('rb') as stream:
            raw = stream.read(MAX_CACHE_BYTES + 1)
        if len(raw) > MAX_CACHE_BYTES:
            return {}
        value = json.loads(raw)
        if (type(value) is not dict or type(value.get('schemaVersion')) is not int or value['schemaVersion'] != 1
                or type(value.get('blocks')) is not list or len(value['blocks']) > MAX_BLOCKS):
            return {}
        blocks = {}
        total_rows = 0
        seen = set()
        for item in value['blocks']:
            block = block_metadata(item)
            rows = item['transactions']
            if type(rows) is not list or len(rows) > min(MAX_TRANSACTIONS, block['transactionCount']):
                return {}
            block['transactions'] = [cached_transaction(row) for row in rows]
            total_rows += len(rows)
            if total_rows > MAX_TRANSACTIONS or block['hash'] in blocks:
                return {}
            for row in block['transactions']:
                if row['txid'] in seen:
                    return {}
                seen.add(row['txid'])
            blocks[block['hash']] = block
        return blocks
    except (OSError, ValueError, TypeError, KeyError, OverflowError, AttributeError):
        return {}  # A cache miss always goes back to public block RPCs.


def chain_tip(chain):
    if chain.get('chain') != 'main':
        raise InvalidObservation('Unexpected chain')
    height = integer(chain['blocks'], 1, 0x7fffffff)
    headers = integer(chain['headers'], height, 0x7fffffff)
    progress = chain['verificationprogress']
    if type(progress) not in (Decimal, int, float) or not math.isfinite(progress) or not 0 <= progress <= 1.000001:
        raise InvalidObservation('Invalid verification progress')
    if type(chain.get('initialblockdownload', False)) is not bool:
        raise InvalidObservation('Invalid synchronization status')
    return height, block_hash(chain['bestblockhash']), headers, progress


def collect(rpc_call, cache=None, now=None, monotonic=time.monotonic, deadline=None):
    cache = cache or {}
    now = now or (lambda: dt.datetime.now(dt.timezone.utc))
    deadline = deadline if deadline is not None else monotonic() + COLLECTION_SECONDS
    def call(method, *args):
        if monotonic() >= deadline:
            raise InvalidObservation('Collection deadline exceeded')
        return rpc_call(method, *args)
    chain = call('getblockchaininfo')
    tip_height, tip_hash, headers, progress = chain_tip(chain)
    connections = integer(call('getconnectioncount'), 0, 100000)
    blocks, transactions, seen = [], [], set()
    current_hash = tip_hash
    observed_count = 0
    for offset in range(min(MAX_BLOCKS, tip_height + 1)):
        height = tip_height - offset
        block = cache.get(current_hash)
        remaining = MAX_TRANSACTIONS - len(transactions)
        if block is None or block['height'] != height or len(block['transactions']) < min(remaining, block['transactionCount']):
            raw = call('getblock', current_hash, 2 if remaining else 1)
            txs = raw['tx']
            if type(txs) is not list or not txs:
                raise InvalidObservation('Empty decoded block')
            if integer(raw['confirmations'], 1) != tip_height - height + 1:
                raise InvalidObservation('Block confirmations changed during collection')
            block = block_metadata(dict(raw, transactionCount=len(txs)))
            if remaining:
                if not sanitize_transaction(txs[0])['isCoinbase']:
                    raise InvalidObservation('Block has no leading coinbase')
                block['transactions'] = [sanitize_transaction(tx) for tx in reversed(txs[-remaining:])]
            else:
                for txid in txs:
                    block_hash(txid)
                block['transactions'] = []
        if block['height'] != height or block['hash'] != current_hash:
            raise InvalidObservation('Inconsistent block ancestry')
        # Keep only the newest 100 rows across the entire cache, plus metadata
        # for all 100 blocks. Rollbacks refetch any newly needed omitted rows.
        kept = block['transactions'][:remaining]
        block = dict(block, transactions=kept)
        for tx in kept:
            if tx['txid'] in seen:
                raise InvalidObservation('Duplicate confirmed transaction')
            seen.add(tx['txid'])
            transactions.append(dict(tx, blockHeight=height, blockHash=current_hash,
                                     blockAt=timestamp(block['time']), confirmations=tip_height - height + 1))
        blocks.append(block)
        observed_count += block['transactionCount']
        current_hash = block['previousblockhash']
    pool = call('getrawmempool', 'true')
    if type(pool) is not dict:
        raise InvalidObservation('Invalid public mempool response')
    pool_rows = []
    for txid, entry in pool.items():
        block_hash(txid)
        if txid in seen:
            raise InvalidObservation('Transaction in both confirmed and mempool observations')
        epoch = integer(entry['time'], 0, 253402300799)
        pool_rows.append((epoch, txid, integer(entry['size'], 1, 200000)))
    pool_rows.sort(key=lambda entry: (-entry[0], entry[1]))
    pending = [{'txid': txid, 'localNodeSeenAt': timestamp(epoch), 'sizeBytes': size}
               for epoch, txid, size in pool_rows[:MAX_MEMPOOL]]
    final = call('getblockchaininfo')
    if chain_tip(final)[:2] != (tip_height, tip_hash) or call('getblockhash', tip_height) != tip_hash:
        raise InvalidObservation('Tip changed during collection')
    observed_at = now().astimezone(dt.timezone.utc)
    if any(epoch > observed_at.timestamp() + 300 for epoch, _, _ in pool_rows):
        raise InvalidObservation('Invalid future mempool admission time')
    age = observed_at.timestamp() - blocks[0]['time']
    synced = (not chain.get('initialblockdownload', False) and headers == tip_height
              and progress >= Decimal('0.9999') and connections > 0 and -300 <= age <= 1800)
    result = {'schemaVersion': 1, 'asset': 'ZCL', 'source': 'https://pool.zclthesis.com',
              'generatedAt': observed_at.isoformat().replace('+00:00', 'Z'),
              'status': 'ok' if synced else 'syncing',
              'chain': {'height': tip_height, 'hash': tip_hash, 'blockAt': timestamp(blocks[0]['time'])},
              'node': {'synced': synced, 'connections': connections},
              'coverage': {'blocksScanned': len(blocks), 'oldestHeight': blocks[-1]['height'],
                           'transactionLimit': MAX_TRANSACTIONS, 'transactionsTruncated': observed_count > len(transactions)},
              'transactions': transactions,
              'mempool': {'total': len(pool), 'limit': MAX_MEMPOOL, 'truncated': len(pool) > len(pending),
                          'transactions': pending}}
    return result, {'schemaVersion': 1, 'blocks': blocks}


def atomic_json(path, value, mode, maximum):
    raw = json.dumps(value, separators=(',', ':'), allow_nan=False).encode('utf-8')
    if len(raw) > maximum:
        raise InvalidObservation('JSON artifact exceeds bound')
    path.parent.mkdir(parents=True, exist_ok=True, mode=0o755 if mode == 0o644 else 0o700)
    descriptor, temporary = tempfile.mkstemp(prefix='.transactions-', dir=path.parent)
    try:
        with os.fdopen(descriptor, 'wb') as stream:
            stream.write(raw)
            os.fchmod(stream.fileno(), mode)
            stream.flush()
            os.fsync(stream.fileno())
        os.replace(temporary, path)
        directory = os.open(path.parent, os.O_RDONLY | os.O_DIRECTORY)
        try:
            os.fsync(directory)
        finally:
            os.close(directory)
    finally:
        if os.path.exists(temporary):
            os.unlink(temporary)


def publish(public=PUBLIC, cache_path=CACHE, rpc_call=None, now=None):
    now = now or (lambda: dt.datetime.now(dt.timezone.utc))
    deadline = time.monotonic() + COLLECTION_SECONDS
    rpc_call = rpc_call or (lambda method, *args: rpc(method, *args, deadline=deadline))
    try:
        result, cache = collect(rpc_call, load_cache(cache_path), now, deadline=deadline)
        # Check both bounds before replacing either artifact.
        if len(json.dumps(result, separators=(',', ':'), allow_nan=False).encode()) > MAX_PUBLIC_BYTES:
            raise InvalidObservation('Public artifact exceeds bound')
        atomic_json(cache_path, cache, 0o600, MAX_CACHE_BYTES)
        atomic_json(public, result, 0o644, MAX_PUBLIC_BYTES)
        return True
    except (subprocess.SubprocessError, ValueError, KeyError, OSError, TypeError, OverflowError, AttributeError):
        if not public.exists():
            atomic_json(public, {'schemaVersion': 1, 'asset': 'ZCL', 'source': 'https://pool.zclthesis.com',
                                'generatedAt': now().isoformat().replace('+00:00', 'Z'), 'status': 'unavailable',
                                'chain': None, 'node': None, 'coverage': None,
                                'transactions': [], 'mempool': None}, 0o644, MAX_PUBLIC_BYTES)
        return False


def main():
    STATE.mkdir(parents=True, exist_ok=True, mode=0o700)
    with (STATE / 'publish.lock').open('a') as lock:
        try:
            fcntl.flock(lock, fcntl.LOCK_EX | fcntl.LOCK_NB)
        except BlockingIOError:
            print('{"published":false,"reason":"already-running"}')
            return 0
        ok = publish()
    print(json.dumps({'published': ok, 'reason': 'complete' if ok else 'retained-prior-observation'}))
    return 0 if ok else 1


if __name__ == '__main__':
    raise SystemExit(main())
