# ZCL Thesis Pool

CPU-only Zclassic pool infrastructure for the zclthesis.com community, based on
[tpfuemp/yiimp](https://github.com/tpfuemp/yiimp) at
`74988f929b65fb088d97444d718ecf869f5149d2`.

**Status: integration and payout validation in progress. Public mining is closed.**
This repository is public for review; publication does not mean the pool is ready
to accept miners or distribute rewards.

The target chain is Zclassic mainnet using Equihash 192,7 with `ZcashPoW`
personalization. The GCP host coordinates external miners and runs a full node,
share validation, accounting, payouts, and monitoring. It does not generate
hashpower, install a GPU, or enable daemon mining (`-gen=0`).

## Current work

- Harden Stratum inputs before decoding and native proof verification.
- Validate current Zclassic templates, accepted shares, and block submission.
- Add durable shielded payout handling. ZCL coinbase outputs must be shielded
  before paying miners; generic transparent `sendmany` is insufficient.
- Persist payout intents before RPC calls. Ambiguous submissions must be held for
  reconciliation rather than retried or refunded automatically.
- Keep admin, wallet RPC, database, and process-control endpoints private.

`deploy/zcl/gcp-bootstrap.sh` prepares an Ubuntu 24.04 CPU build host.
`deploy/zcl/install-node.sh` installs the pinned, checksum-verified official
v2.1.2-beta6 daemon and a restricted systemd service. Runtime wallet files,
credentials, backups, and database data belong outside this repository.

The launch fee, payout threshold, accounting window, and connection command will
be documented here after integration validation. No mainnet payout or public
mining endpoint is claimed by this preliminary configuration.

See [UPSTREAM-README.md](UPSTREAM-README.md) for the inherited application's
architecture and build instructions. Upstream examples are not our production
configuration. Preserve all upstream copyright and license notices.
