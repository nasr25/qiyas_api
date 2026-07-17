# Windows Task Scheduler and Queue Worker Deployment

The platform's deployment target is on-premises Windows Server / IIS (see
`README.md`). Two background processes are required for Phase 2 to function
correctly in production — neither is optional, and neither can be
substituted by "a user opening a page":

1. **The Laravel scheduler** (`php artisan schedule:run`) — must run every
   minute. It is what actually fires `compliance:process-sla` (every 30
   minutes) and the pre-existing `qiyas:mark-overdue`/`qiyas:send-reminders`
   commands (see `routes/console.php`).
2. **A queue worker** (`php artisan queue:work`) — must run continuously.
   Every notification dispatched by `NotificationService` implements
   `ShouldQueue`; without a worker running, emails and in-app notifications
   are created as `jobs` table rows and never actually sent.

## Scheduler: Windows Task Scheduler configuration

IIS does not run background cron-like processes itself, so
`schedule:run` must be triggered externally. Configure a Windows Task
Scheduler task:

1. Open **Task Scheduler** → **Create Task**.
2. **General** tab: name it `Qiyas Laravel Scheduler`; run whether the user
   is logged on or not; run with the same service account IIS/the app pool
   uses (so file permissions on `storage/` match).
3. **Triggers** tab: **New** → *Daily*, recur every 1 day, then check
   **Repeat task every: 1 minute** for a duration of **Indefinitely**.
4. **Actions** tab: **New** → *Start a program*:
   - Program/script: full path to `php.exe` (e.g.
     `C:\xampp\php\php.exe` or the production PHP install path).
   - Add arguments: `artisan schedule:run`
   - Start in: the Laravel project root, e.g.
     `C:\inetpub\wwwroot\qiyas-api`
5. **Conditions**/**Settings** tabs: uncheck "Stop the task if it runs
   longer than 3 days" is fine as-is (each run exits immediately once
   scheduled commands finish — `schedule:run` is designed to return quickly
   every minute, not to run continuously).

Verify with:

```powershell
cd C:\inetpub\wwwroot\qiyas-api
php artisan schedule:list
```

This lists every scheduled command and its next run time, including
`compliance:process-sla`.

## Queue worker: Windows Service (NSSM) configuration

A queue worker is a long-running process — on Windows Server this should
run as a proper Windows Service, not a scheduled task, so it restarts
automatically on crash or server reboot. The
[NSSM](https://nssm.cc/) (Non-Sucking Service Manager) tool is the standard
way to wrap a long-running console command as a Windows Service:

1. Download NSSM, place `nssm.exe` somewhere on `PATH`.
2. Install the service:
   ```powershell
   nssm install QiyasQueueWorker "C:\xampp\php\php.exe" "artisan queue:work --sleep=3 --tries=3 --max-time=3600"
   nssm set QiyasQueueWorker AppDirectory "C:\inetpub\wwwroot\qiyas-api"
   nssm set QiyasQueueWorker AppStdout "C:\inetpub\wwwroot\qiyas-api\storage\logs\queue-worker.log"
   nssm set QiyasQueueWorker AppStderr "C:\inetpub\wwwroot\qiyas-api\storage\logs\queue-worker-error.log"
   nssm set QiyasQueueWorker Start SERVICE_AUTO_START
   nssm start QiyasQueueWorker
   ```
3. `--max-time=3600` makes the worker process exit cleanly once an hour;
   combined with `Start SERVICE_AUTO_START`, Windows' Service Control
   Manager restarts it immediately — this is the standard way to pick up
   deployed code changes without a manual restart step, since PHP workers
   otherwise cache the booted application in memory indefinitely.
4. Confirm it's running: `Get-Service QiyasQueueWorker` or check
   `services.msc`.

## Configuration

- `QUEUE_CONNECTION` in `.env` — the platform ships configured for the
  `database` driver (queued jobs stored in the `jobs` table), which needs
  no additional infrastructure (no Redis) and is appropriate for an
  on-premises single-server deployment. `queue:work` polls this table.
- Failed jobs land in `failed_jobs` (already migrated in Phase 1's base
  schema) — inspect with `php artisan queue:failed`, retry with
  `php artisan queue:retry all`.

## Operational checklist

- [ ] `php artisan schedule:list` shows `compliance:process-sla` with a
      sensible next-run time.
- [ ] Windows Task Scheduler task `Qiyas Laravel Scheduler` shows "Ready"
      / "Running" status and a recent "Last Run Result" of `0x0`.
- [ ] `Get-Service QiyasQueueWorker` shows `Running`.
- [ ] Trigger a real workflow action (e.g. assign a requirement) and
      confirm a row appears in `notification_logs` with `status = sent`
      within a minute or two.
- [ ] `storage/logs/queue-worker.log` has no repeating fatal errors.

## Local development equivalent

For local development (macOS/Linux, as used in this repository's dev
environment), the equivalent of the two Windows services is simply running
two terminals:

```bash
php artisan schedule:work   # foreground scheduler loop, dev-only convenience command
php artisan queue:work      # foreground queue worker
```

`composer dev` (see `composer.json`) already runs a queue listener
alongside `php artisan serve` and the Vite dev server for local
convenience — `schedule:work` is the one additional process needed to
exercise `compliance:process-sla` locally without manually invoking it.
