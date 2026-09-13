# ZCL Thesis Pool

CPU-only Zclassic pool infrastructure for the zclthesis.com community, based on
[tpfuemp/yiimp](https://github.com/tpfuemp/yiimp) at
`74988f929b65fb088d97444d718ecf869f5149d2`.

**Public mining opened on September 12, 2026.** Check the current
[pool status](https://pool.zclthesis.com/) before connecting: admission depends on
fresh node and accounting readiness. Connect native miners to
`stratum+tcp://pool.zclthesis.com:2192`, using your transparent ZCL payout address
as the username and `c=ZCL` as the password. The
[browser GPU miner](https://pool.zclthesis.com/mine/) requires an explicit start.
The [production runbook](deploy/zcl/PRODUCTION.md) documents private configuration,
scoped schedules and launch gates; [GCP operations](docs/GCP-OPERATIONS.md) records
capacity, snapshot and launch validation.

The target chain is Zclassic mainnet using Equihash 192,7 with `ZcashPoW`
personalization. The GCP host coordinates external miners and runs a full node,
share validation, accounting, payouts, and monitoring. It does not generate
hashpower, install a GPU, or enable daemon mining (`-gen=0`).

## Production behavior

- Validate bounded Stratum inputs and native Equihash proofs before accepting work.
- Attribute accepted work to each miner's public payout address.
- Shield matured ZCL coinbase rewards before paying miners, with confirmation
  checks at each stage.
- Persist payout intents before RPC calls and hold ambiguous submissions for
  reconciliation.
- Keep admin, wallet RPC, database, and process-control endpoints private.

`deploy/zcl/gcp-bootstrap.sh` prepares an Ubuntu 24.04 CPU build host.
`deploy/zcl/install-node.sh` installs the pinned, checksum-verified official
v2.1.2-beta6 daemon and a restricted systemd service. Runtime wallet files,
credentials, backups, and database data belong outside this repository.
The live node additionally uses the reviewed, read-only
[corroboration diagnostic patch](deploy/zcl/patches/README.md), built against
that pinned upstream release; its consensus and finalization rules are unchanged.

The selected launch fee is **0.8%** (80 basis points); miners retain 99.2% of
allocated rewards. This is 20% below a 1% fee, using zpool’s published
Equihash 192,7 fee as a named benchmark, not a universal competitor claim.
The miner payout threshold is **0.05 ZCL**. Rewards are proportional to accepted
work in each block round; the operator reserve covers transaction fees. The final
two-minute laptop GPU test accepted 7 shares with zero rejects across three jobs.
Together with an earlier successful test, 21 accepted shares were credited to the
operator's miner account, and the configured fee recipient was verified. No
mainnet block was found and no mainnet payment occurred. Protected-Sapling payout
and reconciliation paths were tested separately
on regtest; see [payout accounting](docs/ZCL-PAYOUTS.md).

See [UPSTREAM-README.md](UPSTREAM-README.md) for the inherited application's
architecture and build instructions. Upstream examples are not our production
configuration. Preserve all upstream copyright and license notices.

## Shared node and public statistics

The node data directory lives in a private Btrfs subvolume. Every two hours,
`publish-richlist.py` flushes node UTXO state, takes an atomic filesystem snapshot,
recovers only its copied chainstate, verifies the best-block hash, full commitment,
exact base-unit total and output count, and publishes only aggregate JSON. It
rechecks that the snapshot block is canonical before replacing the public file.
Failures retain the last verified file. Raw snapshots contain sensitive local
wallet state and must never be uploaded or served.

`record-prices.py` records the public NonKYC ZCL/USDT ticker every minute in a
durable SQLite archive on the existing VM, independent of website visits. It
preserves exact prices, collection/trade timestamps, failures, and daily backups.
A bounded seven-day JSON export and a separate verified launch-period trade
backfill support future charts; see [price history](data/price-history/README.md)
and the [production runbook](deploy/zcl/PRODUCTION.md#durable-nonkyc-price-history).

`publish-node-stats.py` publishes allowlisted read-only fields once a minute.
The same public dashboard shows ZCL mined by this pool across its retained history,
the rolling last 24 hours, and the rolling last hour. The existing pool-status
publisher adds exact gross block rewards with mature/immature breakdowns after
matching reward journals and current canonical headers. Unknown observations
remain partial or unavailable. See [pool-mined totals](docs/GCP-OPERATIONS.md#pool-mined-zcl-totals)
for units, coverage and verification limits. Both English and Spanish pages poll
the existing status feed every 30 seconds while visible.
Caddy serves only explicitly listed static routes and sanitized JSON. Public wallet
RPC, database, supervisor and admin console remain private. Public mining uses
TCP 2192 or the restricted HTTPS WebSocket bridge. The private admin interface is
accessed through an IAP SSH tunnel.
