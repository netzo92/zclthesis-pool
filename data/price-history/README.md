# NonKYC ZCL/USDT price history

The live recorder retains every minute observation and fetch failure in the VM's
private `/var/lib/zcl-prices/prices.sqlite3`. Its bounded public seven-day view is
`https://pool.zclthesis.com/api/prices/zcl-usdt.json`. Successful observations are
snapshots of the exchange's last traded price, not a complete trade tape. Collection
time and last-trade time are separate; repeated prices do not mean new trades.

`nonkyc-20260913/` is an immutable historical backfill from NonKYC's public WebSocket
`getTrades` method, retrieved September 13, 2026 at 00:28 UTC. It contains 800
provider-returned trades from this site's launch through the fixed cutoff
00:28:06 UTC. The first is September 12 at 03:23:25.866 UTC; the last is September
13 at 00:27:59.480 UTC. The preceding prelaunch trade is separate context, not a
launch-instant quote. No points were interpolated or reconstructed from a current
price. The normalized JSON is also published as
`https://pool.zclthesis.com/api/prices/zcl-usdt-launch-trades.json`.

Exact price and quantity strings, provider trade IDs, timestamps, retrieval times,
requests, raw responses and SHA-256 hashes are preserved. Deduplicate trades by
provider ID: different trades can share a timestamp and have different prices.
Within-millisecond execution order is not independently established; do not infer
a candle's close from an arbitrary ID/timestamp tie-break. Do not combine polling
observations with trade records as though they were the same kind of event.

`manifest.json` pins the normalized file. `coverage-verification.json` checks
pagination past the final record, offset behavior and the last trade using a
separate descending query. This establishes the returned API interval, not an
independent audit that the exchange reports every execution or retains all older
history. `official-client-source.json` pins the primary client documentation:
[NonKYC's public Python API client](https://github.com/NonKYCExchange/NonKycPythonApiClient/blob/3905051ab23bf586f4e05f30a5764d8f2bdca8e9/nonkyc.py).

Both sources quote USDT per ZCL. These are exchange prices, separate from the
CoinGecko USD market-cap launch reference on the thesis homepage.

The volume collector was deployed September 13, 2026 UTC from source commit
`7cccf18` on the existing pool VM. Its first verified live export held 936 trades,
4,882.7379 ZCL and 1,677.913029886 USDT. An independent read-only SQLite audit
recomputed both totals from every stored execution and passed the integrity check.
Automatic timer checkpoints advanced on subsequent minutes. No new VM was added.

## Observed trade volume

`deploy/zcl/record-volume.py` collects actual public `getTrades` executions for
ZCL/USDT each UTC minute at second 15. It imports the hash-pinned 800-trade backfill
above once, then fills from its last verified cutoff to the present, including
outages. It never adds rolling 24-hour ticker snapshots together. Each execution
contributes its exact quantity in ZCL and `quantity × price` in USDT once, keyed by
NonKYC's trade ID. Matching overlaps do not increase volume; changed data for an
existing ID fails the transaction for review.

Its private database is `/var/lib/zcl-prices/volume.sqlite3`. Raw normalized trades,
exact decimal aggregates, verified query intervals, and attempt outcomes/page
hashes are retained indefinitely. The public endpoint is
`https://pool.zclthesis.com/api/prices/zcl-usdt-volume.json`: since-launch totals,
execution count, first/last trade times, collection freshness, and at most 168
hourly UTC buckets. Base volume is ZCL; quote volume is USDT, not a USD conversion.
The all-time totals survive eviction of old hours from the public chart window.

The cutoff is ten seconds behind the collection attempt. Fixed ascending queries
request up to 1,000 trades per page and must exhaust pagination before advancing
coverage. The normal interval is bounded to 12 hours with five minutes of overlap;
a maximum of eight pages and a 30-second overall network deadline bound each run.
If a page budget is exhausted, the next attempt halves its interval; successful
small responses grow it again, so a temporarily busy interval does not permanently
slow recovery. Whole-second overlap preserves progress even at the one-second
minimum. The collector rejects reversed/duplicate page IDs, inconsistent timestamps,
wrong markets, malformed numeric values, and changes to stored executions. TLS
verification and a 512 KiB WebSocket response limit are supplied by
`python3-websockets`; no exchange credentials are used.

Coverage means that the exchange's returned pages were exhausted for that query
interval. It does not independently establish that the exchange reports all trades,
that historical retention is complete, or that reported volume is organic. A
`covered: false` bucket is missing coverage, even if it has some observed trades;
it must not be displayed as an observed zero. A covered empty hour really has no
returned trades. The launch hour starts at the site's exact launch time, and the
current hour is in progress through `checkedThrough`, not a completed full hour.
Only contiguous coverage from launch with a successful recent query is `live`.
Fetch failures retain previously observed trades and publish `stale`; holes are
`partial`. Clients also need to age `checkedThrough` if the collector stops entirely.

Three daily SQLite backups are retained in `/var/lib/zcl-prices/backups/volume-*.sqlite3`.
They are local online backups on the same disk, not off-site disaster recovery.
The volume process shares the existing VM and `zcl-prices` account; it adds no new
GCP service or instance. The price-observation process and its database are separate.

Deployment on the existing Debian pool VM, after copying this repository's files:

```sh
sudo apt-get install --yes --no-install-recommends python3-websockets
sudo install -m 0644 deploy/zcl/record-volume.py /opt/zcl-prices/record-volume.py
sudo install -m 0644 data/price-history/nonkyc-20260913/normalized-trades.json /opt/zcl-prices/normalized-trades.json
sudo install -m 0644 deploy/zcl/zcl-volume.service /etc/systemd/system/zcl-volume.service
sudo install -m 0644 deploy/zcl/zcl-volume.timer /etc/systemd/system/zcl-volume.timer
sudo systemctl daemon-reload
sudo systemctl start zcl-volume.service
sudo systemctl enable --now zcl-volume.timer
sudo systemctl status zcl-volume.timer --no-pager
```

The existing `zcl-prices` user must own `/var/lib/zcl-prices` (0700) and be able to
write `/var/lib/zcl-public/api/prices`. Caddy's explicit public-file allowlist must
include `/api/prices/zcl-usdt-volume.json` (see `deploy/zcl/Caddyfile`). Install the
updated allowlist and validate/reload Caddy as part of deployment. Never expose the
SQLite database, lock, or backups. The service uses a 45-second timeout, 96 MiB
memory cap, and 10% CPU quota. A stale/partial run deliberately exits nonzero; the
minute timer retries from retained coverage.

Validation:

```sh
python3 -m unittest discover -s deploy/zcl/tests -p 'test_record_volume.py' -v
```

The tests cover immutable seed totals, precise launch exclusion, Decimal
multiplication/sums, deduplication, conflicting-ID rollback, fixed pagination and
exhaustion, failed-fetch retry/recovery, minimum-window progress and regrowth,
missing versus zero-volume hours, hour boundaries, bounded chart retention,
backup integrity, and file permissions.
