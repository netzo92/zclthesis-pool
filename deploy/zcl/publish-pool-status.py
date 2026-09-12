#!/usr/bin/env python3
"""Publish only allowlisted pool readiness fields. Missing gates always close mining."""
import datetime, json, os, pathlib, subprocess

public=pathlib.Path('/var/lib/zcl-public/api/pool.json')
status={'schemaVersion':1,'asset':'ZCL','generatedAt':datetime.datetime.now(datetime.timezone.utc).isoformat().replace('+00:00','Z'),
    'acceptingMiners':False,'feePercent':0.8,'payoutMinimumZcl':'0.05','status':'validation',
    'algorithm':'Equihash 192,7','personalization':'ZcashPoW','stratumHost':'pool.zclthesis.com','stratumPort':2192}
try:
    result=subprocess.run(['runuser','-u','zclpool','--','php','/opt/zcl-pool/source/yiimp2/yii','zcl-worker/status'],capture_output=True,text=True,timeout=45)
    if result.returncode: raise RuntimeError('Readiness unavailable')
    flags=json.loads(result.stdout)
    stratum=subprocess.run(['systemctl','is-active','--quiet','zcl-stratum']).returncode==0
    status['nodeAndWorkerReady']=flags.get('ready') is True
    status['payoutsEnabled']=flags.get('payoutsEnabled') is True
    status['operatorPaymentsEnabled']=flags.get('operatorPaymentsEnabled') is True
    status['stratumRunning']=stratum
    # This root-owned marker is created only after explicit deployment validation.
    approved=pathlib.Path('/etc/yiimp/launch-approved').is_file()
    status['acceptingMiners']=approved and stratum and all(status[name] for name in ['nodeAndWorkerReady','payoutsEnabled','operatorPaymentsEnabled'])
    if status['acceptingMiners']: status['status']='open'
except Exception:
    status['status']='unavailable'
public.parent.mkdir(parents=True,exist_ok=True)
temporary=public.with_suffix('.json.tmp')
fd=os.open(temporary,os.O_WRONLY|os.O_CREAT|os.O_TRUNC,0o644)
with os.fdopen(fd,'w') as handle: json.dump(status,handle,separators=(',',':'));handle.write('\n')
os.chmod(temporary,0o644)
os.replace(temporary,public)
print(json.dumps({'published':True,'acceptingMiners':status['acceptingMiners'],'status':status['status']}))
