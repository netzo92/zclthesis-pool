#!/usr/bin/env python3
"""Verify and publish a coherent own-node UTXO snapshot. Requires Btrfs and root."""
import datetime as dt
from decimal import Decimal
import fcntl
import json
import os
from pathlib import Path
import shutil
import subprocess
import tempfile
import uuid

CLI = ['/usr/sbin/runuser', '-u', 'zclnode', '--', '/opt/zclassic/zclassic-cli', '-datadir=/var/lib/zclassic', '-rpcclienttimeout=600']
SITE = Path('/opt/zcl-pool/thesis')
PUBLIC = Path('/var/lib/zcl-public/api/richlist/zcl.json')
SNAPSHOTS = Path('/var/lib/zcl-data/private-snapshots')

def rpc(method, *args):
    result = subprocess.run(CLI + [method, *map(str, args)], capture_output=True, text=True, check=True, timeout=650)
    return json.loads(result.stdout, parse_float=Decimal)

def units(value):
    amount = Decimal(str(value)) * 100000000
    if amount < 0 or amount != amount.to_integral_value():
        raise ValueError('RPC amount is not an exact nonnegative base-unit amount')
    return str(int(amount))

def iso(epoch):
    return dt.datetime.fromtimestamp(int(epoch), dt.timezone.utc).isoformat().replace('+00:00', 'Z')

def main():
    lock = open('/run/lock/zcl-richlist.lock', 'w')
    fcntl.flock(lock, fcntl.LOCK_EX | fcntl.LOCK_NB)
    chain = rpc('getblockchaininfo')
    header = rpc('getblockheader', chain['bestblockhash'])
    if chain['blocks'] < 3126937 or float(chain['verificationprogress']) < 0.9999 or abs(dt.datetime.now(dt.timezone.utc).timestamp() - header['time']) > 7200:
        raise RuntimeError('Node is not current enough to publish')
    # This RPC flushes the coin cache, then computes exact on-disk UTXO commitments.
    stats = rpc('gettxoutsetinfo')
    header = rpc('getblockheader', stats['bestblock'])
    context = {'verification': 'own-node-snapshot', 'source': 'https://pool.zclthesis.com',
        'height': header['height'], 'hash': stats['bestblock'], 'commitment': stats['hash_chainstate_full'],
        'blockAt': iso(header['time']), 'totalZatoshis': units(stats['total_amount']),
        'utxoCount': stats['txouts'], 'bootstrapValidation': 'unknown'}
    provenance = Path('/etc/zcl-pool/node-provenance.json')
    if provenance.exists():
        mode = json.loads(provenance.read_text()).get('bootstrapValidation')
        if mode in ('anchored-fast-sync', 'validated-from-genesis'):
            context['bootstrapValidation'] = mode
    SNAPSHOTS.mkdir(exist_ok=True, mode=0o700)
    snapshot = SNAPSHOTS / ('richlist-' + uuid.uuid4().hex)
    # Atomic filesystem snapshot; never copy a live LevelDB directory.
    subprocess.run(['btrfs', 'subvolume', 'snapshot', '-r', '/var/lib/zcl-data/node', str(snapshot)], check=True)
    work = Path(tempfile.mkdtemp(prefix='zcl-richlist-', dir='/var/lib/zcl-data'))
    try:
        os.chmod(work, 0o700)
        context_path = work / 'context.json'
        context_path.write_text(json.dumps(context))
        verified = work / 'verified'
        # Exporter copies only chainstate to a private working directory for WAL
        # recovery and requires B, full commitment, UTXO count, and total to match.
        subprocess.run(['node', str(SITE / 'scripts/export-state.mjs'), str(snapshot), str(verified), str(context_path)], check=True, timeout=1500)
        artifact = work / 'zcl.json'
        subprocess.run(['node', str(SITE / 'scripts/build-richlist.mjs'), str(verified), str(artifact)], check=True, timeout=300)
        if rpc('getblockhash', context['height']) != context['hash']:
            raise RuntimeError('Snapshot block was reorganized; retained previous publication')
        PUBLIC.parent.mkdir(parents=True, exist_ok=True, mode=0o755)
        # Stage on the public filesystem and rename only the validated JSON.
        # Raw database snapshots, wallets, and keys never enter the public tree.
        temp_public = PUBLIC.with_suffix('.json.new')
        shutil.copyfile(artifact, temp_public)
        os.chmod(temp_public, 0o644)
        os.replace(temp_public, PUBLIC)
        print(json.dumps({'published': True, 'height': context['height'], 'hash': context['hash'], 'bytes': PUBLIC.stat().st_size}))
    finally:
        shutil.rmtree(work)
        subprocess.run(['btrfs', 'subvolume', 'delete', str(snapshot)], check=True)

if __name__ == '__main__':
    main()
