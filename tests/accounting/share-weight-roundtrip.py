#!/usr/bin/env python3
"""Exercise the actual C SQL format against a disposable copy of the shares schema.

Run as a local MariaDB admin on the deployment host. Production is read only:
CREATE TABLE LIKE copies its schema, never its records. No node or wallet calls.
"""
import ctypes, json, pathlib, re, secrets, subprocess

root=pathlib.Path(__file__).resolve().parents[2]
source=(root/'stratum/share.cpp').read_text()
match=re.search(r'sprintf\(buffer\+strlen\(buffer\), "([^"]+)",\s*worker->userid',source)
if not match: raise RuntimeError('Actual native share INSERT format was not found')
format_string=match[1].encode()
libc=ctypes.CDLL(None)
libc.snprintf.argtypes=[ctypes.c_void_p,ctypes.c_size_t,ctypes.c_char_p]
libc.snprintf.restype=ctypes.c_int

def row(value,number):
    buffer=ctypes.create_string_buffer(2048)
    values=[ctypes.c_int(number),ctypes.c_int(1),ctypes.c_int(1),ctypes.c_int(1),
            ctypes.c_int(1),ctypes.c_int(1),ctypes.c_int(0),ctypes.c_double(value),
            ctypes.c_double(value*256),ctypes.c_int(1800000000),ctypes.c_char_p(b'equihash192'),
            ctypes.c_int(0),ctypes.c_int(0),ctypes.c_int(3248000)]
    count=libc.snprintf(buffer,len(buffer),format_string,*values)
    if not 0<count<len(buffer): raise RuntimeError('Native share INSERT formatting failed')
    return buffer.value.decode()

def sql(query):
    result=subprocess.run(['mariadb','--batch','--skip-column-names'],input=query,text=True,
                          capture_output=True,timeout=30)
    if result.returncode: raise RuntimeError('Isolated SQL regression failed')
    return result.stdout.strip()

database='zcl_accounting_test_weight_'+secrets.token_hex(8)
if not re.fullmatch(r'zcl_accounting_test_weight_[0-9a-f]{16}',database): raise RuntimeError('Unsafe test name')
columns=sql("SELECT COLUMN_NAME,DATA_TYPE FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA='yiimp_zcl' AND TABLE_NAME='shares' AND COLUMN_NAME IN ('difficulty','share_diff') ORDER BY COLUMN_NAME;")
if columns.splitlines()!=['difficulty\tdouble','share_diff\tdouble']:
    raise RuntimeError('Deployed share columns do not preserve native double precision')
weights=[0.01/256,0.004/256,0.01/256*2,0.01/256*17,1/256,1024/256]
rolling=0.0
for _ in range(73): rolling+=0.01/256
weights.append(rolling)
assert float(format(weights[0],'.6f'))!=weights[0], 'Fixture must detect old six-decimal rounding'
try:
    sql('CREATE DATABASE '+database+'; CREATE TABLE '+database+'.shares LIKE yiimp_zcl.shares;')
    sql('INSERT INTO '+database+'.shares (userid,workerid,coinid,jobid,pid,valid,extranonce1,difficulty,share_diff,time,algo,error,solo,blocknumber) VALUES '+','.join(row(v,i+1) for i,v in enumerate(weights))+';')
    data=sql('SELECT userid,difficulty,share_diff FROM '+database+'.shares ORDER BY userid;').splitlines()
    if len(data)!=len(weights): raise RuntimeError('Unexpected roundtrip row count')
    for index,line in enumerate(data):
        _,difficulty,best=line.split('\t')
        if float(difficulty)!=weights[index] or float(best)!=weights[index]*256:
            raise RuntimeError('Native share weight lost precision in SQL roundtrip')
    print(json.dumps({'passed':True,'cases':len(weights),'actualNativeFormat':True,
                      'deployedColumns':'DOUBLE','productionRowsReadOrWritten':0,
                      'lowDifficultyWeightPreserved':True}))
finally:
    sql('DROP DATABASE IF EXISTS '+database+';')
