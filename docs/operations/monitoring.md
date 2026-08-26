# Monitoring

**Author:** Nasser

## What exists today

**Application log**: `storage/logs/laravel.log` — standard Laravel
logging (exceptions, framework/application `Log::` calls). No
structured/JSON log formatter is configured; entries are the
framework's default single-line-plus-stack-trace text format.

**Audit log** (`audit_logs` table, viewable at `GET
/admin/audit-logs`): `user_id`, `role`, `department_id`,
`compliance_program_id`, `action`, `model_type`, `model_id`,
`old_values`, `new_values`, `description`, `ip_address`, `user_agent`,
`created_at`. Every significant action is recorded here: login, quick
login (production-disabled), logout, user/role/permission/settings
changes, branding/SMTP/email-template changes (secrets redacted — see
`docs/security/smtp-security.md`), standard import and assignment,
document upload/edit/submit/approve/reject/download, extension
request/approve/reject, comments, and SMTP test-connection attempts.

**Email log** (`email_logs` table, `GET /admin/email-logs`): every
outbound email attempt with recipient, subject, body, status (sent/
failed/pending), error, and timestamps — populated by
`MessageSending`/`MessageSent` event listeners and a `Queue::failing`
hook.

**Health endpoint** (`GET /api/v1/admin/health`): per-component
pass/fail for database, cache, queue, storage, and scheduler
freshness — see `docs/operations/health-checks.md`.

## Gap against a fuller structured-logging specification

An earlier specification for this phase described a richer structured
log schema (fields: `timestamp`, `severity`, `event_type`,
`correlation_id`, `user_id`, `program_id`, `department_id`,
`source_ip`, `route`, `http_method`, `result`, `entity_type`,
`entity_id`, `application_version`) covering an extensive event list
including authorization-denial events, file-download-denial/upload-
rejection, queue/scheduler/health-check failures, and application
exceptions. **This structured schema does not exist today** — the
current `audit_logs` table covers most of the same underlying events
but with a narrower, differently-shaped column set (no `severity`,
`event_type` enum, `correlation_id`, `route`, `http_method`, `result`,
or `application_version`), and does not log authorization-denial
events, download-denial/upload-rejection events, or infrastructure
failures (queue/scheduler/health-check) at all — those currently
surface only as an HTTP status code to the client and, for exceptions,
an entry in `laravel.log`.

This is a real, honestly-documented gap: closing it would mean adding
a dedicated structured-event logging layer (likely a new table or a
JSON-formatted log channel) rather than extending `audit_logs` in
place, since the two serve different purposes (audit = "what change
did a user make," structured security logging = "what happened,
including denials and failures, for SIEM correlation").

## What is never logged, by design

Passwords, tokens, SMTP secrets (the encrypted column and the
decrypted value), session identifiers, full evidence file contents,
AD credentials, and encryption keys are never written to any log —
verified directly for the SMTP path (`docs/security/smtp-security.md`)
and by code review for the others (no call site logs a raw
password/token/credential anywhere in the codebase).

## Recommendation

Before a production rollout that requires SIEM correlation (see
`docs/operations/siem-integration.md`), implement the structured
event-logging layer described above, at minimum for: authentication
failures, authorization denials, file-download denials, and
infrastructure health-check failures — these are the categories a
SIEM/SOC would most need for real-time detection that `audit_logs`
does not currently surface.
