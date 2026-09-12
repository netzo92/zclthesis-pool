#!/usr/bin/env python3
"""Synchronize local node config/cookie authentication into the private pool DB."""
import pathlib, re, subprocess, sys
from node_rpc_credentials import read_credentials
try:
    user,password=read_credentials()
    if not 1<=len(user)<=128 or not 1<=len(password)<=128 or any(ord(c)<32 for c in user+password):
        raise ValueError('Unexpected cookie format')
    # Hex SQL literals avoid interpolation of any credential punctuation.
    statement="UPDATE coins SET rpcuser=0x%s,rpcpasswd=0x%s WHERE id=1 AND symbol='ZCL';"%(user.encode().hex(),password.encode().hex())
    result=subprocess.run(['mariadb','yiimp_zcl'],input=statement,text=True,capture_output=True,timeout=10)
    if result.returncode: raise RuntimeError('Credential synchronization failed')
except Exception as error:
    print('ZCL loopback credential synchronization unavailable ('+type(error).__name__+')',file=sys.stderr)
    sys.exit(1)
