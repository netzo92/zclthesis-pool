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
