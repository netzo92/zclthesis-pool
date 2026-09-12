#!/usr/bin/env python3
"""Check private launch readiness; explicitly enable only the scoped pool services."""
import argparse, configparser, json, os, pathlib, re, subprocess

ROOT=pathlib.Path('/opt/zcl-pool/source')
PRIVATE=pathlib.Path('/etc/yiimp')

def run(command, data=None, timeout=60):
    result=subprocess.run(command,input=data,text=True,capture_output=True,timeout=timeout)
    if result.returncode:
        raise RuntimeError('Local readiness command failed: '+command[0])
    return result.stdout.strip()

def rpc(method,*args):
    return json.loads(run(['runuser','-u','zclnode','--','/opt/zclassic/zclassic-cli',
                          '-datadir=/var/lib/zclassic',method,*args]))

def main():
    parser=argparse.ArgumentParser()
    parser.add_argument('--enable-private',action='store_true')
    args=parser.parse_args()
    if os.geteuid()!=0: raise RuntimeError('Run as root')
    if (PRIVATE/'launch-approved').exists(): raise RuntimeError('Public launch marker must remain absent')
    chain=rpc('getblockchaininfo')
    header=rpc('getblockheader',chain['bestblockhash'])
    peers=rpc('getconnectioncount')
    guard="require '"+str(ROOT)+"/yiimp2/services/ZclNodeReadiness.php'; $x=json_decode(stream_get_contents(STDIN),true); echo json_encode(app\\services\\ZclNodeReadiness::ready($x[0],$x[1],$x[2],time()));"
    if run(['php','-r',guard],json.dumps([chain,header,peers]))!='true':
        if args.enable_private:
            raise RuntimeError('Node is syncing, held, disconnected or stale; private activation refused')
        print(json.dumps({'ready':False,'reason':'node-sync-or-hold','height':chain['blocks']}));return
    addresses=json.loads((PRIVATE/'hot-wallet-addresses.json').read_text())
    taddr=addresses['transparent'];zaddr=addresses['sapling']
    if rpc('validateaddress',taddr).get('ismine') is not True:
        raise RuntimeError('Hot transparent address is not owned')
    if rpc('z_validateaddress',zaddr).get('ismine') is not True:
        raise RuntimeError('Hot Sapling address is not owned')
    template=rpc('getblocktemplate')
    if template['height']!=chain['blocks']+1 or template['previousblockhash']!=chain['bestblockhash']:
        raise RuntimeError('Tip changed while validating; retry the read-only check')
    transaction=rpc('decoderawtransaction',template['coinbasetxn']['data'])
    outputs=[output for output in transaction['vout'] if output['value']>0]
    if len(outputs)!=1 or outputs[0]['scriptPubKey'].get('addresses')!=[taddr]:
        raise RuntimeError('Coinbase template does not pay only the configured shielding source')
    fields=['YIIMP_FEES_MINING','YIIMP_ZCL_PAYOUT_MIN','YIIMP_ZCL_POOL_TADDRESS',
            'YIIMP_ZCL_POOL_ZADDRESS','YIIMP_ZCL_OPERATOR_TADDRESS','YIIMP_ZCL_OPERATOR_RESERVE']
    query="require '/etc/yiimp/serverconfig.php'; $a=[]; foreach (json_decode(stream_get_contents(STDIN),true) as $n) {$a[$n]=constant($n);} echo json_encode($a);"
    config=json.loads(run(['php','-r',query],json.dumps(fields)))
    if config['YIIMP_FEES_MINING']!=0.8 or config['YIIMP_ZCL_PAYOUT_MIN']!='0.05' or config['YIIMP_ZCL_OPERATOR_RESERVE']!='0.01':
        raise RuntimeError('Fee, threshold or reserve mismatch')
    if config['YIIMP_ZCL_POOL_TADDRESS']!=taddr or config['YIIMP_ZCL_POOL_ZADDRESS']!=zaddr:
        raise RuntimeError('Configured pool addresses disagree')
    owner=config['YIIMP_ZCL_OPERATOR_TADDRESS']
    owner_info=rpc('validateaddress',owner)
    if owner==taddr or owner_info.get('isvalid') is not True or owner_info.get('ismine') is True:
        raise RuntimeError('Owner recipient must be valid and separate from the hot wallet')
    native=configparser.ConfigParser();native.read(PRIVATE/'equihash192.conf')
    if native['TCP']['bind']!='127.0.0.1' or native['STRATUM']['solo']!='0':
        raise RuntimeError('Private listener or solo gate mismatch')
    if float(native['STRATUM']['difficulty'])!=0.01 or float(native['STRATUM']['diff_min'])!=0.01:
        raise RuntimeError('Initial/minimum share difficulty mismatch')
    migrations=run(['mariadb','--batch','--skip-column-names','yiimp_zcl'],
                  "SELECT COUNT(*) FROM zcl_schema_migrations WHERE name IN ('2026-09-11-zcl-reward-ledger.sql','2026-09-12-zcl-payout-ledger.sql','2026-09-13-zcl-operator-fees.sql');")
    if migrations!='3': raise RuntimeError('Required financial migration missing')
    if args.enable_private:
        units=['zcl-pool-worker.timer','zcl-pool-payout.timer','zcl-pool-worker.service','zcl-pool-payout.service']
        run(['systemctl','stop',*units])
        path=PRIVATE/'serverconfig.php';content=path.read_text()
        for name in ['YIIMP_ZCL_WORKER_ENABLED','YIIMP_PAYMENTS_ENABLED','YIIMP_ZCL_PAYOUTS_ENABLED','YIIMP_ZCL_OPERATOR_PAYMENTS_ENABLED']:
            content,count=re.subn(r"define\('"+name+r"', (false|true)\);","define('"+name+"', true);",content)
            if count!=1: raise RuntimeError('Unexpected private flag declaration')
        # Both schedules remain stopped if any check/write fails.
        run(['mariadb','yiimp_zcl'],"UPDATE coins SET enable=1,auto_ready=1 WHERE id=1 AND symbol='ZCL';")
        temp=PRIVATE/'serverconfig.php.activation'
        fd=os.open(temp,os.O_WRONLY|os.O_CREAT|os.O_EXCL,0o640)
        with os.fdopen(fd,'w') as handle:
            handle.write(content);handle.flush();os.fsync(handle.fileno())
        os.chown(temp,0,path.stat().st_gid);os.replace(temp,path)
        run(['systemctl','restart','zcl-stratum'])
        run(['systemctl','start','zcl-pool-worker.timer','zcl-pool-payout.timer','zcl-pool-status.service'])
    print(json.dumps({'ready':True,'height':chain['blocks'],'coinbaseSourceVerified':True,
                     'hotAddressesOwned':True,'feePercent':0.8,'payoutMinimumZcl':'0.05',
                     'privateEnabled':args.enable_private,'publicMining':False}))

if __name__=='__main__':
    main()
