# GCP node operations

This pool runs a CPU-only full node, Stratum server, website and payment worker.
The server does not run a miner (`gen=0`). Public miner access and the payment
gates must remain closed during first-start validation and maintenance.

## Current full-address rich list

The first successful own-node publication on 2026-09-12 was checked against the
node's exact full chainstate commitment, UTXO count and transparent value before
an atomic public-file replacement. Its private working directories and Btrfs
snapshot were removed afterward. The original private bootstrap snapshot was
retained.

| Field | Verified publication |
| --- | --- |
| Source | `https://pool.zclthesis.com/api/richlist/zcl.json` |
| Block height | 3,247,769 |
| Block time | 2026-09-12 07:09:47 UTC |
| Published | 2026-09-12 07:12:39 UTC |
| Block hash | `0000167fb2f071c0f6200ac19ea8063c50dce08483ccb9593341cf6ab0e1c74e` |
| Full chainstate commitment | `a2ca1a46930ff8d60764c2b9017601a2fae95718e197b66eead2a52e17ac8b1e` |
| Transparent addresses | 64,481 |
| UTXOs | 1,346,365 |
| Total transparent zatoshis | `1043876533934281` |
| Unattributed transparent zatoshis | `494777654985` |
| JSON bytes | 14,491,384 |
| JSON SHA-256 | `0e523f9f23805f33c209c5ce8a6b5e4f2e900669236c62c7d7438979c711ac16` |

Provenance is **anchored fast sync followed by forward validation**, not a replay
from genesis. The imported-tip hold cleared naturally after independent live
peer corroboration. Both bootstrap and finalization holds were false and the
node was at its current header tip before export. A later artifact will have
different metadata; the table records this observed publication only.

The deployed thesis app validates the complete artifact and exact address
totals. It checks the pool feed every five minutes, retaining its last valid
dataset with a stale label if a fetch or validation fails. The pool's normal
snapshot export schedule is every two hours. Raw databases, keys and wallet
files never enter the public directory.

The live main app adopted this artifact through its normal cache refresh at
07:18:16 UTC. Both `/api/richlist` and `/api/richlist-comparison` returned
`status: ok` with the own-node height and address count. The full main-app JSON
was independently revalidated, including all address balances and exact totals.

## Maintenance order

Before stopping the node, stop `zcl-pool-worker.timer`,
`zcl-pool-payout.timer`, `zcl-rpc-cookie.timer` and their corresponding services.
Their `Requires=zclassic.service` dependency can otherwise start the node
during a maintenance pause. Temporarily disable the timers for a VM reboot,
then restore them after the node, private coinbase destination and services have
been checked. The node-stats and rich-list schedules have only an `After`
dependency.

Set an explicit `dbcache=256` in the private node configuration to request a
256 MiB coin cache; this release otherwise raises the default to 1,024 MiB on
64-bit hosts. Preserve the private `mineraddress` setting, which must match the
coordinator's configured pool transparent source. Stop the node cleanly before
stopping the VM. Change machine type in place; retain both disks and the reserved
IP address.

After a completed, verified initial bootstrap, archive its one-time
`zclassic.service.d/bootstrap.conf` override outside the systemd drop-in
directory and reload systemd before the first normal restart. Keeping
`-bootstrappeer` in `ExecStart` requests another import; the daemon refuses
that request when the existing `blocks` directory is present. Preserve all
chain data and provenance when returning to the ordinary `gen=0` service.

A root-owned 6 GiB swap file is available on the existing ext4 boot filesystem.
Swap is a capacity buffer; acceptance of a smaller machine also requires useful
RPC latency, continued block validation, successful export, and no OOM events or
sustained paging stalls. Keep the public mining gates closed during this check.

## Bounded 2 GiB trial

The 2026-09-12 e2-small trial retained both disks and their flags, the reserved
public/private IP addresses, `dbcache=256`, and 6 GiB of active swap. The node's
normal startup remained in block-index loading after roughly six minutes. At
07:29:58 UTC it had loaded 2.0 million of approximately 3.25 million entries,
with 1,507,572 KiB resident and 1,018,552 KiB swapped in the node process. System
memory pressure reported about 10% full stall time over the last ten seconds;
169,404 KiB was available. No OOM events occurred.

The bounded trial was stopped because paging continued and a useful current-tip
baseline had not been reached. This is evidence about cold-start capacity, not
a measurement of e2-small's eventual steady-state performance. The next tested
configuration uses 4 GiB to provide more working-memory headroom.

## Verified 4 GiB operation

The VM restarted as an e2-medium on 2026-09-12 at approximately 14:16 UTC,
retaining both disks and the reserved IP. Normal startup reached the current
tip, and the next complete rich-list export succeeded in 85 seconds at height
3,248,122. No second bootstrap import was needed.

At 14:59 UTC the node reported height and headers 3,248,150, verification progress
above 0.999999, and both bootstrap and finalization holds cleared. Five sequential
read-only RPC calls took 13–20 ms. Available RAM was approximately 986 MiB;
the node used approximately 2,351 MiB resident and 1,182 MiB swapped memory.
System swap use was approximately 1,859 MiB. Recent memory-pressure averages
were zero and the kernel reported zero OOM kills. These are observed operating
checks, not a load-capacity guarantee for an arbitrary number of miners.

The accounting worker and payout timer also completed repeated successful runs
after their activation-relative schedules were installed. An idle payout result
means there is no eligible payment; it does not establish that a mainnet payment
has occurred. Keep these health and export checks when evaluating future capacity
changes.

## Compute price reference

The published us-central1 on-demand E2 rates checked on 2026-09-12 were:

| Machine | RAM | Compute/hour | Compute at 730 hours |
| --- | --- | --- | --- |
| e2-standard-2 | 8 GiB | $0.06701142 | $48.92 |
| e2-medium | 4 GiB | $0.03350571 | $24.46 |
| e2-small | 2 GiB | $0.016752855 | $12.23 |

These figures exclude disks, external IPv4, egress and the separate thesis
Cloud Run service. E2 small and medium use shared CPU capacity: two visible
vCPUs do not mean two sustained full cores. Refer to Google's
[price table](https://cloud.google.com/products/compute/pricing/general-purpose)
and [E2 machine specifications](https://docs.cloud.google.com/compute/docs/general-purpose-machines).

## Initial public launch validation

Public mining opened at 15:04 UTC on September 12, 2026. The native deployment
was `7695f54`, with binary SHA-256
`20e8d70558ac75b252af7e8ca3e400d4fa9185d61c1f6bab33fea506c83788ea`.
An earlier private test exposed unterminated local header-reversal buffers; the
fix passed a production-code regression with poisoned automatic storage and
ASan/UBSan. The same regression reproduced the old code's stack-buffer overflow.

A one-minute WebGPU session on the operator's Mac then generated 30 valid proofs,
submitted 14 target-qualified shares, and received 14 accepts with zero rejects.
It stopped automatically at its time limit. A read-only production accounting
audit recorded all accepted difficulty weight (`0.000546875`) against the expected
operator miner account and verified that the 0.8% fee recipient matched the
separately stored owner address. There were no accounting holds, pending payment
batches, blocks found, or confirmed payments. Earlier rejected-share rows remain
in the database as test evidence; they are not accepted work.

The private owner-only browser test override was removed before public admission.
After changing Stratum to `0.0.0.0:2192` and allowing only that additional GCP
ingress port, an external laptop completed subscribe, authorize, target and job
checks without submitting further work. The root-owned launch marker was then
created, and the fresh public pool feed reported `acceptingMiners: true` with
ready node, worker and payment gates. No VM GPU or daemon mining was enabled.

Browser counts show work performed during that session; database share rows are
aggregates, not one row per protocol event. Accepted work is an allocation weight
for a future round reward, not a wallet balance or a guaranteed payout.

## Final repaired deployment and public reopening

Public admission was temporarily closed after the initial launch to repair job
delivery and a compact-target calculation, and to install a fresh read-only node
readiness diagnostic. It reopened at 15:57 UTC on September 12, 2026. The final
native source is `8de5dbf8b4bb32afb5d37a83590d703e3c3be45c`, with binary SHA-256
`8b0546bfebd482e772211a6f413bc5e9ab34a60201e9dc34ce10947c69657ebd`.
The previous binary is retained at `/opt/zcl-pool/stratum.pre-8de5dbf`.

Initial job selection now scans eligible jobs, and subsequent broadcasts reach
direct ZCL miners with autoexchange disabled. ZCL candidate-block decisions use
the complete 256-bit target, and compact-target projection no longer invokes an
undefined shift. Production-code regression tests passed under ASan/UBSan,
including old-code negative controls, 95 independently calculated target fixtures,
a historical mainnet proof, and the actual block-submission branch with its RPC
call intercepted. These checks do not claim a general audit of inherited code.

The node daemon was built from upstream `v2.1.2-beta6` commit
`14a83d510ffd109d3fa09bf74ebf8c28854a263f` plus the
[read-only live-corroboration patch](../deploy/zcl/patches/README.md). Cloud Build
`62a2f6b2-92bb-43ba-94d4-dec93e42ed54` completed successfully, including three
compiled corroboration tests. The installed daemon SHA-256 is
`28898a96572900e820e04368c97ee8b0fc21b6cbbc62ed92adcd13d1e74c3d9d`.
The original official daemon remains at
`/opt/zclassic/rollback/831b14cb794fb53a2e3d05a3143089799e725cf80da850168d271782101ac012/zclassicd`.
The patch reports current peer corroboration without changing consensus,
finalization, or the bootstrap hold. Pool and payout consumers strictly validate
the live result against the current tip; an absent diagnostic retains the strict
cached-hold fallback for an official-binary rollback.

The final two-minute Mac WebGPU test ran from 15:51:47 to 15:53:48 UTC against
this node and final native build. It received three jobs, generated 20 valid
proofs, and submitted 7 target-qualified shares: **7 accepted, zero rejected**.
The worker stopped at its time limit. Together with the earlier successful run,
21 accepted shares contributed total difficulty weight `0.0008203125`, all
attributed to the expected operator miner account. The final production audit
verified the 0.8% owner fee recipient, zero accounting holds, zero pending payment
batches, and no mainnet blocks or confirmed miner/operator payments.

The owner-only browser override was removed, Stratum listened on
`0.0.0.0:2192`, the TCP 2192 ingress rule was enabled, and the root-owned launch
marker was restored. External native and normal public WebSocket checks both
received authorization, target and job messages. A separate visitor address also
received work; those handshake checks did not mine or receive payments. Public
pool and miner pages passed English/Spanish readiness, form, language-link and
responsive layout checks at widths from 320 to 1440 pixels without browser or
HTTP errors. The UI remained idle until an explicit start.

At 16:02:57 UTC the node reported height and headers 3,248,200, live corroboration
from three independent outbound peers, no bootstrap hold, and a 24 ms RPC response.
Node, Stratum, accounting, payout, cookie and rich-list schedules were active.
The fresh pool feed reported open admission with its readiness and payment gates
enabled. This remains a CPU-only pool host; the Mac test is stopped.

The final full rich-list export completed successfully at 15:59:45 UTC:

| Field | Verified publication |
| --- | --- |
| Block height | 3,248,197 |
| Block hash | `00000a6e5292fe090a2757a9487a4301d887f782de7f140cc6c2c352c2bfecb0` |
| Full chainstate commitment | `447bb330e8520adca69eab81ed62a93334d5af24ca2e95fbc4a6a4f168ca1387` |
| Transparent addresses | 64,481 |
| UTXOs | 1,346,377 |
| Total transparent zatoshis | `1043892809226092` |
| JSON bytes | 14,491,404 |

The exporter removed its temporary private snapshot and recovered database after
atomic publication. The normal two-hour schedule remains enabled.

## Pool-mined ZCL totals

[`zcl-worker/mined`](../yiimp2/commands/ZclWorkerController.php) is a read-only
summary of this pool's retained ZCL block and immutable reward-round ledger.
[`ZclMinedStats`](../yiimp2/services/ZclMinedStats.php) checks each accepted
candidate against the local node's current `getblockheader` result. It sums exact
integer `zcl_reward_rounds.reward_sat` amounts only when the block ID, coin ID,
hash, recorded reward and allocation conservation agree. It counts each
journaled hash once. The summary does not use network emission, miner shares,
deposits, estimated earnings, or wallet balances as mined coins.

The existing minute-based
[`publish-pool-status.py`](../deploy/zcl/publish-pool-status.py) adds a sanitized
`mined` object to `/api/pool.json`. Its `allTime`, `last24h` and `lastHour` windows
contain `rewardZat` (an integer string), `blocks`, `matureRewardZat`,
`matureBlocks`, `immatureRewardZat`, and `immatureBlocks`. Rewards are gross
coinbase receipts, including their transaction fees, before the pool's 0.8%
allocation fee. They are not amounts already paid to miners or to the owner.
An accepted block remains in the immature portion until the ledger category is
`generate` and the live node reports at least 101 confirmations.

The rolling windows use `(now - duration, now]` in UTC and `blocks.time`:
Stratum's recorded find time, or the coinbase timestamp when the existing worker
recovers a block from wallet transaction history. `allTime` means the entire
retained pool ledger. `coverageStartedAt` remains null because the ledger has no
durable creation-time marker; it must not be replaced with an invented launch
timestamp. The summary explicitly identifies this as `retained-pool-ledger`
coverage and `pool-recorded-time` windows.

Live headers reporting negative confirmations are excluded. Records marked
orphaned or rejected are also checked against the live node; if they have returned
to the canonical chain, they remain unknown until accounting is reconciled.
Candidates still awaiting validation, missing/malformed reward rounds,
unavailable headers and journal rows missing their block records increase
`unknownBlocks`; `status: partial` then reports known amounts only. An accounting
hold also yields `partial`. The dashboard displays every verified subtotal with
a ≥ prefix, including zero, and shows the unresolved block count. These lower
bounds do not claim pending rewards are zero. Dashes are reserved for unavailable
data. A failed database/readiness check, invalid publisher
payload, or more than 10,000 retained rows produces `status: unavailable` with
null windows, never fabricated zeroes. Canonical-header work has a 20-second
budget and the read-only CLI has a 30-second outer timeout. These bounds must be
revisited before the retained ledger approaches the row limit.

Only public aggregates pass the publisher allowlist. No addresses, account IDs,
SQL details, exception messages or wallet metadata enter this feed. The command
does not credit balances or issue wallet mutations, and failure of the mined
summary does not change public mining admission or payout gates. The existing
pool-status timer handles updates without adding a service or VM.

Focused synthetic validation:

```sh
php tests/payout/mined-stats.php
python3 -m unittest deploy/zcl/tests/test_publish_pool_status.py -v
```

These cover exact sums, UTC boundaries, immature/mature transitions, duplicate
hashes, reorged headers, missing/invalid records, unknown outcomes, holds,
resource bounds, and publisher allowlisting. They do not claim a mainnet block
has been mined or a mainnet payment has occurred.

Deployment on September 13, 2026 installed the scoped public/status source from
`ba968af`, retaining backups of replaced files. Caddy reloaded after validation;
the node, Stratum and existing status timer remained active. No schema migration,
fee/recipient edit or wallet mutation was needed. The read-only live result at
01:55:47 UTC was `ok`: zero blocks and zero ZCL in all three windows, zero unknown
blocks, zero excluded orphans and no accounting hold. The public endpoint and
homepage proxy agreed. This observed zero is not evidence of a payout or a fixed
future result.

Final validation passed 105 PHP assertions, eight publisher tests and the website's
92 Node tests. Live English/Spanish checks on both pages at mobile and desktop
sizes matched reviewed assets and rendered all three verified values correctly.

## Public miner dashboard data

The exact `/api/miner.json` route runs
[`public/miner.php`](../deploy/zcl/public/miner.php) through the existing PHP-FPM
socket. Only this route reaches the standalone script; it does not initialize
the admin web application, sessions, queue or wallet RPC. The optional `address`
query accepts a checksum-validated mainnet t1/t3 address. Its database lookup is
case-sensitive and constrained to ZCL. Missing addresses return a verified
`not-found` state; invalid requests receive 400. Omit the parameter for pool-only
work statistics. This is public reporting by payout address, not proof of wallet
ownership; everyone using the same payout address shares its totals.

Before enabling the route, create `/var/cache/zcl-miner-stats` owned by the
PHP-FPM user (`www-data`), mode 0700. The script keeps at most 512 short-lived
hashed cache entries, reuses results for 10 seconds, removes expired entries,
permits one database reader at a time, and limits cache misses to four per
second. Busy/capacity/error responses return sanitized 503 data with null
financial values. Queries use bound parameters, a read-only transaction,
MariaDB's two-second per-statement limit and a maximum of 10,000 selected rows.
PHP's eight-second execution limit is additional; it is not a wall-clock I/O
deadline. No database migration, additional service or VM is required.

[`ZclMinerStats`](../yiimp2/services/ZclMinerStats.php) reports exact integer
zatoshi strings: current `availableZat` is the account's mature, unreserved
balance; `immatureZat` and `awaitingCreditZat` are separate retained earnings;
`creditedZat` is historical retained status-2 credit, already represented in
balances, reservations or payments. `earnedZat` sums the retained status-0/1/2
net allocations. The allocator already deducted the fee and any configured
account donation; the dashboard must not deduct 0.8% again. Do not sum these
display categories to invent an additional wallet balance.

`paidZat` requires the immutable miner payment item, matching payout amount,
completed payout and batch, and exactly one confirmed send operation whose
transaction ID matches the payout. Shielding confirmation alone is insufficient.
Incomplete reservations remain `pendingPayoutZat`, including held payments.
Missing journal links, unsupported states and mismatched payment evidence fail
closed. Accounting holds and inconsistent earning evidence return partial
status. A preserved balance or paid record under a reorg hold is ledger history,
not a claim that the disputed funds are currently spendable.

`thresholdZat` is the greater of the configured pool minimum and the account
threshold. Progress uses only the available balance and is capped at 100%.
`thresholdReached` is not a payment promise: account locks, holds, maturity,
reserve funding and the coordinator's checks still apply. A payment reservation
reduces available balance immediately; the dashboard shows the reserved amount
separately until confirmed.

Five-minute and one-hour work windows sum accepted, coin-scoped stored share
difficulty, using `(now - duration, now]`. These rows aggregate protocol events,
so row counts must not be labeled accepted share counts. The native Equihash
target and stored `assignedDifficulty / 256` convention imply approximately
65,537 expected solutions per stored weight unit; dividing by the window seconds
gives `estimatedSolps`. This is a statistical work estimate, not earned ZCL.
`networkPercent` divides it by a fresh, synced own-node `networkSolps` observation,
which itself estimates chain work over the latest 120 blocks. Stale, invalid or
missing network data yields a null percentage. Pool gross rewards continue to
come from the separately timestamped `pool.mined` snapshot.

Coverage is explicitly `retained-pool-ledger`. Generic upstream stats cleanup
can prune historical credited earnings if someone enables that schedule; the
deployed dedicated ZCL worker does not invoke it. Neither a lifetime earnings
claim nor historical balances should be reconstructed from these records.
Earning creation, maturity and payout reservation timestamps are not credit or
receipt timestamps. The API reports `history.available: false`; the browser can
plot actual timestamped observations collected since the dashboard opened.

Run `php tests/payout/miner-stats.php` for synthetic SQLite regression coverage
of exact monetary categories, reservation/confirmation evidence, threshold
progress, checksum validation, ZCL/address isolation, window boundaries,
native work scaling, stale data, accounting holds and broken journal links.

The dashboard API was deployed from `0302954` on September 13, 2026 at 02:07 UTC,
with the cache owned by `www-data` at mode 0700. Caddy reloaded after validation;
node and Stratum stayed active. The live address lookup returned exact zero
credited/available rewards and a 5,000,000-zatoshi threshold. Network-share
estimates advanced with real accepted work. No wallet or payment operation was
invoked by this deployment or its checks.

All 58 synthetic accounting checks passed. Live HTTP verification covered valid
lookup, short-cache reuse, query validation, POST rejection, empty HEAD bodies,
private/PHP/admin path denial and absence of cookies/private output fields.
The webminer UI from `8ec2721` passed 46 tests and live EN/ES desktop/mobile
checks in a separate headless browser. Its actual 30-second refresh advanced
the graph and keyboard-accessible observation selector. That isolated browser
attempted zero GPU workers or mining connections. Eight deployed assets matched
reviewed source, including the unchanged mining/consent implementation.

### 2026-09-18: dashboard lower bounds and pending rounds

Read-only production SQL found 31 `generate` block records and 19 reward-round
journals. Blocks 20–31 have no matching round. The earliest pending record,
block 20 at height 3253230, was imported after block 19 at height 3253231.
Shares through block 20's recorded cutoff had already been assigned to 3253231.
`ZclRewardLedger::allocate()` therefore finds no eligible unassigned shares for
block 20 and returns; its earlier-pending-block guard prevents later allocations.
This is an accounting backlog, not evidence that the mining listener is down.
The public report showed 742227745 verified zatoshis, 19 blocks, and 12 unknown
blocks; block totals still require their canonical-header and journal checks.

The display now renders partial zero subtotals as `≥ 0`, with an explicit warning
that pending rewards are excluded. This does **not** reconcile the 12 missing
rounds or alter balances, fees, allocation policy, payout gates, or share ownership.
Reconciliation must establish the historical share windows and already credited
payments before changing financial records. Do not remove the earlier-round guard
or assign missing rewards to the operator merely to clear the dashboard warning.
