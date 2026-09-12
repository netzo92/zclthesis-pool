#!/usr/bin/env bash
# Synthetic protected-coinbase/Sapling wallet test. Never connects to mainnet.
set -euo pipefail
umask 077
: "${ZCLD:?Set ZCLD to the verified zclassicd v2.1.2-beta6 executable}"
: "${ZCLCLI:?Set ZCLCLI to its matching zclassic-cli executable}"
: "${ZCL_PARAMS_DIR:?Set ZCL_PARAMS_DIR to the existing verified proving-parameter directory}"
command -v php >/dev/null
command -v python3 >/dev/null
python3 - "$ZCL_PARAMS_DIR" <<'PYTEST'
import os,sys
from pathlib import Path
expected=Path.home()/'.zcash-params'
if expected.resolve()!=Path(sys.argv[1]).resolve():
    sys.exit('Run as the node service user (sudo -H -u zclnode): this release reads proving parameters from ~/.zcash-params, not -paramsdir.')
for name in ['sapling-spend.params','sapling-output.params','sprout-groth16.params','sprout-proving.key','sprout-verifying.key']:
    if not (expected/name).is_file():
        sys.exit('Missing existing proving parameter: '+name)
PYTEST
zcl_test_dir=$(mktemp -d /tmp/zcl-payout-regtest.XXXXXXXX)
export ZCL_TEST_DATADIR="$zcl_test_dir"
export ZCLCLI
export ZCL_TEST_DSN="sqlite:$zcl_test_dir/ledger.sqlite"
export ZCL_TEST_RPC_PORT
ZCL_TEST_RPC_PORT=$(python3 - <<'PY'
import socket
with socket.socket() as s:
    s.bind(('127.0.0.1',0))
    print(s.getsockname()[1])
PY
)
python3 - <<'PY'
import os,secrets
from pathlib import Path
p=Path(os.environ['ZCL_TEST_DATADIR'])/'zclassic.conf'
p.write_text('rpcuser=regtest-only\nrpcpassword='+secrets.token_hex(32)+'\nrpcport='+os.environ['ZCL_TEST_RPC_PORT']+'\n')
p.chmod(0o600)
PY
cleanup() {
  "$ZCLCLI" -regtest -datadir="$zcl_test_dir" stop >/dev/null 2>&1 || true
  printf 'Regtest evidence directory: %s\n' "$zcl_test_dir"
}
trap cleanup EXIT
"$ZCLD" -daemon -regtest -regtestprotectcoinbase -datadir="$zcl_test_dir" \
  -bootstrap=0 -connect=0 -listen=0 -dnsseed=0 -discover=0 \
  -rpcbind=127.0.0.1 -rpcallowip=127.0.0.1 \
  -nuparams=5ba81b19:1 -nuparams=76b809bb:1
for zcl_attempt in $(seq 1 60); do
  if "$ZCLCLI" -regtest -datadir="$zcl_test_dir" getblockchaininfo >/dev/null 2>&1; then
    break
  fi
  sleep 1
done
php "$(dirname "$0")/regtest.php"
