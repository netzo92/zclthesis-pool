#!/usr/bin/env python3
"""Provision a NEW empty ZCL pool. Runtime secrets stay in /etc/yiimp, never git."""
import argparse, gzip, hashlib, json, os, pathlib, pwd, grp, re, secrets, subprocess, sys
from node_rpc_credentials import read_credentials

ROOT = pathlib.Path('/opt/zcl-pool/source')
PRIVATE = pathlib.Path('/etc/yiimp')
DATABASE = 'yiimp_zcl'

def run(args, data=None):
    result = subprocess.run(args, input=data, text=True, capture_output=True, timeout=180)
    if result.returncode:
        # Commands may contain credentials in stdin. Do not echo their stderr/input.
        if '/opt/zclassic/zclassic-cli' in args:
            method=next((arg for arg in args[args.index('/opt/zclassic/zclassic-cli')+1:] if not arg.startswith('-')),'unknown')
            raise RuntimeError('Native wallet RPC failed: '+method)
        raise RuntimeError('Command failed: ' + args[0])
    return result.stdout.strip()

def sql(statement, database=False):
    return run(['mariadb','--batch','--skip-column-names'] + ([DATABASE] if database else []), statement)

def quote(value):
    return "'" + str(value).replace('\\','\\\\').replace("'","\\'") + "'"

def private_file(path, text, group_read=False):
    fd=os.open(path,os.O_WRONLY|os.O_CREAT|os.O_EXCL,0o640 if group_read else 0o600)
    with os.fdopen(fd,'w') as out: out.write(text)
    if group_read: os.chown(path,0,grp.getgrnam('zclpool').gr_gid)

def rpc(*args):
    return json.loads(run(['runuser','-u','zclnode','--','/opt/zclassic/zclassic-cli','-datadir=/var/lib/zclassic',*args]))

def main():
    parser=argparse.ArgumentParser()
    parser.add_argument('--owner-address',required=True)
    parser.add_argument('--resume-empty-schema',action='store_true',help='Resume only when financial tables are empty and no app config exists')
    args=parser.parse_args()
    if os.geteuid()!=0: raise RuntimeError('Run as root')
    if not re.fullmatch(r't1[1-9A-HJ-NP-Za-km-z]{33}',args.owner_address): raise RuntimeError('Expected transparent ZCL owner address')
    exists=DATABASE in sql('SHOW DATABASES').splitlines()
    if exists:
        if not args.resume_empty_schema: raise RuntimeError('Pool database exists; refusing to modify a live or partial database')
        for table in sql('SHOW TABLES',True).splitlines():
            if not re.fullmatch(r'[a-z_]+',table): raise RuntimeError('Unexpected table name')
            if table not in ['algos','migration','zcl_schema_migrations'] and sql('SELECT COUNT(*) FROM `'+table+'`',True)!='0':
                raise RuntimeError('Resume requires every financial table to be empty')
    if (PRIVATE/'serverconfig.php').exists(): raise RuntimeError('Pool configuration exists; refusing overwrite')
    PRIVATE.mkdir(mode=0o750,exist_ok=True)
    os.chown(PRIVATE,0,grp.getgrnam('zclpool').gr_gid)
    os.chmod(PRIVATE,0o750)
    if exists:
        credentials=json.loads((PRIVATE/'provision-secrets.json').read_text())
        if any(not re.fullmatch(r'[0-9a-f]{64}',str(v)) for v in credentials.values()): raise RuntimeError('Invalid saved provisioning credentials')
    else:
        credentials={key:secrets.token_hex(32) for key in ['app_db','stratum_db','admin_password','cookie_key','notify_password']}
        # Initial credentials remain root-only on this host for operator handoff.
        private_file(PRIVATE/'provision-secrets.json',json.dumps(credentials)+'\n')
        sql('CREATE DATABASE '+DATABASE+' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;')
    dump=gzip.open(ROOT/'sql/2024-03-06-complete_export.sql.gz','rt').read()
    tables=re.findall(r'CREATE TABLE IF NOT EXISTS `[a-z_]+`[\s\S]*?;',dump)
    seeds=re.findall(r'INSERT INTO `algos`[\s\S]*?;',dump)
    if len(tables)!=37 or len(seeds)!=36: raise RuntimeError('Unexpected base schema shape')
    # Preserve foreign-key enforcement: create referenced tables before dependants.
    pending={re.search(r'CREATE TABLE IF NOT EXISTS `([a-z_]+)`',s)[1]:s for s in tables}
    ordered=[]; created=set()
    while pending:
        ready=[name for name,statement in pending.items() if set(re.findall(r'REFERENCES `([a-z_]+)`',statement))<=created]
        if not ready: raise RuntimeError('Cyclic or missing schema dependency')
        for name in ready:
            ordered.append(pending.pop(name)); created.add(name)
    existing_tables=sql('SHOW TABLES',True).splitlines()
    if 'zcl_schema_migrations' not in existing_tables:
        if 'algos' in existing_tables and sql('SELECT COUNT(*) FROM algos',True)!='0': raise RuntimeError('Unjournaled algorithm seed data')
        sql('\n'.join(ordered+seeds),True)
        sql('CREATE TABLE zcl_schema_migrations (name VARCHAR(160) PRIMARY KEY, sha256 CHAR(64) NOT NULL, applied_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP);',True)
    for migration in sorted((ROOT/'sql').glob('20*.sql')):
        source=migration.read_text()
        digest=hashlib.sha256(source.encode()).hexdigest()
        applied=sql('SELECT sha256 FROM zcl_schema_migrations WHERE name='+quote(migration.name),True)
        if applied:
            if applied!=digest: raise RuntimeError('Previously applied migration changed: '+migration.name)
            continue
        sql(source,True)
        sql('INSERT INTO zcl_schema_migrations (name,sha256) VALUES ('+quote(migration.name)+','+quote(digest)+');',True)
        print('Applied '+migration.name,flush=True)
    if sql('SELECT COUNT(*) FROM coins;',True)!='0': raise RuntimeError('Unexpected seeded coin records')
    for username,secret_key in [('yiimp_app','app_db'),('yiimp_stratum','stratum_db')]:
        sql('CREATE USER IF NOT EXISTS '+quote(username)+"@'localhost' IDENTIFIED BY "+quote(credentials[secret_key])+'; GRANT SELECT,INSERT,UPDATE,DELETE ON '+DATABASE+'.* TO '+quote(username)+"@'localhost';")
    sql("UPDATE algos SET visible=0; UPDATE algos SET visible=1,port=2192,speedfactor=1,factor=1,powlimit_bits=13 WHERE name='equihash192';",True)
    # Wallet APIs generate keys on the node; no private keys are exported or logged.
    address_file=PRIVATE/'hot-wallet-addresses.json'
    if address_file.exists():
        saved=json.loads(address_file.read_text());taddress=saved['transparent'];zaddress=saved['sapling']
    else:
        taddress=run(['runuser','-u','zclnode','--','/opt/zclassic/zclassic-cli','-datadir=/var/lib/zclassic','getnewaddress'])
        zaddress=run(['runuser','-u','zclnode','--','/opt/zclassic/zclassic-cli','-datadir=/var/lib/zclassic','z_getnewaddress','sapling'])
        private_file(address_file,json.dumps({'transparent':taddress,'sapling':zaddress})+'\n')
    if not rpc('validateaddress',taddress).get('ismine') or not rpc('z_validateaddress',zaddress).get('ismine'): raise RuntimeError('Hot wallet ownership check failed')
    if taddress==args.owner_address: raise RuntimeError('Hot wallet must differ from owner wallet')
    node_config=pathlib.Path('/var/lib/zclassic/zclassic.conf')
    content=node_config.read_text()
    configured=re.findall(r'^mineraddress\s*=\s*(\S+)\s*$',content,re.MULTILINE)
    if configured and configured!=[taddress]: raise RuntimeError('Node has another mineraddress configured')
    if not configured:
        with node_config.open('a') as handle: handle.write('\nmineraddress='+taddress+'\n')
        print('Node mineraddress configured; a coordinated node restart is required before launch.',flush=True)
    run(['runuser','-u','zclnode','--','/opt/zclassic/zclassic-cli','-datadir=/var/lib/zclassic','backupwallet','poolinitial'+secrets.token_hex(8)])
    rpcuser,rpcpassword=read_credentials()
    fields={
        'id':1,'name':'Zclassic','symbol':'ZCL','algo':'equihash192','master_wallet':taddress,'wallet_zaddress':zaddress,
        'rpchost':'127.0.0.1','rpcport':8023,'rpcuser':rpcuser,'rpcpasswd':rpcpassword,'rpcencoding':'ZEC',
        'program':'zclassicd','installed':1,'enable':0,'auto_ready':0,'visible':1,'dontsell':1,'auto_exchange':0,
        'hasgetinfo':1,'hassubmitblock':1,'usesegwit':0,'usememorypool':0,'hasmasternodes':0,'auxpow':0,
        'personalization':'ZcashPoW','powlimit_bits':13,'block_time':75,'mature_blocks':100,'payout_min':'0.05',
        'reward':0,'price':1,'price2':1,'difficulty':1,'index_avg':1,'enable_rpcdebug':0,
    }
    sql('INSERT INTO coins ('+','.join('`'+k+'`' for k in fields)+') VALUES ('+','.join(quote(v) for v in fields.values())+');',True)
    password_hash=run(['php','-r','echo password_hash(stream_get_contents(STDIN), PASSWORD_BCRYPT);'],credentials['admin_password'])
    config={
        'YIIMP_DBHOST':'127.0.0.1','YIIMP_DBNAME':DATABASE,'YIIMP_DBUSER':'yiimp_app','YIIMP_DBPASSWORD':credentials['app_db'],
        'YIIMP_MEMCACHE_HOST':'','YIIMP_LOGS':'/var/log/yiimp','YIIMP_HTDOCS':str(ROOT/'yiimp2/web'),'YIIMP_BIN':str(ROOT/'bin'),
        'YIIMP_SITE_URL':'pool.zclthesis.com','YIIMP_STRATUM_URL':'pool.zclthesis.com','YIIMP_SITE_NAME':'ZCL Thesis Pool',
        'YIIMP_DEFAULT_ALGO':'equihash192','YIIMP_PRODUCTION':True,'YIIMP_DEBUG':False,
        'YIIMP_ADMIN_USER':'zcladmin','YIIMP_ADMIN_PASS':'','YIIMP_ADMIN_PASS_HASH':password_hash,
        'YIIMP_COOKIE_VALIDATION_KEY':credentials['cookie_key'],'YIIMP_ADMIN_IP':'127.0.0.1','YIIMP_ADMIN_WEBCONSOLE':False,'YIIMP_ADMIN_LOGIN':True,
        'YIIMP_FEES_MINING':0.8,'YIIMP_FEES_SOLO':0.8,'YIIMP_FEES_EXCHANGE':0,'YIIMP_PAYMENTS_FREQ':120,'YIIMP_PAYMENTS_MINI':0.05,
        'YIIMP_PAYMENTS_ENABLED':False,'YIIMP_ZCL_PAYOUTS_ENABLED':False,'YIIMP_ZCL_PAYOUT_MIN':'0.05',
        'YIIMP_ZCL_POOL_TADDRESS':taddress,'YIIMP_ZCL_POOL_ZADDRESS':zaddress,
        'YIIMP_ZCL_OPERATOR_TADDRESS':args.owner_address,'YIIMP_ZCL_OPERATOR_RESERVE':'0.01','YIIMP_ZCL_OPERATOR_PAYMENTS_ENABLED':False,
        'YIIMP_ZCL_WORKER_ENABLED':False,
        'YIIMP_ALLOW_EXCHANGE':False,'YIIMP_RENTAL':False,'YIIMP_USE_NICEHASH_API':False,
        'YIIMP_CREATE_NEW_COINS':False,'YIIMP_NOTIFY_NEW_COINS':False,'YIIMP_CLI_ALLOW_TXS':False,'YIIMP_CLI_ALLOW_DISTCLEAN':False,
        'YIIMP_PUBLIC_EXPLORER':False,'YIIMP_PUBLIC_BENCHMARK':False,'YIIMP_LAYOUT':'legacy','YIIMP_FIAT_ALTERNATIVE':'USD',
    }
    php='<?php\n// Private production configuration. Generated values must never enter git.\n'
    for key,value in config.items(): php+='define('+quote(key)+', '+(str(value).lower() if isinstance(value,(bool,int,float)) else quote(value))+');\n'
    php+="$configFixedPoolFees = ['equihash192' => 0.8];\n$configCustomPorts = ['equihash192' => 2192];\n$cold_wallet_table = [];\n"
    private_file(PRIVATE/'serverconfig.php',php,True)
    private_file(PRIVATE/'equihash192.conf',f'''[TCP]
server = pool.zclthesis.com
bind = 127.0.0.1
port = 2192
password = {credentials['notify_password']}
[SQL]
host = 127.0.0.1
port = 3306
database = {DATABASE}
username = yiimp_stratum
password = {credentials['stratum_db']}
[STRATUM]
algo = equihash192
difficulty = 0.01
diff_min = 0.01
diff_max = 1024
max_ttf = 50000
max_cons = 500
autoexchange = 0
renting = 0
solo = 0
logdir = /var/log/yiimp/
[WALLETS]
include = ZCL
[DEBUGLOG]
client = 0
hash = 0
socket = 0
rpc = 0
''',True)
    private_file(PRIVATE/'admin-bootstrap.json',json.dumps({'username':'zcladmin','password':credentials['admin_password'],'url':'http://127.0.0.1:8090/admin/login'})+'\n')
    private_file(PRIVATE/'deployment.json',json.dumps({'database':DATABASE,'coin_id':1,'rpc_auth':'local-auth-synchronized','public_mining':False,'payouts':False,'operator_withdrawals':False})+'\n')
    print('Fresh ZCL-only database and private configuration created; mining/payouts remain disabled.')

if __name__=='__main__':
    try: main()
    except Exception as error:
        print('Provisioning stopped: '+str(error),file=sys.stderr)
        sys.exit(1)
