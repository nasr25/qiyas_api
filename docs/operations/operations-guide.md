# Operations Guide

**Author:** Nasser

Consolidates and updates the earlier `docs/qiyas-operational-runbook.md`
for the current four-program, Phase-8 platform. Audience: IT
operations staff and each program's technical contact.

## Starting and stopping

| Component | Start | Stop |
|---|---|---|
| Application (IIS) | `Start-WebAppPool <pool>` | `Stop-WebAppPool <pool>` / `iisreset` |
| Scheduler | Windows Task Scheduler task `Qiyas Laravel Scheduler` runs `php artisan schedule:run` every minute — enable/disable the task | Disable the task |
| Queue worker | `nssm start QiyasQueueWorker` (or the configured service name) | `nssm stop QiyasQueueWorker` |

See `docs/operations/queue-and-scheduler.md` for full detail on both
background processes.

## Local development equivalent

```bash
composer dev   # runs serve + queue:listen + pail (log tail) + vite, concurrently
```

## Routine checks

- **Health**: `GET /up` (public liveness) and `GET
  /api/v1/admin/health` (Super-Admin-only readiness/diagnostics) — see
  `docs/operations/health-checks.md`.
- **Queue depth / failures**: the health endpoint reports
  `checks.queue.pending_jobs` and `checks.queue.failed_last_24h`
  directly; `php artisan queue:monitor` and `php artisan queue:failed`
  for detail.
- **Email delivery**: `GET /admin/email-logs` (Super Admin UI) shows
  every send attempt, status, and error.
- **Audit trail**: `GET /admin/audit-logs`.

## Common operational tasks

| Task | How |
|---|---|
| Retry a failed queue job | `php artisan queue:retry {id}` (or `all`) |
| Discard a failed job | `php artisan queue:forget {id}` |
| Investigate an import failure | `GET /programs/{program}/requirements-imports`, download the error report |
| Rotate/reload SMTP config for a running worker | `php artisan queue:restart` after a Super Admin SMTP change — see `docs/administration/smtp-settings.md` |
| Take a backup | `scripts/backup.sh` — see `docs/backup/backup-guide.md` |
| Restore a backup | `scripts/restore.sh` — see `docs/backup/restore-guide.md` |

## Storage

Never manually delete files under `storage/app/private/evidence/` —
submitted evidence is immutable by design; if a file must be removed
for a legitimate reason (e.g. a legal/retention requirement), do it
through an approved administrative process that also records the
action in the audit log, not a direct filesystem delete. The health
endpoint's `checks.storage` reports whether the private disk is
read/write-healthy.

## Log locations

`storage/logs/laravel.log` (application log), `storage/logs/queue-
worker.log` / `queue-worker-error.log` (queue worker stdout/stderr,
per the NSSM service configuration — see
`docs/operations/queue-and-scheduler.md`). The audit log and email log
are database-backed, viewed through the admin UI/API, not flat files.

## Active Directory operational note

AD integration has confirmed gaps (no account-status validation, no
connection timeout, no multi-DC failover) documented in
`docs/security/active-directory.md` — this has not been exercised
against a real domain controller in this environment. If AD login
issues are reported in a real deployment, check those gaps first.

## Escalation

Escalation contacts and paths are environment-specific and must be
filled in by the deploying organization before pilot use — no
placeholder contact information is invented here.
