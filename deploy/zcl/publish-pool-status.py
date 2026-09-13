#!/usr/bin/env python3
"""Publish allowlisted readiness and read-only pool reward totals."""
import datetime
import json
import os
import pathlib
import re
import subprocess


def unavailable_mined(now):
    return {
        'schemaVersion': 1, 'asset': 'ZCL', 'generatedAt': now,
        'status': 'unavailable', 'coverageStartedAt': None,
        'coverageBasis': 'retained-pool-ledger', 'windowBasis': 'pool-recorded-time',
        'rewardBasis': 'gross-coinbase-including-fees',
        'allTime': None, 'last24h': None, 'lastHour': None,
        'unknownBlocks': None, 'excludedOrphans': None, 'accountingHeld': None,
    }


def sanitized_mined(data, now):
    """Never publish arbitrary CLI data or replace an unavailable result with zeros."""
    result = unavailable_mined(now)
    if not isinstance(data, dict) or data.get('schemaVersion') != 1 or data.get('asset') != 'ZCL':
        raise ValueError('Invalid mined summary')
    if data.get('status') == 'unavailable':
        return result
    if data.get('status') not in ('ok', 'partial'):
        raise ValueError('Invalid mined status')
    for field in ('coverageBasis', 'windowBasis', 'rewardBasis'):
        if data.get(field) != result[field]:
            raise ValueError('Unknown measurement basis')
    generated = datetime.datetime.fromisoformat(data['generatedAt'].replace('Z', '+00:00'))
    current = datetime.datetime.fromisoformat(now.replace('Z', '+00:00'))
    if generated.tzinfo is None or not -60 <= (current - generated).total_seconds() <= 180:
        raise ValueError('Mined snapshot timestamp outside freshness limit')
    result['generatedAt'] = data['generatedAt']
    result['status'] = data['status']
    for key in ('unknownBlocks', 'excludedOrphans'):
        if type(data.get(key)) is not int or not 0 <= data[key] <= 1000000000:
            raise ValueError('Invalid block count')
        result[key] = data[key]
    if type(data.get('accountingHeld')) is not bool:
        raise ValueError('Invalid accounting state')
    result['accountingHeld'] = data['accountingHeld']
    if result['status'] == 'ok' and (result['unknownBlocks'] or result['accountingHeld']):
        raise ValueError('Incomplete result cannot be ok')
    for key in ('allTime', 'last24h', 'lastHour'):
        source = data.get(key)
        if not isinstance(source, dict):
            raise ValueError('Missing time window')
        window = {}
        for count in ('blocks', 'matureBlocks', 'immatureBlocks'):
            if type(source.get(count)) is not int or not 0 <= source[count] <= 10000:
                raise ValueError('Invalid window block count')
            window[count] = source[count]
        for amount in ('rewardZat', 'matureRewardZat', 'immatureRewardZat'):
            if not isinstance(source.get(amount), str) or not re.fullmatch(r'0|[1-9][0-9]{0,23}', source[amount]):
                raise ValueError('Invalid integer reward')
            window[amount] = source[amount]
        if (window['blocks'] != window['matureBlocks'] + window['immatureBlocks']
                or int(window['rewardZat']) != int(window['matureRewardZat']) + int(window['immatureRewardZat'])):
            raise ValueError('Window components disagree')
        for count, amount in (('blocks', 'rewardZat'), ('matureBlocks', 'matureRewardZat'),
                              ('immatureBlocks', 'immatureRewardZat')):
            if window[count] == 0 and window[amount] != '0':
                raise ValueError('Reward without a corresponding block')
        result[key] = window
    for smaller, larger in (('lastHour', 'last24h'), ('last24h', 'allTime')):
        for field in ('blocks', 'matureBlocks', 'immatureBlocks', 'rewardZat', 'matureRewardZat', 'immatureRewardZat'):
            if int(result[smaller][field]) > int(result[larger][field]):
                raise ValueError('Rolling windows disagree')
    return result


def cli(action):
    result = subprocess.run(
        ['runuser', '-u', 'zclpool', '--', 'php', '/opt/zcl-pool/source/yiimp2/yii', action],
        capture_output=True, text=True, timeout=30 if action == 'zcl-worker/mined' else 45)
    if result.returncode:
        raise RuntimeError('Pool summary unavailable')
    return json.loads(result.stdout)


def main():
    public = pathlib.Path('/var/lib/zcl-public/api/pool.json')
    now = datetime.datetime.now(datetime.timezone.utc).isoformat().replace('+00:00', 'Z')
    status = {'schemaVersion': 1, 'asset': 'ZCL', 'generatedAt': now,
        'acceptingMiners': False, 'feePercent': 0.8, 'payoutMinimumZcl': '0.05', 'status': 'validation',
        'algorithm': 'Equihash 192,7', 'personalization': 'ZcashPoW', 'stratumHost': 'pool.zclthesis.com', 'stratumPort': 2192}
    try:
        flags = cli('zcl-worker/status')
        stratum = subprocess.run(['systemctl', 'is-active', '--quiet', 'zcl-stratum']).returncode == 0
        status['nodeAndWorkerReady'] = flags.get('ready') is True
        status['payoutsEnabled'] = flags.get('payoutsEnabled') is True
        status['operatorPaymentsEnabled'] = flags.get('operatorPaymentsEnabled') is True
        status['stratumRunning'] = stratum
        # This root-owned marker is created only after explicit deployment validation.
        approved = pathlib.Path('/etc/yiimp/launch-approved').is_file()
        status['acceptingMiners'] = approved and stratum and all(status[name] for name in [
            'nodeAndWorkerReady', 'payoutsEnabled', 'operatorPaymentsEnabled'])
        if status['acceptingMiners']:
            status['status'] = 'open'
    except Exception:
        status['status'] = 'unavailable'
    try:
        status['mined'] = sanitized_mined(cli('zcl-worker/mined'), now)
    except Exception:
        status['mined'] = unavailable_mined(now)
    public.parent.mkdir(parents=True, exist_ok=True)
    temporary = public.with_suffix('.json.tmp')
    fd = os.open(temporary, os.O_WRONLY | os.O_CREAT | os.O_TRUNC, 0o644)
    with os.fdopen(fd, 'w') as handle:
        json.dump(status, handle, separators=(',', ':'))
        handle.write('\n')
    os.chmod(temporary, 0o644)
    os.replace(temporary, public)
    print(json.dumps({'published': True, 'acceptingMiners': status['acceptingMiners'],
        'status': status['status'], 'minedStatus': status['mined']['status']}))


if __name__ == '__main__':
    main()
