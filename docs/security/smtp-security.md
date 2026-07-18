# SMTP Secret Security

**Author:** Nasser

## Source of truth

**A single, Super-Admin-managed database row** (`smtp_settings`
table) is the source of truth for SMTP configuration, with the
framework's `.env`-derived mail config used **only** as a fallback
when no active database row exists (e.g. a fresh install before any
Super Admin has configured SMTP). There is exactly one source of truth
at any point — never two conflicting SMTP configurations.

`SmtpSettingsService::applyToRuntimeConfig()`, called once from
`AppServiceProvider::boot()`, overrides Laravel's runtime
`mail.default`/`mail.mailers.smtp`/`mail.from` config when an active,
enabled row exists. Every existing `Mail::`/`Notification::` call site
in the codebase needed **zero** changes — only the resolved transport
configuration changes underneath them.

## Encryption at rest

The password column (`password_encrypted`) is encrypted via
`Illuminate\Support\Facades\Crypt::encryptString()`, keyed off
`APP_KEY`. It is decrypted **only** inside `SmtpSettingsService`, and
**only** for the duration of building a mail transport or running a
test connection — never cached in decrypted form, never returned, and
never logged.

## What the API never returns

Verified by `tests/Feature/Admin/PlatformAdministrationTest.php`
(`test_smtp_password_is_encrypted_at_rest_and_never_returned_by_the_api`)
and by a Playwright E2E test that inspects the raw network response:

- The plaintext password.
- The `password_encrypted` ciphertext column.
- Any password value in an API Resource, an audit-log old/new value,
  an exception message, an export, or a health-check response.

`GET /api/v1/admin/smtp-settings` returns only
`password_configured: true|false` and `password_last_changed_at` — the
frontend renders this as "Password configured" / "Password not
configured" and a last-changed date, and the password `<input>` field
is always initialized empty (write-only from the browser's
perspective; see `SettingsView.vue`'s `loadSmtp()`, which explicitly
overwrites any `password` key from the API response with `''`).

## Editing behavior

`PUT /api/v1/admin/smtp-settings` with an empty `password` field
**preserves the existing encrypted password** — only an explicitly
entered new value replaces it (`SmtpSettingsService::save()`: the
incoming password is applied via `SmtpSetting::setPassword()`, which
no-ops on an empty string). Verified by a dedicated test that saves a
password, then re-saves with the password field blank and a different
port, and confirms the original password still decrypts correctly.

## Test Connection

`POST /api/v1/admin/smtp-settings/test`:

- Validates all fields before connecting; uses a short connection
  timeout.
- Supports testing **unsaved** form values (so a Super Admin can
  verify a change before committing it) via explicit fields, or the
  currently saved password via `use_saved_password: true` when the
  password field is left blank.
- Never permanently stores an incomplete/untested configuration — the
  test uses `Transport::fromDsn()` to build a throwaway transport,
  never touching the `smtp_settings` row.
- Returns a **sanitized** result: `sanitizeError()` strips any
  `scheme://user:pass@host` credential pattern and any filesystem path
  from the exception message before it is ever returned or logged.
  Verified with a real fake SMTP server in Playwright: a wrong-password
  test asserts the returned error text never contains the password
  (the username, not a secret, may legitimately appear — the Super
  Admin just typed it).
- Every test attempt (success or failure) is audited via
  `AuditService::log()` **without** the password.

## Configuration change auditing

`setting_versions` records every SMTP field change. For **non-secret**
fields (host, port, encryption, timeouts, etc.), it stores old and new
values. For the **secret** field (password), it stores only a
`secret_action` enum — `configured` / `changed` / `removed` — **never**
an old or new value, and pairs with an `AuditService::log()` entry
(`smtp_settings.password_configured`/`_changed`) whose message never
contains the secret. Verified by a test that saves a password and
asserts the resulting `SettingVersion` row has `old_value`/`new_value`
both `null` and the audit log entry's serialized form never contains
the plaintext password.

## Cache behavior

The effective (decrypted, in-memory) config is cached under
`smtp_settings.effective` for 1 hour (`Cache::remember`) and
**immediately invalidated** (`Cache::forget()`) on every save — a
config change takes effect on the next mail send without waiting for
the cache TTL to expire.

## Queue-worker refresh

Because `applyToRuntimeConfig()` runs once at application boot, a
long-running queue worker process does not automatically pick up a new
SMTP configuration mid-run. The documented refresh mechanism is
Laravel's standard `php artisan queue:restart` (signals workers to
finish their current job and exit; a process supervisor — NSSM on
Windows, per `docs/operations/queue-and-scheduler.md` — restarts them,
re-booting the application and re-reading the current SMTP config).
**This is documented, not yet wired as an automatic trigger** on SMTP
save — a Super Admin (or the deployment pipeline) must run
`queue:restart` after a production SMTP change for a running worker to
pick it up immediately; until then, the worker continues using the
config it booted with. This is a real, honestly-documented gap.

## Rollback

Non-secret SMTP fields can be restored to a previous value by reading
`setting_versions` and re-saving the old values through the normal
`PUT` endpoint (no dedicated one-click "restore" button exists for
SMTP settings, unlike branding assets). Secret rollback is **not**
supported and must never be — there is no "previous password" to
restore to, by design; a Super Admin must re-enter the correct
password if a change needs to be undone.

## What was never tested

No test connection was run against a real, internet-facing SMTP relay
requiring a genuine trusted-CA TLS certificate — the Playwright suite
uses a local, plaintext, no-TLS fake SMTP server double under
`encryption=none`+`internal_relay_mode=true` (the platform's own
"approved trusted internal relay" exemption). Real-relay TLS
negotiation, an untrusted-certificate rejection path, and a real
relay's own connection-denial behavior were not exercised end-to-end
in this environment.
