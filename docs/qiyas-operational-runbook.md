# Qiyas — Operational Runbook

Audience: whoever operates the platform day-to-day in production (IT
operations / the Program Manager's technical point of contact). Pairs with
`docs/qiyas-deployment-iis.md` (initial setup) and
`docs/windows-scheduler-and-queues.md` (background process detail).

## Start / stop

**Windows Server (production):**
- The web app runs under IIS — starting/stopping IIS (`iisreset`) or the
  specific application pool controls the API.
- The Laravel Scheduler runs as a Windows Task Scheduler task
  (`Qiyas Laravel Scheduler`, triggers `schedule:run` every minute) — see
  `docs/windows-scheduler-and-queues.md` for setup; to stop it, disable the
  scheduled task.
- The queue worker runs as a Windows Service via NSSM
  (`QiyasQueueWorker`) — stop with `nssm stop QiyasQueueWorker` or
  `Stop-Service QiyasQueueWorker`; start with `nssm start QiyasQueueWorker`.

**Local development:**
```
composer dev   # runs php artisan serve + queue:listen + pail + vite together
```

## Queue worker operations

- Check status: `Get-Service QiyasQueueWorker` (Windows) or `php artisan
  queue:monitor` for queue depth.
- Inspect pending jobs: `php artisan tinker` → `DB::table('jobs')->count()`,
  or query the protected health endpoint (`GET /api/v1/admin/health`,
  Super Admin only) which reports `checks.queue.pending_jobs` and
  `checks.queue.failed_last_24h` without exposing table contents.
- Restart after a deployment (workers cache the booted app in memory and
  will not pick up new code otherwise): `nssm restart QiyasQueueWorker`, or
  rely on the `--max-time=3600` flag already configured (see
  `docs/windows-scheduler-and-queues.md`) which makes the worker exit
  cleanly once an hour and the Windows Service Control Manager restart it
  automatically.

## Scheduler verification

```
php artisan schedule:list
```
Confirms `compliance:process-sla` (every 30 minutes) and the pre-existing
`qiyas:mark-overdue` / `qiyas:send-reminders` commands are registered with
sensible next-run times. The protected health endpoint's
`checks.scheduler` reports minutes since `compliance:process-sla` last
actually ran (a real heartbeat the command writes on every invocation, not
just whether the schedule is registered) — `status: "fail"` there means the
Windows Task Scheduler task has stopped firing, not that nothing happened to
report.

## Failed-job handling

```
php artisan queue:failed              # list
php artisan queue:retry all           # retry every failed job
php artisan queue:retry {id}          # retry one
php artisan queue:forget {id}         # discard one permanently
```
A failed notification job does not silently vanish — `NotificationLog`
already recorded the attempt with `status: failed` and the sanitized error
message (never the SMTP credentials) before the job failed, via
`AppServiceProvider::boot()`'s `Queue::failing()` listener.

## Email failure handling

1. Check `GET /api/v1/admin/email-logs` (Super Admin) for recent failures
   and their recorded error text.
2. Verify SMTP settings under `GET /api/v1/admin/settings/smtp`.
3. Use `POST /api/v1/admin/email-templates/{id}/test-send` to send one real
   test email to a known-good address without touching any real recipient's
   inbox or exposing the SMTP password in the response.
4. If SMTP itself is down, notifications still queue and the in-app
   notification (`database` channel) still delivers — a mail outage does
   not lose the underlying event, only delays the email.

## Import failure handling

1. `GET /api/v1/programs/QIYAS/requirements-imports` lists every
   `ImportLog` with its status (`validating`, `ready_for_confirmation`,
   `validation_failed`, `importing`, `completed`, `failed`).
2. For a `validation_failed` log, `GET
   .../requirements-import/{importLog}/error-report` downloads the
   bilingual per-row error XLSX.
3. A `failed` status at `confirm` time means the transaction rolled back
   completely — `php artisan compliance:verify-qiyas` can confirm no
   partial `Standard` rows were left behind (the "no partial import"
   guarantee is transactional, not something that needs manual cleanup).

## Storage troubleshooting

- Evidence files, imports, and error reports all live on the `private`
  disk (`storage/app/private` by default) — never inside `public/`.
- If uploads/downloads start failing with a generic 500: check the
  protected health endpoint's `checks.storage` (it performs a real
  write/read/delete cycle against the private disk); if that fails, check
  IIS application-pool identity has write permission on `storage/` and
  `bootstrap/cache/` (see `docs/qiyas-deployment-iis.md`).
- Never manually delete files under `storage/app/private/evidence/` — a
  file's row (`EvidenceFile`) is the source of truth for whether it's
  still referenced; removing the file without removing the row (or vice
  versa) is exactly the kind of inconsistency `compliance:verify-qiyas`
  cannot detect (it checks DB relationships, not filesystem existence) — if
  this ever happens, restore from backup rather than hand-editing.

## AD (Active Directory) authentication troubleshooting

*(Not verified against a real domain controller in this environment — see
`docs/qiyas-known-issues.md`. This is written from `LdapService`'s actual
code path and standard AD error semantics.)*

1. Confirm `LDAP_HOST`, `LDAP_BASE_DN`, `LDAP_USERNAME`/`LDAP_PASSWORD`
   (service/bind account) are set in `.env`.
2. A user login failure with a correct AD password most likely means the
   bind DN construction is wrong for your domain — `LdapService` builds
   `{username}@{domain-from-base-dn}` when the user didn't type a UPN
   (`user@domain`) themselves; if your AD expects a different bind format
   (e.g. `DOMAIN\username`), this needs adjusting in `LdapService::authenticate()`.
3. "LDAP search skipped: no service account configured" in the logs is
   informational, not an error — the Super Admin's "search AD to add a
   user" feature needs a bind/service account (`LDAP_USERNAME`/
   `LDAP_PASSWORD`); ordinary user login does not.
4. A disabled or locked AD account: the bind itself fails at the directory
   level (AD does not authenticate disabled/locked accounts), so this
   surfaces to the user as "invalid credentials" the same as a wrong
   password — by design, the platform does not tell an unauthenticated
   caller *why* a bind failed (would otherwise leak account-existence
   information).
5. `LDAP_USE_TLS=true` requires the domain controller's certificate to be
   trusted by the PHP process — a `ldap_start_tls()` failure is logged
   server-side (`Log::error('LDAP start_tls failed')`) but never surfaced
   to the end user.

## Log locations

- Application log: `storage/logs/laravel.log` (standard Laravel daily log).
- Queue worker log (Windows/NSSM): configured path in
  `docs/windows-scheduler-and-queues.md`, e.g.
  `storage/logs/queue-worker.log` / `queue-worker-error.log`.
- Audit log (business events, not technical errors): queryable via `GET
  /api/v1/admin/audit-logs`, not a file — see `docs/qiyas-role-permissions.md`
  for the full event list.
- Email delivery log: `GET /api/v1/admin/email-logs`.

## Health checks

- **Public liveness**: `GET /up` (Laravel's built-in health route,
  `bootstrap/app.php`) — confirms the app booted, nothing more, no
  authentication required.
- **Protected readiness**: `GET /api/v1/admin/health` (Super Admin JWT
  required) — actually exercises database, cache, queue table
  reachability, private-disk read/write, and the scheduler heartbeat, each
  reported independently; returns HTTP 503 if any component fails. Never
  returns hostnames, credentials, file paths, or stack traces — only a
  per-component `ok`/`fail` status and a couple of safe numeric metrics
  (pending job count, minutes since last scheduler run).

## Backup / restore / rollback

See `docs/qiyas-backup-and-recovery.md` for the full procedure. In brief:
`mysqldump` the database and archive `storage/app/private` on the same
schedule; restore by reversing both; roll back a bad deployment with
`php artisan migrate:rollback --step=N` for the specific migration batch
plus redeploying the previous code release.

## Escalation

- **Application/platform issues**: [Program technical point of contact —
  fill in for your deployment].
- **Infrastructure (IIS/Windows Server/network)**: [IT infrastructure team
  contact — fill in].
- **Active Directory**: [AD/identity team contact — fill in].
- **Database**: [DBA/infrastructure contact — fill in].

These are intentionally left as placeholders — this document does not know
your organization's actual on-call structure.
