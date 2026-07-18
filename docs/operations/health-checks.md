# Health Checks

**Author:** Nasser

## Two tiers exist today (not three)

An earlier specification described three tiers: a public liveness
endpoint, a restricted readiness endpoint, and restricted detailed
diagnostics. **This platform implements two, not three**: the
diagnostics an earlier spec wanted separated into their own restricted
tier are currently merged into the same restricted readiness endpoint.
This is an honestly-documented shape difference, not a claim of full
compliance with the three-tier design.

## Public liveness — `GET /up`

Laravel's built-in health route (`bootstrap/app.php`,
`health: '/up'`). Confirms only that the application booted and can
respond — no dependency (database, cache, queue, storage) is actually
exercised. Returns 200 with no meaningful body. **Never exposes**
hostnames, credentials, paths, stack traces, dependency versions, or
internal network details — it has nothing to expose; it does not
touch any of those systems.

## Restricted readiness + diagnostics — `GET /api/v1/admin/health`

Gated by `role:super-admin` (same middleware group as every other
admin endpoint). `App\Http\Controllers\Api\Admin\HealthController::
readiness()` actually exercises each dependency:

| Check | What it does | Reported fields |
|---|---|---|
| `database` | `DB::select('select 1')` | `status` |
| `cache` | Write/read/delete a random key with a 5s TTL | `status` |
| `queue` | Counts rows in `jobs` and `failed_jobs` (last 24h) — a dependency-free proxy for "queue tables reachable" without dispatching a real job | `status`, `pending_jobs`, `failed_last_24h` |
| `storage` | Write/read/delete a file on the `private` disk | `status` |
| `scheduler` | Reports how long ago `compliance:process-sla` last ran, based on its own tracked state | `status`, freshness |

Response: `{"success": true, "status": "ok"|"degraded", "checked_at":
..., "checks": {...}}`, HTTP 200 if every check passes, **503** if
any check fails. On failure, each check reports a generic message
(e.g. "Database connection failed.") — never the underlying
exception message, host, or credential, so a failure response cannot
leak infrastructure detail to whoever is looking at it (even though
this endpoint is already Super-Admin-only).

## Not implemented

SMTP connectivity status and Active Directory connectivity status are
**not** part of this health endpoint's checks today, despite being
requested in an earlier specification (both would need to be
reasonably fast/non-blocking to fit a health-check's expected response
time, and neither is currently wired in). Disk-space-remaining,
current build/migration-status, and critical-configuration-status
checks are also not part of this endpoint. This is a real gap, not an
oversight to be silently omitted from this document.

## Verification performed this phase

`GET /up` confirmed to return 200 against the running dev server.
`GET /api/v1/admin/health` has a dedicated test
(`SecurityHardeningTest::test_readiness_health_check_reports_each_
component_and_is_super_admin_only`) that asserts: 403 for a non-Super-
Admin, 503 with the correct JSON structure before the scheduler has
ever run, and 200 with `checks.scheduler.status === 'ok'` after
seeding scheduler state — passing as part of the 171/171 backend
suite.
