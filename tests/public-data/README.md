# Public readiness checks

Run the browser status tests without network access or a browser:

```sh
node --test tests/public-data/status.test.mjs
```

The fake clock and response fixtures check loading, current admission holds,
unverifiable observations, eight-second request/body timeouts, independent
three-minute expiry, suspended-page recovery, and late responses. They also
check that node failure does not discard independently available mined totals.
No test starts a miner or changes pool admission.

The existing Python guard tests cover the richlist publisher's daemon-readiness
validation, including live/cached finalization compatibility:

```sh
python3 tests/public-data/readiness.py
```
