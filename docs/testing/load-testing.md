# Load Testing

**Author:** Nasser

## Tooling

**k6 only** — Playwright is never used as a load generator; it is a
functional/browser-testing tool (see `docs/testing/playwright-guide.md`).
This separation is deliberate: a browser-driving tool does not scale
to hundreds of concurrent simulated users the way a headless HTTP load
tool does, and mixing the two would produce misleading results.

`tests/load/smoke.js` is the test script. Installed via `brew install
k6` in this environment (k6 was not present at the start of this
phase).

## What was actually run

A **smoke-scale** test only: 10 virtual users, 30 seconds, against the
**local dev backend** (`http://localhost:8000`). Logs in once in a
`setup()` phase (not per-iteration — see below), then each iteration
hits: `GET /up`, `GET /api/v1/branding`, `GET /api/v1/programs`, `GET
/api/v1/admin/settings`.

### Design note: login once, not per-iteration

An initial draft of this script called the quick-login endpoint on
every iteration. The login endpoint is deliberately rate-limited
(`throttle:login`, 10/min — see
`docs/security/security-hardening.md`) as a brute-force protection,
and a load test must not need to bypass that protection to run
cleanly. Real traffic also logs in once per session, not on every
request. Rewritten to authenticate once in k6's `setup()` function and
reuse the resulting token across all iterations — both more realistic
and avoiding the rate limit entirely.

## Actual measured results (real, not estimated)

```
checks_total: 1092 (100% succeeded, 0% failed)
http_req_duration: avg=29.12ms  med=18.35ms  p(90)=34.74ms  p(95)=113.85ms  p(99)=185.39ms  max=228.72ms
http_req_failed: 0.00% (0 / 1093)
http_reqs: 1093 (35.22/s)
iterations: 273
vus_max: 10
data_received: 4.4 MB   data_sent: 344 kB
```

Thresholds set and passed: `http_req_failed rate < 1%` ✓,
`http_req_duration p(95) < 800ms` ✓ (113.85ms), `p(99) < 1500ms` ✓
(185.39ms).

## What was NOT run, and why

An earlier specification called for tiered load tests at 10/50/100/
300/500 concurrent users, with a 1000-user tier requiring approved
infrastructure capacity, an isolated environment, and explicit
stakeholder approval. **Only the 10-VU smoke tier was run.** The
50/100/300/500/1000 tiers were not executed because:

- This environment is a single local development machine, not an
  isolated, capacity-approved load-testing environment.
- No stakeholder approval process exists to authorize a higher-tier
  test in this session.
- Running a meaningfully higher concurrent load against a local dev
  MySQL/PHP-built-in-server setup would not produce results
  representative of real production infrastructure sizing anyway —
  the bottleneck characteristics of `php artisan serve` (a
  single-threaded development server) are not representative of a
  production IIS + PHP-FPM/FastCGI deployment.

**This platform's capacity for 50, 100, 300, 500, or 1000 concurrent
users is explicitly not claimed anywhere in this documentation set.**
Any such claim would require running the higher tiers against
approved, production-representative infrastructure first.

## Metrics k6 reports that a fuller test would also need to correlate

Response time (avg/median/p90/p95/p99 — captured above), error rate
(captured), throughput (captured). **Not captured** in this smoke run,
and requiring server-side instrumentation this environment does not
have configured: CPU/memory for app and DB, DB connection count, slow
query log analysis, lock waits/deadlocks, queue depth/processing time
under load, disk I/O, network utilization, storage latency, IIS
application-pool recycling behavior, PHP process count. A fuller load
test campaign should correlate k6's client-side metrics with
server-side monitoring — not yet wired up in this environment (see
`docs/operations/monitoring.md`).

## Running it yourself

```bash
brew install k6   # or the equivalent for your platform
LOAD_TEST_BASE_URL=http://localhost:8000 LOAD_TEST_USERNAME=superadmin \
  k6 run tests/load/smoke.js
```

Adjust `options.vus`/`options.duration` in `tests/load/smoke.js` for a
larger run — only against an environment and with an approval level
appropriate to the tier being tested, per the constraints above.
