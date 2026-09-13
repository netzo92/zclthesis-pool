# Conditional next-block allocation

Address-scoped `/api/miner.json?address=...` responses include
`miner.projection`. It answers: **with the currently retained round work, what
would this address receive if the pool found the next network block?** It is
neither earned money nor an hourly income forecast. No block arrival, payout
date, exchange price or personal browser-session attribution is predicted.

## Public contract

```text
schemaVersion: 1
asset: "ZCL"
address: selected public address
status: "ok" | "unavailable"
reason: null | short reason code
generatedAt: UTC database observation time
basis: "conditional-next-block-subsidy"
workBasis: "retained-unconsumed-round-shares"
subsidyBasis: "next-height-subsidy-excluding-transaction-fees"
sourceGeneratedAt: published node/subsidy observation time
tipHeight, tipHash: the node observation's network tip
height: tipHeight + 1
roundId: "initial" | most recently allocated pool block hash
subsidyZat: exact next-height subsidy, excluding transaction fees
feePercent: "0.8"
poolWeight, addressWeight: exact decimal strings, 24 fractional places
sharePercent: approximate display percentage, 0 through 100
grossZat, poolFeeZat, additionalDonationZat, allocationZat: integer strings
```

All `*Zat` values are nonnegative integer strings in zatoshis. An unavailable
projection sets all source context, round, fee, weight, percentage and amount
fields to null; it does not return a plausible zero. A known address with no
work can have a verified zero when other eligible round work exists. An empty
round is unavailable (`no-round-work`). Positive eligible work has no arbitrary
minimum rate or browser share-count requirement.

The pool round changes when another pool allocation is journaled. Ordinary
network block arrivals update the subsidy context without resetting the pool
round. Charts must reset on a selected-address or pool-round change and must
only plot actual fresh observations. They should suppress the projection when
the containing report/account is partial, held, locked or unavailable. Neither
the estimate nor a chart point changes balances, earned totals, treasury
receipts, payout thresholds or payout progress.

## Arithmetic and evidence

The helper reads all retained, valid, unconsumed native ZCL shared-pool rows,
including work older than an hour. It excludes rejected shares, another coin
or algorithm, solo shares and rows already consumed by an allocation. It uses
the same MySQL `CAST(difficulty AS DECIMAL(50,24))` as `ZclRewardLedger`, scales
those decimal weights to integers, and calls the allocator's pure
`ZclRewardLedger::split` for both gross allocation and the fee split.

Gross subsidy is split by exact work weight using largest remainders and the
same stable account-ID tie break as payouts. Consequently an address's gross
allocation is its proportional floor or one zatoshi higher. The round's fee is
floored once across non-exempt gross allocations, then split by those gross
allocations with the same remainder rule. A fee-exempt account still applies
its own configured donation, if any. Each account donation is floored from
that account's post-fee amount. Exact conservation is:

```text
allocationZat + poolFeeZat + additionalDonationZat = grossZat
```

`additionalDonationZat` means the allocator's optional account-level retained
deduction. It is distinct from using the shared treasury address as the mining
destination. Selecting that address shows the combined conditional allocation
of all miners using it, including donors, the owner and native miners.

Source context must be the sanitized public node publisher's exact next-height
subsidy, bound to the same tip height/hash and observation timestamp. Both node
and subsidy observations must be at most 180 seconds old, with at most one
second of clock skew, and the node must report synchronized with a recent tip.
The publisher rechecks its tip after `getblocksubsidy(height + 1)`. No wallet
RPC is called by the web request, and transaction fees are excluded. A zero or
unknown subsidy produces no estimate.

The endpoint already provides one repeatable-read, read-only transaction and
a ten-second bounded cache. Projection requires that transaction, rechecks the
accounting hold inside it, and performs only bounded `SELECT`s. It requires
known retained reward-round evidence and fresh canonical status from treasury
reporting, consistent round identities, and valid native accounts/configuration. A pending unallocated pool
block, future share height/time, malformed or nonpositive work, incomplete
schema, or more than 10,000 rows in either retained round or eligible-work reads
makes the projection unavailable. Rows are never silently truncated and
renormalized. No internal account, share or payment identifiers are returned.

Coverage is deliberately limited to retained unconsumed work. Database cleanup
can consolidate or remove historical rows; this feature cannot restore missing
history. Shares flushed after the snapshot and later work can change the
allocation. An uncredited orphan allocation does not put previously consumed
shares back into the next round; accounting holds suppress disputed credits.

The launch fee is fixed at 0.8%. `BlockService` caches its configured fee for an
hour. A future fee change requires clearing that worker cache and updating this
projection contract/UI together; a different configured fee disables this
projection rather than displaying the old rate. The default follows the ZCL
allocator's 0.8% fallback when the mining fee constant is not defined.

## Validation

Run `php tests/payout/round-projection.php`,
`php tests/payout/treasury-stats.php`, and `php tests/payout/miner-stats.php`.
The projection suite uses a synthetic SQLite ledger with `query_only` enabled,
an explicit read transaction, and a before/after `total_changes()` assertion.
SQLite stores the allocator's converted weights as decimal text; the production
query uses MySQL's actual decimal conversion. Existing allocation/share-weight
tests cover the production database precision separately.

`php tests/payout/round-projection.php --fixture` emits a complete synthetic
API response for frontend compatibility testing. Its observation time is
`2000000000000` milliseconds since epoch. No network, real funds, mining or
wallet operations are involved.
