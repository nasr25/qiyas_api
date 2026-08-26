# Disaster Recovery

**Author:** Nasser

Each scenario below is **documented, not drilled as a live simulation**
in this environment, except where explicitly marked otherwise — this
session has one sandbox environment, not a set of independently
failable infrastructure components to actually simulate failing.
Backup/restore (a genuine sub-case of several of these scenarios) was
actually drilled — see `docs/backup/restore-guide.md`.

| Scenario | Detection | Immediate response | Recovery procedure | Validation | Escalation |
|---|---|---|---|---|---|
| Application server failure | `/up` fails; monitoring/uptime check alerts | Confirm app pool/IIS state; check `laravel.log` for a boot exception | Restart the app pool/server; if the host itself is lost, redeploy the last release artifact to a replacement host — see `docs/deployment/release-process.md` | `/up` returns 200; `GET /api/v1/admin/health` returns 200; manual login | Ops on-call |
| Database server failure | `GET /api/v1/admin/health` reports `database: fail`; app-wide errors | Confirm MySQL service state on the DB host | Restart MySQL; if data is lost, restore from the most recent backup — see `docs/backup/restore-guide.md` | Row-count/functional checklist from the restore guide | Ops on-call, DBA if available |
| Storage unavailable | `GET /api/v1/admin/health` reports `storage: fail`; evidence upload/download failures | Confirm disk mount/permissions on the app server | Restore filesystem access; if data is lost, restore evidence/branding storage from the most recent backup | Spot-check a known evidence file download; branding renders correctly | Ops on-call |
| Queue worker stopped | `GET /api/v1/admin/health` reports growing `failed_last_24h` or `pending_jobs`; emails stop sending | Check the NSSM service state | `nssm start QiyasQueueWorker` — see `docs/operations/queue-and-scheduler.md` | Queue processes the backlog; `queue:failed` list reviewed | Ops on-call |
| Scheduler stopped | `GET /api/v1/admin/health` reports `scheduler: fail` (stale last-run); SLA warnings stop firing | Check the Windows Task Scheduler task state | Re-enable the task | `compliance:process-sla` next run updates the freshness check | Ops on-call |
| SMTP unavailable | Rising `failed` entries in `GET /admin/email-logs`; SMTP "Test Connection" fails | Confirm the relay/host is reachable from the app server | Fix connectivity or switch to a working relay via the Settings page; queue holds emails for retry rather than dropping them (`retry_count`/`retry_delay`) | "Test Connection" succeeds; a real test email delivers | Ops on-call |
| AD unavailable | AD-backed logins fail; local logins unaffected | Confirm the domain controller is reachable | Fix connectivity; the platform continues to serve local-account logins throughout — AD is not a single point of failure for login overall | An AD-backed test login succeeds | Ops on-call, AD admin |
| Corrupted deployment | Health checks fail immediately after a deploy; smoke tests fail | Do not attempt to patch forward | Roll back to the previous release artifact — see `docs/deployment/rollback.md` | Full post-rollback checklist | Ops on-call, release owner |
| Failed migration | `php artisan migrate` errors mid-run | Stop the deployment; do not continue to the next step | Restore the database from the pre-deployment backup (never attempt a blind programmatic rollback of a partially-applied migration) | `migrate:status` confirms expected state; functional checklist | Ops on-call, release owner |
| Failed release | Deployment pipeline stage fails | Halt the pipeline (the current CI pipeline already halts on any failing stage) | Do not deploy the failed artifact; fix and re-run from the failed stage | CI passes green | Release owner |
| Certificate expiration | Browser TLS warnings; monitoring alert on cert expiry (if configured) | Confirm which certificate expired (IIS binding) | Renew and rebind the certificate | HTTPS access confirmed clean | Ops on-call |
| Disk-space exhaustion | `storage: fail` on health check; write errors in logs | Identify the largest consumers (evidence storage, logs, backups) | Clear old backups per retention policy; rotate/compress logs; expand disk if needed | `storage` check passes again | Ops on-call |
| Log-volume growth | `laravel.log`/queue-worker logs growing unbounded | Monitor disk usage on the log volume | Rotate/archive logs; no built-in log rotation is configured by this repository — this must be set up at the OS/deployment level | Log volume stabilizes | Ops on-call |
| Cache failure | `GET /api/v1/admin/health` reports `cache: fail` | Confirm the configured cache store (file/database, per `.env`) is reachable/writable | Fix the underlying store; the application degrades but does not hard-fail on a cache miss for most reads (cache is a performance optimization, not a hard dependency, for the paths reviewed) | `cache` check passes again | Ops on-call |
| Configuration corruption | Unexpected application behavior after a config change; `config:cache` produces errors | `php artisan config:clear` to bypass a bad cached config | Fix the underlying `.env`/config file; re-run `config:cache` | Application behaves as expected; health checks pass | Ops on-call |
| Branding-asset corruption | A logo fails to render; the active asset's file is missing/corrupted on disk | Check `GET /admin/branding/{type}` for version history | Use "Restore" on a previous known-good version — the versioning system means a corrupted active file never destroys the prior version's history | The restored version renders correctly across AR/EN and light/dark | Super Admin |

## Backup/restore as the common recovery mechanism

Database-server failure, storage-unavailable, and corrupted-deployment
scenarios all ultimately rely on the same restore procedure —
`docs/backup/restore-guide.md` records a real, executed drill of that
procedure, not just a documented plan.

## What is genuinely untested

Every "detection" and "immediate response" column above describes
intended behavior based on code review (the health-check endpoint,
the queue/scheduler design, the branding versioning system), not a
live-fire simulation of an actual server crash, a killed MySQL
process, or a severed network link. No chaos-engineering-style drill
was performed in this environment.
