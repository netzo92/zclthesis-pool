# Stratum input regression checks

On Ubuntu 24.04, install `build-essential`, then run:

```sh
bash stratum/tests/run-submit-validation.sh
```

The test compiles the actual production validators and JSON parser with AddressSanitizer and UndefinedBehaviorSanitizer. It checks malformed JSON types, embedded NULs, oversized fields, exact nonce/time lengths, canonical CompactSize solution prefixes, every implemented Equihash solution length (including ZCL's 400 bytes), and standard/ASICBoost/KawPow input formats. It starts no servers, wallets or miners and uses no network.

These checks establish input-boundary behavior. They do not establish share validity, daemon block acceptance, payout correctness, or general public-pool readiness. Those require integration checks against the current daemon and complete deployment review.

With `default-libmysqlclient-dev` and `libsodium-dev` installed, run
`bash stratum/tests/run-job-scheduling.sh` for the direct ZCL scheduling regression.
It links production selection, assignment, broadcast, list, coin eligibility,
and history functions under ASan/UBSan. An inactive list head and reordered active
jobs exercise newest-job selection. Initial and subsequent notifications must
work with both exchange switches disabled, while wrong payout coins and `mc`/`nc`
restrictions remain enforced, including the recovery assignment pass. Socket
output and external dependencies are intercepted; the test has no network,
database, daemon, GPU work or shares.

`bash stratum/tests/run-compact-target.sh` additionally links the actual private
block-dispatch function and target helpers. Independent Python integers generate
full 256-bit boundaries, including `T-1`, `T` and `T+1` with equal high 64 bits,
small exponents and invalid/negative/overflow compact values. The historical
height 3,192,878 header in `fixtures/zcl-webgpu1927.txt` is verified with the native
192,7 verifier and SHA256d, then reaches an intercepted block-submission call.
Above-target and invalid-target hashes must never reach that call. The synthetic
transaction body is not a valid block; this tests candidate routing and header
validity, not daemon acceptance. All RPC/database effects are replaced, with
ASan/UBSan enabled and no hash search, mining or live submission.
