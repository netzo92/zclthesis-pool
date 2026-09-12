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

## Public launch validation

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
