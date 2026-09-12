# ZCL payout operation

This fork has a dedicated ZCL payout coordinator. The upstream Bitcoin-style
`sendmany`/`sendtoaddress` payout engine is blocked for ZCL in both applications.
Mined ZCL coinbase outputs must be shielded before paying transparent miner
addresses. The current pool fee is **0.8%**, applied by the reward allocator; the
coordinator pays the miner's entire credited balance. Shielding and payment
network fees come from the pool reserve, without an additional miner deduction.

## Deployment

Apply the reward-ledger migration first, then
`sql/2026-09-12-zcl-payout-ledger.sql`, then
`sql/2026-09-13-zcl-operator-fees.sql`, once, before accepting public shares. These
migrations target a new empty pool. Do not convert an existing financial ledger
from floating point without reconciliation and a backup.

The payout ledger requires MySQL/MariaDB InnoDB, PHP 8+, PDO MySQL, and cURL.
The reward allocator additionally requires BCMath. SQLite and PCNTL are used by
tests, not the production ledger. Only the Yii 2 earnings worker may clear ZCL
balances; the legacy clearing worker excludes ZCL.

Keep the following explicit settings during private validation:

```php
define('YIIMP_PAYMENTS_ENABLED', false);
define('YIIMP_ZCL_PAYOUTS_ENABLED', false);
define('YIIMP_CLI_ALLOW_TXS', false);
define('YIIMP_ALLOW_EXCHANGE', false);
```

Set the pool's coinbase transparent address and its own Sapling spending address
in the private server configuration. Both must belong to the same local daemon
wallet; neither is a miner's payout address.

```php
define('YIIMP_ZCL_POOL_TADDRESS', 'YOUR_POOL_TRANSPARENT_ADDRESS');
define('YIIMP_ZCL_POOL_ZADDRESS', 'YOUR_POOL_SAPLING_ADDRESS');
define('YIIMP_ZCL_PAYOUT_MIN', '0.05');
```

After deployment validation, setting **both** payment-enabled constants to the
boolean `true` enables the dedicated worker. Strings such as `'true'` and the
integer `1` do not authorize payment. Run once per minute:

```sh
php yii zcl-payout/tick
php yii zcl-payout/status
```

The daemon RPC endpoint must use authenticated loopback access on the pool VM.
This worker rejects remote endpoints, HTTP redirects, mismatched response IDs,
and malformed JSON. It never retries a mutation RPC. Keep exchange, renting,
legacy payout jobs, alternate payout currencies, and the generic replay/repair
commands disabled. Do not operate a second sender on this wallet.

## Accounting and transaction states

Amounts are integer zatoshis in the operation ledger and DECIMAL(24,8) in account
and payout records. A database transaction credits each mature earning exactly
once and keeps its `status=2` evidence. Credit requires a `generate` block with
at least 101 wallet confirmations; immature or orphaned rewards do not become
available account balances. A shared accounting hold stops new credits,
reservations, and wallet submissions.

A batch reserves at most 50 miners' payable balances under account row locks,
creates immutable recipient records and payout rows, and subtracts the reserved
amounts atomically. A durable per-coin lease prevents concurrent workers from
reserving or submitting the same payment. The configured user threshold and the
pool's 0.05 ZCL default minimum both apply.

The coordinator then advances one step per invocation:

1. Check a synchronized daemon, the configured network, and ownership of the
   pool's transparent and Sapling spending keys.
2. Use sufficiently confirmed Sapling balance when it covers all recipients and
   the explicit 0.0001 ZCL transaction fee. Otherwise select mature, spendable
   coinbase outputs and call `z_shieldcoinbase` with at most 50 inputs.
3. Persist the shielding operation ID, poll `z_getoperationstatus`, persist the
   resulting transaction ID, and wait for six confirmations.
4. Persist an immutable `z_sendmany` intent from the Sapling address to the
   reserved transparent recipients. Decimal amount strings retain all eight
   places; ZCL's `AmountFromValue` accepts these strings. Persist its returned
   operation ID and transaction ID, then wait for six confirmations before
   marking payout rows complete.
5. Recheck completed payout transactions from the last 24 hours before starting
   a new batch. A confirmation regression or conflict raises an accounting hold.

If the pool reserve cannot cover network fees, the batch waits for funding.
No recipient amounts are halved and no network fee is silently subtracted from
miner credit. The ledger retains all payout and operation records.

## Operator fee remittance

An additional strict boolean enables remittance through the same coordinator:

```php
define('YIIMP_ZCL_OPERATOR_PAYMENTS_ENABLED', true);
define('YIIMP_ZCL_OPERATOR_TADDRESS', 'PRIVATE_OPERATOR_RECIPIENT');
define('YIIMP_ZCL_OPERATOR_RESERVE', '0.01');
```

Keep the actual recipient only in private server configuration. The destination
is frozen in the private batch journal before submitting a transaction. The
owner's own mining rewards use the ordinary miner account and payout flow; fee
remittance never replaces or reduces that account's earned balance.

The allocator records `fee_sat` separately from donations and total retained
amounts. Each known fee from a `generate` block with at least 101 confirmations
is credited once in `zcl_operator_credits`. Earlier rounds with `fee_sat=NULL`
are unclaimable until reconciled. A reorg of a credited source raises a durable
accounting hold, even if the miner earnings have not yet been cleared.

Miner payouts have priority. When no miner balance is payable, an owner-only
batch may remit at least 0.001 ZCL from already-confirmed Sapling funds. It never
starts another shielding transaction for the owner. The amount is capped by
both of these independently checked limits:

- Mature fee credits, less every previous operator reservation/payment, all
  journaled network fees, the new send's network fee, and the 0.01 ZCL reserve.
- Confirmed Sapling funds, less all unpaid miner account balances (including
  locked and below-threshold accounts), pending/immature earnings, unconfirmed
  payouts, the new send's fee, and the same reserve.

Network costs are recorded at intent creation, before the wallet RPC. Unknown
and held operations retain their reserved cost. Unpriced historical operations
block owner remittance until reviewed. Donations and unexplained wallet deposits
cannot increase the mature-fee ceiling. The reserve is a minimum operating float,
not a guarantee that future network costs will always be covered.

Both limits are checked again under the coin lock immediately before an owner
send intent. If new miner claims remove the surplus before any wallet mutation
has been attempted, the unsent owner reservation is cancelled so it cannot
block miners. Once an intent exists it cannot be cancelled or retried. Owner
transfers use the same operation-ID, transaction-ID, six-confirmation, failure,
and reorg rules as miner payments. Changing the recipient or reserve during an
active batch holds it for reconciliation.

## Unknown outcomes and recovery

`held` means operator reconciliation is required. A timeout can happen after the
wallet accepted or broadcast a transaction. A process death between committed
intent and returned operation ID is also ambiguous. The reservation stays in
place; automatic retries, cancellation refunds, and guessed balance corrections
are blocked. A daemon restart can erase its in-memory operation list, which is
why the database journal is required and why an absent operation is held.

For investigation, preserve the database and wallet backup, read the batch and
operation records, inspect `z_listoperationids` / `z_getoperationstatus` and wallet
transactions, and compare the exact recipients, amounts, fee, and transaction
history to the stored intent. Do not use `z_getoperationresult`, which removes
completed operation evidence. Do not invoke `payout/redotx`, refund the account,
or clear a hold merely because a transaction ID is absent. There is deliberately
no automated release/rebroadcast command: a reviewed reconciliation must first
establish the outcome and preserve its evidence in the journal.

A reorg affecting already credited block rewards is an accounting hold rather
than a deletion or a second credit. The block worker rechecks recent confirmed
ZCL blocks. Very deep reorgs beyond the configured 24-hour recheck window still
require operator monitoring and reconciliation.

## Validation

Run the non-wallet suite with PHP CLI, cURL, PDO SQLite and PCNTL:

```sh
bash tests/payout/run.sh
```

For real InnoDB locking and rollback tests, use only the disposable database named
`zcl_payout_test` on the isolated test container `zcl-ledger-db`:

```sh
ZCL_TEST_DSN='mysql:host=zcl-ledger-db;dbname=zcl_payout_test' \
  php tests/payout/ledger.php
```

`tests/payout/regtest.sh` is the additional real-daemon test. It starts a fresh
loopback-only regtest data directory, explicitly enables coinbase protection and
Sapling, and creates only synthetic regtest blocks. It does not access the
mainnet wallet or run continuous mining. It verifies that direct transparent
coinbase payment fails, then exercises actual shielding, asynchronous operation
results, confirmations, a Sapling-funded miner payout, exact received amounts,
and retained operation evidence. Provide release-verified binaries and the
existing verified proving parameters. Run as the node service user, whose normal
home contains `.zcash-params`; this release does not support `-paramsdir`:

```sh
ZCLD=/path/to/zclassicd ZCLCLI=/path/to/zclassic-cli \
  ZCL_PARAMS_DIR=/path/to/zclassic-params bash tests/payout/regtest.sh
```

The test requires Python 3 and PHP cURL/PDO SQLite. It leaves a report and
synthetic-chain evidence under `/tmp/zcl-payout-regtest.*` and stops its daemon.
Its scope is protected-coinbase Sapling regtest with cheap Equihash 48,5; it does
not establish mainnet mining performance or validate Equihash 192,7 Stratum
shares. The live pool also needs its own synchronized node, verified block/share
path, monitoring, backups, and the validated accounting migration.

## Recorded real-daemon result

The protected-Sapling regtest passed on the GCP pool VM on 2026-09-12 UTC.
The [report](../tests/payout/evidence/protected-sapling-regtest.json) records
release-binary hashes, both confirmed operation/transaction IDs, the 113-block
synthetic chain, and two recipients receiving exactly 0.09920000 each. The test
also rejected direct protected-coinbase payment, verified the Sapling spend,
checked no replay, and stopped its isolated daemon. This is real-daemon regtest
evidence; no mainnet coin was sent by this test.

The [operator-remittance report](../tests/payout/evidence/operator-fee-regtest.json)
adds a second actual-daemon run on 2026-09-12. After the two miner payments, an
owner-only Sapling transfer paid exactly **0.08970000 ZCL**, leaving
**12.20160000 ZCL** backing a locked miner account plus the **0.01000000 ZCL**
reserve. Three explicit network fees were charged to the operator portion. All
three operations confirmed, the synthetic chain ended at height 119, and a
subsequent tick did not replay either miner or operator transfers. SQLite and
real InnoDB tests also cover source-fee maturity, donations, unknown historical
costs, accounting reorg holds, transaction rollback, and concurrent owner workers.

## Release sources

Behavior was checked against Zclassic v2.1.2-beta6:

- [Mainnet protection, regtest flags and upgrade defaults](https://github.com/ZclassicCommunity/zclassic/blob/v2.1.2-beta6/src/chainparams.cpp)
- [Wallet maturity and coin selection](https://github.com/ZclassicCommunity/zclassic/blob/v2.1.2-beta6/src/wallet/wallet.cpp)
- [Shielding, async payments and operation RPCs](https://github.com/ZclassicCommunity/zclassic/blob/v2.1.2-beta6/src/wallet/rpcwallet.cpp)
- [Exact RPC amount parsing](https://github.com/ZclassicCommunity/zclassic/blob/v2.1.2-beta6/src/rpc/server.cpp)
- [Sapling address validation](https://github.com/ZclassicCommunity/zclassic/blob/v2.1.2-beta6/src/rpc/misc.cpp)
