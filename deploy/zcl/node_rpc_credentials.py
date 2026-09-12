"""Read local node authentication without exposing it in commands or logs."""
from pathlib import Path

def read_credentials():
    data=Path('/var/lib/zclassic')
    config={}
    for line in (data/'zclassic.conf').read_text().splitlines():
        if not line.strip() or line.lstrip().startswith('#') or '=' not in line: continue
        key,value=line.split('=',1);config[key.strip()]=value.strip()
    cookie=Path(config.get('rpccookiefile','.cookie'))
    if not cookie.is_absolute(): cookie=data/cookie
    if cookie.exists():
        return cookie.read_text().strip().split(':',1)
    if config.get('rpcuser') and config.get('rpcpassword'):
        return config['rpcuser'],config['rpcpassword']
    raise RuntimeError('No supported local RPC authentication found')
