# ZCL production pool

The node runs with `gen=0`; external miners supply work. The launch fee is 0.8%,
with a 0.05 ZCL miner payout threshold. Operator remittance preserves all miner
liabilities and a 0.01 ZCL wallet reserve. See [the payout protocol](../../docs/ZCL-PAYOUTS.md)
for confirmation, shielding, reconciliation, rounding and network-fee behavior.

## Private runtime

Install MariaDB, PHP dependencies, the pinned node and native Stratum first.
Create a `zclpool` service account; add `www-data` to its group and give both
processes group write access to `yiimp2/runtime`. Restart PHP-FPM after changing
groups. Keep `/var/log/yiimp` writable only to `zclpool`.

Run `provision-pool.py --owner-address ADDRESS` as root only for a new database.
It loads schema and algorithm definitions from the pinned 2024 dump, omitting
historical coin/account data, then applies every dated SQL migration in order.
The migration journal stores each file's SHA-256. `--resume-empty-schema` requires
all financial tables empty and no application config; it is not an upgrade/reset.

The provisioner creates distinct limited database users, a bcrypt admin password
hash, a cookie-validation key, and pool transparent/Sapling addresses. Node RPC
authentication is synchronized privately from generated config or cookie
credentials. Keys and credentials never belong in logs or this repository.

Runtime configuration lives in `/etc/yiimp` (root-owned, group `zclpool`, 0750).
Application and Stratum configs are 0640; bootstrap credentials and address
metadata are 0600. Native wallet backups remain private on the node. The owner
recipient differs from the pool wallet; owner keys never belong on this VM.

Provisioning sets `mineraddress` to the pool hot transparent address. Coordinate
a node restart to activate it before mining: otherwise Zclassic supplies a wallet
reserve address in `getblocktemplate.coinbasetxn`, outside the payout coordinator's
configured shielding source. Verify the template output after restarting.

## Schedules

Install `zcl-stratum.service`, `zcl-rpc-cookie.*`, `zcl-pool-worker.*`,
`zcl-pool-payout.*` and `zcl-pool-status.*` units into `/etc/systemd/system`.
Enable Stratum and the four timers after provisioning. All work and payment flags
start false, and the single ZCL coin starts disabled.

| Schedule | Purpose |
| --- | --- |
| RPC authentication, 1 minute | Synchronize local credentials without logging them |
| ZCL worker, 15 seconds | Attribute blocks, update confirmations, clear eligible earnings |
| Worker scan, at most 1 minute | Recover transactions and refresh pool balances |
| ZCL payout, 1 minute | Durable shielding, miner payments and operator remittance |
| Pool status, 1 minute | Atomically publish allowlisted readiness flags and parameters |

Do not run the generic queue initializer, generic/legacy payout sender, exchange,
rental, purchase or automated share-pruning jobs. The scoped worker retains share
history during validation. `solo=0` rejects solo requests at native ingress.

Stratum initially binds `127.0.0.1:2192`. Initial/minimum difficulty are `0.01`;
values below approximately 1/256 exceed the existing Equihash target conversion's
range. RPC 8023, SQL 3306 and admin 8090 remain loopback-only. Access admin through
an authenticated IAP SSH tunnel. Its bootstrap password stays in root-only
`/etc/yiimp/admin-bootstrap.json` pending an authorized operator handoff.

## Upgrades and launch

Stop worker/payout timers and services before schema changes. Copy reviewed code
and run `apply-pool-migrations.py` as root. It refuses active schedules and changed
historical migrations. MariaDB DDL can auto-commit: inspect a partial failure
manually instead of blindly retrying. Run accounting/payout tests against an
isolated test database, never production. Native sanitizer fixtures cover invalid
input, known 192,7 proofs, distinct solutions and exact replay rejection.

Readiness requires a current mainnet tip, peers, high verification progress,
matching block/header height and hash, and both bootstrap/finalization holds
cleared. The imported-tip hold remains active during initial sync; a separate
`finalization_hold.held=false` does not cancel it. Missing hold fields fail closed.
Anchored bootstrap and forward validation do not mean this VM independently
replayed the chain from genesis.

After current-node and coinbase-template checks, enable the single ZCL coin and
`YIIMP_ZCL_WORKER_ENABLED`. Enable strict booleans `YIIMP_PAYMENTS_ENABLED`,
`YIIMP_ZCL_PAYOUTS_ENABLED` and `YIIMP_ZCL_OPERATOR_PAYMENTS_ENABLED` only with
reviewed payout migrations and the private address/reserve configuration. Keep
the generic sender disabled. Check the dedicated worker and payout status.

Public mining additionally requires root-owned `/etc/yiimp/launch-approved`.
Create it only after handshake, template, target, accepted-share and payout
readiness checks. The publisher requires this marker, active Stratum, a ready
node/worker and both payment gates; otherwise `/api/pool.json` has
`acceptingMiners:false`. Consumers must reject stale status files too. Opening
native TCP 2192 and changing its bind address require the same checks. A bounded
private owner test can use the bridge's explicit owner-address gate while public
mining stays closed.

The pool may find no mainnet block during validation. Regtest proves the tested
accounting and protected-Sapling payout paths; it does not establish that a
mainnet reward or payment occurred. Preserve this distinction in launch copy.
