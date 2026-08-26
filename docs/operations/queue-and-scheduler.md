# Queue and Scheduler

**Author:** Nasser

Consolidates and updates `docs/windows-scheduler-and-queues.md`. Two
background processes are required in every environment; neither is
optional.

## Scheduled commands (`routes/console.php`)

| Command | Schedule | Purpose |
|---|---|---|
| `qiyas:mark-overdue` | Daily 01:00 | Marks past-due documents/requirements overdue |
| `qiyas:send-reminders` | Daily 08:00 | Deadline reminder notifications |
| `compliance:process-sla` | Every 30 minutes, `withoutOverlapping()` | Detects SLA warnings/breaches across all programs; processed in `chunkById(200, ...)` batches for both SLA instances and overdue assignments, to bound memory on large datasets |

`withoutOverlapping()` on `compliance:process-sla` prevents two
overlapping runs if a single run takes longer than 30 minutes.

## Windows Task Scheduler setup

- Task name: `Qiyas Laravel Scheduler`.
- Trigger: Daily, recurring "Repeat task every: 1 minute," indefinitely.
- Action: run `php.exe` (e.g. `C:\xampp\php\php.exe`) with arguments
  `artisan schedule:run`, "Start in" set to the application root (e.g.
  `C:\inetpub\wwwroot\qiyas-api`).

Local dev equivalent: `php artisan schedule:work` (a long-running
process that ticks the scheduler every minute without needing a real
OS-level cron/Task Scheduler entry) — or `composer dev`, which starts
it alongside the app server and queue listener.

## Queue worker

`QUEUE_CONNECTION=database` — jobs live in the `jobs` table, failures
in `failed_jobs`. No Redis dependency.

### Windows service (NSSM)

```
nssm install QiyasQueueWorker "C:\xampp\php\php.exe" "artisan queue:work --sleep=3 --tries=3 --max-time=3600"
nssm set QiyasQueueWorker AppDirectory "C:\inetpub\wwwroot\qiyas-api"
nssm set QiyasQueueWorker AppStdout "C:\inetpub\wwwroot\qiyas-api\storage\logs\queue-worker.log"
nssm set QiyasQueueWorker AppStderr "C:\inetpub\wwwroot\qiyas-api\storage\logs\queue-worker-error.log"
nssm set QiyasQueueWorker Start SERVICE_AUTO_START
nssm start QiyasQueueWorker
```

`--max-time=3600` makes the worker exit cleanly once per hour; the
Windows Service Control Manager auto-restarts it, which is also how it
picks up freshly deployed code and configuration (including a new SMTP
configuration — see below) without a separate manual restart step
being strictly required, though `php artisan queue:restart` remains
the documented, deliberate signal to do so immediately after a change.

Local dev equivalent: `php artisan queue:work` in its own terminal (or
via `composer dev`, which runs `queue:listen`).

### No dependency on an interactive user session

Both the Task Scheduler entry and the NSSM service run independent of
any logged-in user session — this satisfies the requirement that
neither process depend on someone staying logged into the server.

## Queue-worker config refresh

A running worker process does not re-read application configuration
mid-run. `php artisan queue:restart` signals every worker to finish
its current job and exit; the service supervisor (NSSM) restarts it,
which re-boots the application (re-reading `.env`, re-applying the
current SMTP configuration via `AppServiceProvider::boot()`, etc.).
Run this explicitly after any production configuration change that
affects a queued job's behavior — most notably an SMTP settings
change, since a queued notification email uses whatever mail config
the worker booted with. See `docs/administration/smtp-settings.md`.

## Failed-job handling

`php artisan queue:failed` (list), `php artisan queue:retry {id}` (or
`all`), `php artisan queue:forget {id}` (discard). The health endpoint
(`GET /api/v1/admin/health`) reports `checks.queue.failed_last_24h` so
a growing failure count is visible without manually querying the
table.

## Outage recovery

If the queue worker service is stopped for a period, jobs simply
accumulate in the `jobs` table (nothing is lost) and process in order
once the worker restarts — no special recovery procedure beyond
restarting the service. If the scheduler task is disabled for a
period, `qiyas:mark-overdue`/`qiyas:send-reminders`/
`compliance:process-sla` simply do not run for that period; there is
no catch-up/backfill logic, so any SLA warning window that should have
fired during the outage will not fire retroactively — the next
scheduled run only evaluates current state, not missed historical
windows. This is a real, honestly-documented behavior, not a defect
introduced this phase.

## What was not tested

A real Windows Task Scheduler entry and a real NSSM service were not
actually created/exercised in this environment (no Windows host is
available) — the scheduler and queue worker were run this session via
their direct CLI equivalents (`php artisan schedule:work` /
`php artisan queue:work`, and `QUEUE_CONNECTION=sync` for the isolated
E2E environment, which processes jobs synchronously and needs no
worker at all) rather than through the Windows service wrappers
described above.
