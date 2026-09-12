# Stratum input regression checks

On Ubuntu 24.04, install `build-essential`, then run:

```sh
bash stratum/tests/run-submit-validation.sh
```

The test compiles the actual production validators and JSON parser with AddressSanitizer and UndefinedBehaviorSanitizer. It checks malformed JSON types, embedded NULs, oversized fields, exact nonce/time lengths, canonical CompactSize solution prefixes, every implemented Equihash solution length (including ZCL's 400 bytes), and standard/ASICBoost/KawPow input formats. It starts no servers, wallets or miners and uses no network.

These checks establish input-boundary behavior. They do not establish share validity, daemon block acceptance, payout correctness, or general public-pool readiness. Those require integration checks against the current daemon and complete deployment review.
