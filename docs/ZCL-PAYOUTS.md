# ZCL payout operation

This fork has a dedicated ZCL payout coordinator. The upstream Bitcoin-style
`sendmany`/`sendtoaddress` payout engine is blocked for ZCL in both applications.
Mined ZCL coinbase outputs must be shielded before paying transparent miner
addresses. The current pool fee is **0.8%**, applied by the reward allocator; the
coordinator pays the miner's entire credited balance. Shielding and payment
network fees come from the pool reserve, without an additional miner deduction.

## Deployment

Apply the reward-ledger migration first, then
`sql/2026-09-12-zcl-payout-ledger.sql`, once, before accepting public shares. These
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
existing verified proving parameters:

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

## Release sources

Behavior was checked against Zclassic v2.1.2-beta6:

- [Mainnet protection, regtest flags and upgrade defaults](https://github.com/ZclassicCommunity/zclassic/blob/v2.1.2-beta6/src/chainparams.cpp)
- [Wallet maturity and coin selection](https://github.com/ZclassicCommunity/zclassic/blob/v2.1.2-beta6/src/wallet/wallet.cpp)
- [Shielding, async payments and operation RPCs](https://github.com/ZclassicCommunity/zclassic/blob/v2.1.2-beta6/src/wallet/rpcwallet.cpp)
- [Exact RPC amount parsing](https://github.com/ZclassicCommunity/zclassic/blob/v2.1.2-beta6/src/rpc/server.cpp)
- [Sapling address validation](https://github.com/ZclassicCommunity/zclassic/blob/v2.1.2-beta6/src/rpc/misc.cpp)
