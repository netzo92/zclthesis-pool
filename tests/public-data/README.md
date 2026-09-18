# Public readiness checks

Run the browser status tests without network access or a browser:

```sh
node --test tests/public-data/status.test.mjs tests/public-data/mined-display.test.mjs
```

The fake clock and response fixtures check loading, current admission holds,
unverifiable observations, eight-second request/body timeouts, independent
three-minute expiry, suspended-page recovery, and late responses. They also
check that node failure does not discard independently available mined totals.
The mined-display tests distinguish partial numeric lower bounds (including zero)
from complete zero totals and unavailable data in both languages.
No test starts a miner or changes pool admission.

The existing Python guard tests cover the richlist publisher's daemon-readiness
validation, including live/cached finalization compatibility:

```sh
python3 tests/public-data/readiness.py
```

RPC authentication tests execute the synchronization script with synthetic
credentials and mocked SQL. They verify the single-coin update boundary,
idempotent values, cookie rotation/config fallback, and failure handling without
calling a node, database, or production timer:

```sh
python3 -m unittest discover -s deploy/zcl/tests -p test_sync_rpc_cookie.py -v
```

Timer activation itself must be checked after an authorized deployment: confirm
the first service invocation, its successful result, and a finite next trigger
after completion. Restarting the real timer schedules a database update; local
tests do not trigger that job.
