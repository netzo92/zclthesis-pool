#!/usr/bin/env python3
"""Publish an allowlisted, read-only view of the local node; never expose RPC."""
import datetime as dt
import json
import math
import os
from pathlib import Path
import subprocess
import tempfile

PUBLIC = Path('/var/lib/zcl-public/api')
CLI = ['/usr/sbin/runuser', '-u', 'zclnode', '--', '/opt/zclassic/zclassic-cli', '-datadir=/var/lib/zclassic', '-rpcclienttimeout=15']

def rpc(method, *args):
    reply = subprocess.run(CLI + [method, *map(str, args)], text=True, capture_output=True, timeout=20, check=True)
    return json.loads(reply.stdout)

def timestamp(epoch):
    return dt.datetime.fromtimestamp(epoch, dt.timezone.utc).isoformat().replace('+00:00', 'Z')

def atomic_json(path, value):
    path.parent.mkdir(parents=True, exist_ok=True, mode=0o755)
    fd, name = tempfile.mkstemp(prefix='.new-', dir=path.parent)
    try:
        with os.fdopen(fd, 'w') as stream:
            json.dump(value, stream, separators=(',', ':'), allow_nan=False)
            stream.flush()
            os.fsync(stream.fileno())
        os.chmod(name, 0o644)
        os.replace(name, path)
    finally:
        if os.path.exists(name):
            os.unlink(name)

def main():
    now = dt.datetime.now(dt.timezone.utc)
    try:
        chain = rpc('getblockchaininfo')
        network = rpc('getnetworkinfo')
        mining = rpc('getmininginfo')
        header = rpc('getblockheader', chain['bestblockhash'])
        progress = float(chain['verificationprogress'])
        height = chain['blocks']
        if not isinstance(height, int) or height < 0 or header['height'] != height:
            raise ValueError('Inconsistent node height')
        if not math.isfinite(progress) or not 0 <= progress <= 1.000001:
            raise ValueError('Invalid synchronization progress')
        age = now.timestamp() - header['time']
        synced = not chain.get('initialblockdownload', False) and progress >= 0.9999 and -300 <= age <= 1800 and network['connections'] > 0
        # This deployment initializes from the release-pinned bootstrap and then
        # validates forward. Progress=1 is not evidence of a genesis replay.
        result = {'schemaVersion': 1, 'asset': 'ZCL', 'generatedAt': timestamp(now.timestamp()),
            'chain': {'height': height, 'hash': chain['bestblockhash'], 'blockAt': timestamp(header['time'])},
            'node': {'synced': synced, 'connections': network['connections'], 'verificationProgress': progress,
                'softwareVersion': network.get('subversion', str(network.get('version', 'unknown'))),
                'bootstrapValidation': 'unknown'},
            'mining': {'difficulty': mining.get('difficulty'), 'networkSolps': mining.get('networksolps')},
            'source': 'https://pool.zclthesis.com'}
        provenance = Path('/etc/zcl-pool/node-provenance.json')
        if provenance.exists():
            mode = json.loads(provenance.read_text()).get('bootstrapValidation')
            if mode in ('anchored-fast-sync', 'validated-from-genesis'):
                result['node']['bootstrapValidation'] = mode
        atomic_json(PUBLIC / 'node.json', result)
        print(json.dumps({'published': True, 'height': height, 'synced': synced}))
    except (subprocess.SubprocessError, ValueError, KeyError, OSError, TypeError):
        # Keep a previously validated observation. The consumer can mark it stale.
        # Do not copy arbitrary daemon stderr or configuration into public JSON.
        if not (PUBLIC / 'node.json').exists():
            atomic_json(PUBLIC / 'node.json', {'schemaVersion': 1, 'asset': 'ZCL',
                'generatedAt': timestamp(now.timestamp()), 'node': {'synced': False}, 'status': 'synchronizing'})
        print(json.dumps({'published': False, 'reason': 'Node not ready; retained prior observation'}))
        raise SystemExit(1)

if __name__ == '__main__':
    main()
