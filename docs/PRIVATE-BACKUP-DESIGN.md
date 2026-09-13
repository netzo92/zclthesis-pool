# Proposed private recovery coverage

This is a design for review, not an installed backup system. No backup job,
wallet call, copy, bucket or access grant is created by this document. The
September 13, 2026 read-only audit found daily price/volume backups on the boot
disk, initial native wallet backups, no analytics backup in its state directory,
and no GCP snapshots or snapshot policies for either ZCL disk. Boot-disk deletion
protection is a separate operational change; it does not replace a backup.

## Minimum data set

| Data | Consistent capture | Recovery significance |
| --- | --- | --- |
| Entire `yiimp_zcl` MariaDB database | Transaction-consistent logical backup after verifying live table engines and excluding concurrent DDL | Includes shares, miner liabilities, round allocations, payout intents, reservations, transaction references, operator fee credits, schema journal and RPC credentials |
| `/var/lib/zcl-analytics/analytics.sqlite3` | SQLite online backup API into a private temporary file | Preserves events and recording-start metadata; never copy only a live main database while omitting its WAL |
| `/var/lib/zcl-prices/prices.sqlite3` | SQLite online backup API | Preserves every observation, failure and original decimal string |
| `/var/lib/zcl-prices/volume.sqlite3` | SQLite online backup API | Preserves provider trade IDs, collection coverage and exact quantities |
| Pool's native wallet | Explicitly approved native `backupwallet` operation into the existing private export directory | Preserves pool wallet keys and metadata without printing/exporting individual keys |
| Minimum recovery configuration | Explicit allowlist of pool/node runtime configuration, approved recipient mappings and deployed code/migration versions, encrypted with the data | Allows recovery with the same destinations and accounting settings; these files contain secrets and must never enter git or public storage |

Do not capture `blocks/`, `chainstate/`, raw richlist snapshots, swap, logs,
transaction caches, web assets or other reconstructable blockchain data. Code
comes from pinned commits. Owner-wallet keys are not on this VM and must not be
requested or added to this backup set. Reconstruct MariaDB users with new secrets
or include a separately reviewed minimal recovery record; never assume the pool
database alone contains server authentication/grants.

For MariaDB, a proposed logical capture uses a single transaction and streaming
rows against the one database, with routines/triggers/events and schema included.
Verify live storage engines before selecting that method: a transactional dump
does not make nontransactional tables consistent. If any required table is
nontransactional, design an explicit consistent backup/lock window before
installing a schedule. Do not introduce an automatic writer pause or DDL job.
Authentication stays on the local Unix socket or a private credential file,
never in command arguments or printed output.

The pinned node's `backupwallet` implementation locks the wallet, checkpoints its
database and copies the native file to the configured export directory. An
ordinary filesystem copy of a live wallet is not the substitute. Creating this
backup is a new explicitly approved wallet operation, although it sends no funds;
it is outside the read-only audit. New/imported key material also needs fresh
coverage before it is relied on. Initial wallet backups are not evidence that
every subsequently generated key is recoverable.

## Schedule and destination

Start with hourly captures of the financial database and native pool wallet,
with a target recovery point of one hour, plus captures before reviewed schema
changes and after approved key changes. Capture the three SQLite databases
hourly. One non-overlapping orchestrator can sequence the captures with low CPU
and I/O priority, size/deadline limits, private 0700 staging and 0600 files. The
existing three daily price/volume copies remain useful for same-disk recovery.

Use a dedicated private object-storage destination with public access prevented.
Encrypt before upload using an owner-approved recovery public key whose private
key is held outside the VM. The VM uploader should only create uniquely named
objects; it should not read, overwrite or delete earlier backups. Recovery and
retention administration use separate operator identities. No bucket, IAM grant,
encryption recipient or copy destination is assumed approved by this proposal.

For financial and price/volume archives, a small starting policy is 24 hourly
generations, seven daily generations and four weekly generations, enforced by
the storage service, with an agreed deletion-protection interval. The retention
mechanism must account for any immutable-object period before deleting objects.
Keep analytics in a separate retention group: archive expiry must respect its
documented 90 UTC-calendar-day event limit. Do not silently extend visitor-data
retention by applying the financial archive policy; select a compatible capture
and expiry policy before provisioning analytics backups.

Every completed generation needs a private manifest containing start/finish UTC
times, component names, byte counts, checksums and software/schema versions.
Write a completion marker only after all required components are captured,
validated, encrypted and uploaded. Check remote object metadata/checksums, retain
the preceding complete generation on failure, and alert on a missing generation
or failed component. Never log database rows, addresses, keys or credential
contents. Local cleanup must preserve the last complete recovery copy until its
replacement is verified.

## Restore proof and payment safety

A database snapshot and wallet backup taken seconds apart are not one atomic
payment checkpoint. Record both capture times. Restoration must begin in an
isolated environment with mining admission, automatic payouts, operator
payments and credential timers disabled. Do not start cloned production units
while testing a restore.

At least once before relying on this system, decrypt a generation using the
independent recovery key, restore MariaDB and the SQLite files into disposable
private storage, verify schema versions and SQLite integrity, and check ledger
invariants without invoking payment jobs. A wallet restore test must use an
isolated node with no sending automation and must verify that required pool
destinations are recoverable without displaying private keys.

For an actual recovery, reconcile wallet/chain transactions against recovered
payment intents and reservations before enabling any sender. Activity after the
database snapshot may be missing from the recovered journal; absence is not
permission to repay. Retain ambiguous liabilities and submissions for review,
and prune expired analytics events before any restored dashboard is served.

Only after successful restore evidence should this be described as verified
backup coverage. Files existing on a disk, or an upload reporting success, do
not establish that the pool can safely resume payments.
