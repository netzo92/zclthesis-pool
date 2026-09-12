#!/usr/bin/env python3
"""Apply journaled SQL upgrades with both ZCL worker schedules stopped."""
import hashlib, os, pathlib, subprocess, sys

ROOT=pathlib.Path('/opt/zcl-pool/source')

def quote(value):
    return "'"+str(value).replace('\\','\\\\').replace("'","\\'")+"'"

def sql(statement):
    result=subprocess.run(['mariadb','--batch','--skip-column-names','yiimp_zcl'],
                          input=statement,text=True,capture_output=True,timeout=180)
    if result.returncode:
        raise RuntimeError('SQL failed; inspect the database before retrying')
    return result.stdout.strip()

def main():
    if os.geteuid() != 0:
        raise RuntimeError('Run as root')
    for unit in ['zcl-pool-worker.timer', 'zcl-pool-worker.service',
                 'zcl-pool-payout.timer', 'zcl-pool-payout.service']:
        if subprocess.run(['systemctl', 'is-active', '--quiet', unit]).returncode == 0:
            raise RuntimeError('Stop worker schedules before migration: '+unit)
    for migration in sorted((ROOT/'sql').glob('20*.sql')):
        source=migration.read_text()
        digest=hashlib.sha256(source.encode()).hexdigest()
        applied=sql('SELECT sha256 FROM zcl_schema_migrations WHERE name='+quote(migration.name))
        if applied:
            if applied != digest:
                raise RuntimeError('Previously applied migration changed: '+migration.name)
            continue
        # MariaDB DDL can auto-commit. On failure, inspect partial schema manually;
        # never mark a partial migration as complete or silently retry its DDL.
        sql(source)
        sql('INSERT INTO zcl_schema_migrations (name,sha256) VALUES ('+
            quote(migration.name)+','+quote(digest)+');')
        print('Applied '+migration.name, flush=True)

if __name__ == '__main__':
    try:
        main()
    except Exception as error:
        print('Migration stopped: '+str(error), file=sys.stderr)
        sys.exit(1)
