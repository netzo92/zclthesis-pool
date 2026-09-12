# ZCL reward accounting tests

The launch fee is **0.8%**: floor(reward satoshis * 80 / 10000) is retained once per
round, and the remaining satoshis belong to miners. Largest remainder
allocation conserves the complete reward with deterministic user-id tie breaks.
Explicit account fee exemptions and donations remain visible in the round's
credited/retained totals. Network transaction fees are outside this allocator.
Both 0.8% and 1% fee arithmetic are covered by regression tests.

Ubuntu 24.04 prerequisites: `php-cli php-bcmath php-mysql mariadb-server`.

```sh
php tests/accounting/rewards.php --unit
```

Integration tests require a disposable **InnoDB** database whose name begins
with `zcl_accounting_test_`. The test refuses any other database name. It drops
and recreates its fixture tables; never point it at an application database.

```sh
export ZCL_ACCOUNTING_TEST_DSN='mysql:host=127.0.0.1;dbname=zcl_accounting_test_local'
export ZCL_ACCOUNTING_TEST_USER='accounting_test'
export ZCL_ACCOUNTING_TEST_PASSWORD='test-only-password'
export ZCL_ACCOUNTING_TEST_DESTRUCTIVE=yes
php tests/accounting/rewards.php --integration
```

Tests use the production ledger class and migration, real database transactions,
an injected mid-round insert failure, and two competing PHP processes. They
check exact allocation, share isolation, replay under duplicate block records,
rollback/retry, native-coin account enforcement, fee conservation, maturity,
retained credited earnings, and a persistent accounting hold after a reorg.
They do not connect to a wallet, submit a transaction, generate blocks, or mine.

Apply `sql/2026-09-11-zcl-reward-ledger.sql` with queue workers stopped before
using the new ZCL service. All accounting tables must use InnoDB. Existing
unjournaled earnings are intentionally rejected and need reconciliation;
the migration cannot reconstruct their history. Non-ZCL upstream allocation
is outside the scope of these changes and tests.

The daemon polling job also rechecks already mature ZCL blocks from the past
24 hours. A reorg affecting credited earnings creates a persistent accounting
hold; it does not automatically delete credits or resume payouts. Reorgs beyond
that monitoring window require separate chain monitoring and reconciliation.
