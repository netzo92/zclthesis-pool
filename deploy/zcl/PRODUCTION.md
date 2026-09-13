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

RPC authentication, worker and payout timers first run one second after timer
activation, including when activation happens long after boot. Each next run is
scheduled after the preceding service finishes (15 seconds for the worker, one
minute for authentication and payouts), so slow calls do not overlap executions.
After installing or changing a unit, run `systemctl daemon-reload` and restart
only its corresponding timer. Verify successful first and repeated service
executions and a finite next trigger; an active but elapsed timer does not prove
that its job ran. A transient missing next trigger while a oneshot is running is
normal; recheck after it finishes.

Restarting `zcl-rpc-cookie.timer` schedules a real credential synchronization one
second later. Its service reads the local node cookie when present, otherwise
the configured RPC username/password, and sends one SQL update over stdin to the
local `yiimp_zcl` database: only `coins.rpcuser` and `coins.rpcpasswd` for
`id=1 AND symbol='ZCL'`. It does not change balances, payment gates, wallet keys,
Stratum configuration or node configuration, and it calls no node RPC method.
Repeating it with unchanged source credentials leaves those values unchanged.
A rotated daemon cookie becomes available to database consumers after the next
successful synchronization. Source-read or validation failures do not call SQL.
A failed or timed-out SQL call is reported as a failure and the assignment is
safe to repeat, even if an acknowledgment was lost after it committed.

The authentication service requires MariaDB and the node, so activating it can
start those dependencies during maintenance. Verify that both are intended to be
running before restarting its timer; keep it stopped during an intentional node
pause. `tests/public-data/README.md` documents isolated credential-synchronization
tests that use only synthetic credentials and mocked SQL execution.

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
matching block/header height and hash, and a cleared bootstrap hold. If
`getblockchaininfo.live_corroboration` is present, the worker, private activation,
payout coordinator and richlist publisher require its version 1 diagnostic to
report boolean `ready:true` for that exact tip hash and integer height. Its integer
`requiredDepth` must be positive, `candidateHeight` must equal
`tipHeight-requiredDepth` and be nonnegative, and integer `requiredPeers` must be
at least two on mainnet. `reason` must be a string; it is descriptive rather than
a readiness code. Malformed fields, missing fields, unknown schema versions and
`ready:false` close readiness even when the cached finalization hold is clear.

A valid live diagnostic can supersede a stale cached `finalization_hold.held=true`
after existing peers corroborate the current tip. This diagnostic reads the
daemon's current guards; it does not change consensus or finalized state. When
the live field is absent, including after rollback to the official daemon,
readiness still requires explicit `finalization_hold.held=false`. The imported-tip
`bootstrap_validation.tip_hold` must independently be explicit false on mainnet;
neither live corroboration nor a cleared cached finalization hold cancels it.
The existing isolated test-network payout fallback for older daemons is retained.
Node-statistics `synced` remains a separate observation with its existing meaning.
Anchored bootstrap and forward validation do not mean this VM independently
replayed the chain from genesis.

Regression checks: `php tests/accounting/readiness.php`,
`python3 tests/public-data/readiness.py`, and `bash tests/payout/run.sh` (the payout
suite defaults to a disposable SQLite database). These check live/cached
compatibility, schema and tip mismatches, unchanged freshness/bootstrap gates,
and preservation of payout reservations while readiness is held.

After current-node and coinbase-template checks, enable the single ZCL coin and
`YIIMP_ZCL_WORKER_ENABLED`. Enable strict booleans `YIIMP_PAYMENTS_ENABLED`,
`YIIMP_ZCL_PAYOUTS_ENABLED` and `YIIMP_ZCL_OPERATOR_PAYMENTS_ENABLED` only with
reviewed payout migrations and the private address/reserve configuration. Keep
the generic sender disabled. Check the dedicated worker and payout status.

`activate-private-pool.py` performs these read-only checks by default, including
decoding the template and requiring its sole positive output to the configured
hot transparent address. `--enable-private` additionally enables the scoped coin,
worker and payout flags after those checks pass. It requires the public launch
marker to be absent and Stratum bound to loopback; it never opens public mining.

Public mining additionally requires root-owned `/etc/yiimp/launch-approved`.
Create it only after handshake, template, target, accepted-share and payout
readiness checks. The publisher requires this marker, active Stratum, a ready
node/worker and both payment gates; otherwise `/api/pool.json` has
`acceptingMiners:false`. Consumers must reject stale status files too. Opening
native TCP 2192 and changing its bind address require the same checks. A bounded
private owner test can use the bridge's explicit owner-address gate while public
mining stays closed.

The bilingual public status page reveals the Stratum connection only when both
node and pool observations are fresh (at most three minutes old, allowing at most
five minutes of forward clock skew), the node is current, and the pool reports
the configured 0.8% fee and open admission. Missing or stale feeds close the
display. Loading, current admission holds, and unavailable observations have
separate messages. Requests, including response bodies, time out after eight
seconds; an independent timer expires prior availability at the older feed's
three-minute limit. Returning to a suspended tab rechecks freshness immediately.
Run `node --test tests/public-data/status.test.mjs` for the isolated browser-status
regressions. The page documents round-proportional rewards and the 0.05 ZCL payout minimum.

The pool may find no mainnet block during validation. Regtest proves the tested
accounting and protected-Sapling payout paths; it does not establish that a
mainnet reward or payment occurred. Preserve this distinction in launch copy.


## Private website analytics

The thesis repository now owns a separate lightweight Python analytics service,
`zcl-analytics.service`, under `/opt/zcl-analytics`, with private state under
`/var/lib/zcl-analytics`. It runs as its own unprivileged user and has no wallet,
RPC or pool database access. See `netzo92/zclthesis` → `analytics/README.md`.

Caddy permits only `POST /api/analytics/event` to loopback collector port 8792;
the collector requires a server relay credential from the Cloud Run website and
validates event categories/identifiers. It exposes no analytics reads publicly.
The read-only dashboard on loopback8091 is available through the existing operator
IAP SSH connection (Mac local18091). No firewall port or public admin route was
added. Existing pool admin8090, mining ports, payouts and fees are independent.
Credential files and visitor data remain outside the public repositories.

## Public transaction observations

Install `publish-transactions.py` into the separate root-owned
`/opt/zcl-transactions/` directory and the `zcl-transactions.service` and `.timer`
units into `/etc/systemd/system/`. The timer runs 30 seconds after each completed
collection. It uses the installed node CLI as `zclnode`, with an allowlist of
public chain/mempool RPCs. No wallet RPC, transaction index, node restart, reindex,
mining process, or additional VM is required. Version 2.1.2-beta6 supports decoded
`getblock(hash, 2)` and integer `valueZat` outputs.

The private sanitized cache is `/var/lib/zcl-transactions/cache.json` (0600;
directory 0700). It retains metadata for 100 canonical blocks and at most 100
transactions total. Public output is atomically replaced at
`/var/lib/zcl-public/api/transactions.json` (0644) and explicitly served by Caddy
with a ten-second cache. Caddy continues to reject arbitrary RPC/private paths.
Collections are bounded to 60 seconds and 16 MiB per RPC, with a 2 MiB public
artifact limit; systemd adds a 90-second timeout, 20% CPU quota, and 128 MiB memory
limit. Warm collections reuse decoded blocks. Ancestry and final tip checks avoid
mixing branches; a changed tip or failed collection retains the prior artifact.
Consumers must mark it stale rather than assume it remains current.

The payload distinguishes coinbase transactions, visible output sums (including
change), shielded-component presence, and local mempool entry times (which can reset on readmission). It does
not infer hidden parties, payment amounts, exchange ownership, or miner earnings.
Validation: `python3 -m unittest discover -s deploy/zcl/tests -v`.

Transaction exporter deployment verified September 13, 2026 UTC: source `2167a45`
installed in `/opt/zcl-transactions`; timer enabled and public Caddy route active.
The actual node dry run produced a roughly 52 KiB artifact with 100 confirmed
transactions (4 excluding rewards) and 4 mempool entries at height 3,248,586.
Cold collection took 1.04 seconds and a warm collection 0.10 seconds. The installed
service then published fresh observations with all existing node, Stratum and
browser-bridge services active. All 11 exporter tests passed. These are dated
validation observations, not fixed current pool/network figures.

## Durable NonKYC price history

`record-prices.py` fetches the public NonKYC `ZCL/USDT` ticker every UTC minute,
independently of website traffic. Install it root-owned at
`/opt/zcl-prices/record-prices.py` and install `zcl-prices.service` and `.timer` in
`/etc/systemd/system/`. Use a dedicated system user/group `zcl-prices` with no login;
it owns `/var/lib/zcl-prices` (0700) and the public subdirectory
`/var/lib/zcl-public/api/prices` (0755). Enable the timer after `daemon-reload` and
start the service once to validate the initial observation. The service needs
Python's standard library and outbound HTTPS only; it does not access the node,
wallet, pool database, credentials, or analytics.

The complete archive is `/var/lib/zcl-prices/prices.sqlite3` (0600), outside the
source checkout and Cloud Run's ephemeral filesystem. SQLite uses WAL and FULL
synchronous commits. No observations are pruned. One UTC-minute bucket is unique;
a same-minute restart preserves the existing observation without a second fetch.
Missing scheduled intervals remain timestamp gaps. Failed HTTP, network or data
validation requests get explicit failure records, never zero or invented prices.

Prices, 24-hour quote volumes and change percentages retain the provider's exact
decimal strings. Each successful record keeps its collection timestamp and the
exchange's last-trade timestamp separately, as well as market pause status. An
unchanged price is another observation, not a new execution. `status` describes
recorder freshness only; a fresh collection can report an old last trade. These
USDT prices do not replace the homepage's CoinGecko USD market-cap reference.

The exact public route `/api/prices/zcl-usdt.json` serves an atomic, bounded view
of the last seven days (at most 10,080 combined observations/failures; 4 MiB cap).
Its `columns` names describe the compact `samples` rows. Older data remains in
SQLite for future charts and exports. Check both `generatedAt` and
`lastSuccessAt`; an old artifact must not be treated as a healthy live recorder.
Daily SQLite online backups rotate three files in the private `backups/` directory.
These protect against some local database/upgrade mistakes; they share the same
persistent disk and are not an off-site backup. Copy an online backup, rather
than copying only a live WAL database file, when exporting the complete archive.

The independently retrieved public trade backfill lives under
`/var/lib/zcl-prices/backfill/nonkyc-20260913/` and in the public repository's
[data/price-history](../../data/price-history/README.md). Its normalized JSON is
served only at `/api/prices/zcl-usdt-launch-trades.json`. It retains 800 actual
provider-returned trade IDs since launch through the fixed September 13 00:28:06
UTC cutoff, with raw responses and verified hashes. It remains separate from
minute observations. Same-millisecond trades are distinct and must not be
collapsed by timestamp. The backfill is a dated file, not a continuing trade feed.

The timer is persistent and resumes after reboot without synthesizing missed
points. The service has a 45-second limit, 10% CPU quota and 64 MiB memory cap.
HTTP uses a fixed URL, no redirects, a ten-second deadline and a 256 KiB response
limit. Verify scheduled growth and storage with:

```sh
sudo systemctl status zcl-prices.timer --no-pager
sudo journalctl -u zcl-prices.service -n 15 --no-pager
sudo -u zcl-prices python3 -c 'import sqlite3; c=sqlite3.connect("file:/var/lib/zcl-prices/prices.sqlite3?mode=ro",uri=True); print(c.execute("SELECT count(*), min(observed_ms), max(observed_ms) FROM observations").fetchone())'
```

Deployment verified September 13, 2026 UTC from source `07189a7`. Recording began
at 00:36:54.309 UTC; the first stored observation was 00:36:55.157 UTC. Subsequent
scheduled polls at 00:37:02.656, 00:38:03.019 and 00:39:02.756 increased the archive
without manual runs, preserving earlier rows and the unchanged exchange trade time.
The database and online backup both passed SQLite integrity checks. Private modes
were 0700 for state and 0600 for database/backup; the unit runs as `zcl-prices`.
Installed recorder, units and Caddy hashes matched the committed source. Both
public history files returned valid data, and the 800-trade backfill's SHA-256
matched its manifest. All 26 focused Python tests passed, including the existing
transaction exporter tests. Existing node, Stratum and browser-bridge services
remained active; no new VM, GPU or public write endpoint was introduced.


## September 13 operations hardening

The boot and chain disks now both have `autoDelete=false`; deleting the VM will
not automatically delete these disks. This protects against that deletion path,
not against disk loss or corruption. The read-only audit found no failed units,
ample free disk/RAM, and loopback-only RPC, SQL, analytics and bridge listeners.

The RPC authentication timer's boot-relative schedule had no next invocation
after late activation. The activation-relative schedule above fixes that case.
The public availability display also has bounded requests and independent expiry.
[Private recovery coverage](../../docs/PRIVATE-BACKUP-DESIGN.md) records the
remaining backup design; it is not an installed off-VM backup or a restore proof.
