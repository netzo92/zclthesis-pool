# Read-only treasury statistics

`/api/miner.json` and `/api/miner.json?address=...` both include `pool.treasury`.
The reporting endpoint uses a repeatable-read, read-only transaction and its
existing bounded cache. The new helper performs only bounded `SELECT`s. It does
not read private configuration, call wallet RPC, allocate rewards, change a
recipient or initiate payments. The approved public recipient is fixed in
`ZclTreasuryStats::RECIPIENT`.

## Contract

```text
schemaVersion: 1
asset: "ZCL"
unit: "zatoshi"
recipient: approved shared treasury address
generatedAt: UTC observation time
status: "ok" | "partial" | "unavailable"
coverageBasis: "retained-pool-ledger"
allocationBasis: "retained-ledger-block-status"
receivedBasis: "confirmed-payout-journal"
coverageStartedAt: null
canonicalStatus: "ok" | "partial" | "stale" | "unavailable"
accountingHeld: boolean | null
unknownRounds: integer | null
unknownPayments: integer | null
excludedOrphanRounds: integer | null
allocated:
  operatorFees: {totalZat, matureZat, immatureZat}
  otherRetained: {totalZat, matureZat, immatureZat}
  sharedMining: {totalZat, matureZat, immatureZat}
received: {operatorTransfersZat, sharedMiningZat, totalZat}
```

Every amount is an exact nonnegative integer string in zatoshis. Unavailable
results have `allocated`, `received`, counts and `accountingHeld` set to null.
Missing tables, ambiguous recipient accounts or more than 10,000 rows in any
bounded history read produce unavailable results without affecting otherwise
valid address-scoped reporting.

`allocated.operatorFees` reads the immutable round's exact `fee_sat`; it does
not multiply a gross lifetime total by today's 0.8% fee. The remaining
`retained_sat - fee_sat` is reported as `otherRetained`: account-level donation
deductions, not a confirmed treasury transfer. Legacy null fees are unknown,
never assumed to be zero. `sharedMining` contains net allocations to the
approved address's ordinary miner account. This includes all miners using that
address, including donors, owner mining and native miners; it is not any
browser visitor's personal allocation.

Allocation requires matching block/round identity, exact reward conservation,
positive ledger confirmations and supported states. Orphan/rejected source
blocks are excluded. Mature amounts require `generate` and at least 101
confirmations; shared earnings must agree with that state. Retained credits
affected by a reorg remain disputed rather than silently counted again.

`received` counts only complete payment batches backed by exactly one confirmed
send operation. Operator transfers must target the approved recipient. Shared
mining receipts must have matching destination, item amount, payout amount,
completion flag and transaction ID. Shielding, pending operations, cancelled
batches, operator credit accrual and account balances are not receipts. A
transaction appearing under multiple reported transfers is disputed and never
counted twice. These are transfers confirmed by the pool's journal, not the
recipient's present on-chain balance, external deposits or a new web-request
verification of transaction confirmations.

The existing mined summary supplies `canonicalStatus`; observations older than
180 seconds are stale. Allocation uses the retained ledger's block status,
without another per-block RPC pass. Missing/stale/partial canonical coverage,
accounting holds or unresolved evidence make the aggregate partial. Show
partial values as known retained-history subtotals with a ≥ prefix, including
zero. A displayed ≥ 0 is a lower bound, not a verified total of zero. Reserve
dashes for unavailable data. Keep orphan counts, holds and
observation time visible. No start date, rolling receipt windows, pre-page chart
history or complete history beyond retained records is claimed.

## Validation

Run `php tests/payout/treasury-stats.php` and
`php tests/payout/miner-stats.php`. The focused synthetic suite enforces SQLite
`query_only`, checks that reads never change the database, and covers exact
allocation/receipt separation, both API modes, maturity, reorgs, missing fee
history, conflicting/duplicate evidence, wrong destinations, holds, stale
canonical observations and bounded history. No real funds or miner are used.
