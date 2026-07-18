# Troubleshooting

**Author:** Nasser

## Application won't respond / `GET /up` fails

Check the IIS app pool is running (`Get-WebAppPoolState`), check
`storage/logs/laravel.log` for a boot-time exception, confirm `.env`
is present and readable by the app pool identity, confirm the database
is reachable from the app server.

## `GET /api/v1/admin/health` reports 503

Check `checks.*.status` for which component failed:

- `database`: connectivity/credentials — check `DB_*` in `.env` and
  that MySQL is running and reachable.
- `cache`: the configured cache store is unreachable/unwritable.
- `queue`: the `jobs`/`failed_jobs` tables are unreachable — usually a
  database issue, not a queue-worker-specific one (the check reads the
  tables directly; it does not require the worker process itself to
  be running).
- `storage`: the `private` disk is not writable — check filesystem
  permissions for the app pool identity on `storage/app/private`.
- `scheduler`: `compliance:process-sla` hasn't run recently — check
  the Windows Task Scheduler task `Qiyas Laravel Scheduler` is enabled
  and firing every minute (see `docs/operations/queue-and-scheduler.md`).

## Emails are not being delivered

1. `GET /admin/email-logs` — check the status/error for the specific
   message.
2. `GET /admin/settings` → Email Settings tab — confirm SMTP is
   enabled and the "Password configured" status is correct.
3. Use "Test Connection" on the SMTP tab to isolate whether the
   problem is connectivity/credentials versus something else.
4. If a queued notification is failing but a "Test Connection" from
   the UI succeeds, the queue worker may be running with a **stale**
   SMTP configuration — run `php artisan queue:restart` (see
   `docs/administration/smtp-settings.md`, "Queue-worker refresh").
5. Check `storage/logs/queue-worker-error.log`.

## A user can't log in

- **Local account**: check the account is active, check for recent
  failed-login rate-limiting (10/min per username+IP), check
  `audit_logs` for the login attempt's recorded outcome.
- **AD account**: AD integration has confirmed gaps — see
  `docs/security/active-directory.md`. In particular, a disabled or
  expired AD account currently is **not** rejected by account status
  (only a bind-credential failure is caught); if a supposedly-disabled
  AD user can still log in, that is the known gap, not a new defect.
- **Quick Login not visible**: this is correct, expected behavior in
  any environment where `APP_ENV` isn't `local` and `APP_DEBUG` isn't
  `true` — Quick Login is deliberately production-disabled. See
  `docs/security/secrets-management.md`.

## An import (XLSX) fails or produces unexpected rows

`GET /programs/{program}/requirements-imports` lists past import
attempts; download the error report for row-level detail. Confirm the
file is under the row cap (`MAX_ROWS=5000`) and the upload size limit,
and that it is not a macro-enabled workbook (`.xlsm` renamed `.xlsx`
is rejected — see `docs/security/file-security.md`).

## Branding upload is rejected

Check the shown error message — the platform rejects (rather than
silently degrading) a wrong-content-for-extension file, an oversized
file, an oversized-pixel-count image, or an SVG that could not be
safely sanitized. See `docs/security/file-security.md` for the exact
validation chain; a rejection here is very likely the validation
working correctly, not a bug.

## Playwright E2E tests won't run / fail immediately

The safety preflight in `tests/e2e/helpers/env.ts` refuses to run
against anything that isn't a recognized local host or whose
`E2E_DB_NAME_HINT` doesn't contain "e2e"/"test" — this is intentional.
See `docs/testing/playwright-guide.md` for the exact isolated-
environment startup sequence; a common cause of failure is simply not
having started the isolated E2E backend (port 8001) and frontend (port
5175) first.

## CI fails on the prohibited-reference scan

The scan output names the exact file and line. If it is a genuine new
reference to an automated development tool, remove it. If it is a
confirmed false positive (third-party package metadata, official
regulatory content, or a documented limitation disclosure), it needs a
new, narrowly-justified, hash-pinned allowlist entry — see
`docs/current-repository-cleanup.md`. Never widen an existing entry to
a filename/directory wildcard to make a new false positive disappear.
