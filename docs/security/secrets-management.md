# Secrets Management

**Author:** Nasser

## Secrets inventory

| Secret | Storage | Access |
|---|---|---|
| `APP_KEY` | `.env`, server filesystem only | Read by the framework at boot; never in the database, never returned by any API |
| Database password | `.env` | Same |
| SMTP password | `smtp_settings.password_encrypted`, `Crypt::encryptString()`-encrypted (keyed off `APP_KEY`) | Decrypted only in-memory, only inside `SmtpSettingsService`, only for the duration of building a mail transport — see `docs/security/smtp-security.md` |
| JWT signing key | tymon/jwt-auth config, derived from `.env` | Framework-internal |
| LDAP service-account password | `.env` (`LDAP_PASSWORD`) | Read by `LdapService` at request time; never logged, never stored elsewhere |

## What must never be in an ordinary application backup

`APP_KEY` and any other `.env`-held secret must **never** travel in an
ordinary, unprotected application backup. `scripts/backup.sh`
deliberately excludes `.env` entirely and archives only the database
dump and file storage — see `docs/backup/backup-guide.md`. A protected
backup of `.env`/`APP_KEY`, if required for disaster recovery, must go
through a separate, access-controlled process (e.g. a secrets vault or
an encrypted, restricted-access copy) — not documented further here
because no such vault is provisioned in this environment; this is a
process gap to close before a production rollout, not something this
review can close unilaterally.

## Local Super Admin account security

- **No default, seeded, or hard-coded production password.** The
  local Super Admin account requires an explicit password set through
  the platform's own user-management/reset flow — no seeder ships a
  guessable production credential.
- **No Quick Login / development authentication shortcut in
  production.** `AuthController::quickLoginEnabled()` gates the entire
  Quick Login panel and the `/auth/quick-login` endpoint behind
  `app()->environment('local') || config('app.debug')` — both false by
  default in a production `.env` (`APP_ENV=production`,
  `APP_DEBUG=false`). Re-verified this phase; a dedicated backend test
  (`test_quick_login_is_disabled_outside_local_or_debug`) asserts this
  directly.
- **Rate limiting**: `throttle:login` — 10 requests/minute keyed by
  `lower(username)+ip`, applied to both the standard login and
  quick-login endpoints (quick-login is itself production-disabled, so
  this limit's practical effect is on the standard login form and any
  residual non-production quick-login testing).
- **Session/token expiry**: JWT tokens expire per `jwt.php` config
  (framework default TTL unless overridden); no indefinitely-lived
  session token is issued.
- **Full audit logging**: every login, quick-login, and logout is
  recorded in `audit_logs` with user id, role, department id, IP
  address, and user agent.

### Confirmed gap

No progressive-delay/exponential-backoff lockout beyond the flat
per-minute rate limit, and no MFA integration point currently exists.
Both are reasonable hardening steps for a future phase but are out of
this phase's explicit scope (MFA is listed as an "optional approved
integration point," not a requirement) and are recorded here rather
than silently omitted.

## Encryption mechanism

`Illuminate\Support\Facades\Crypt` (`encryptString()`/
`decryptString()`) is the platform's single approved application
encryption mechanism, keyed off `APP_KEY` (AES-256-CBC with an HMAC,
per Laravel's default cipher). Used exclusively, currently, for the
SMTP password. No custom/homegrown encryption exists anywhere in the
codebase.

## Key rotation

`APP_KEY` rotation is a manual, high-impact operation (rotating it
without re-encrypting existing `Crypt::` ciphertext, including the
stored SMTP password, would make that ciphertext undecryptable) and is
not automated by any script in this repository. A documented rotation
procedure (decrypt-under-old-key → re-encrypt-under-new-key for every
`Crypt::`-encrypted column, in a single transaction, before switching
`APP_KEY` in the running environment) should be written and tested
before this is done in production — not yet done in this phase.

## What is explicitly never exposed

Per `docs/security/smtp-security.md`, the SMTP password: never
returned by any API response (including after saving), never in a
browser network response, never in an API Resource, never in
application logs, never in an audit-log old/new value, never in an
exception message, never in an export, never in a health-check
response, and never in a CI artifact. Verified by a dedicated PHPUnit
test asserting the raw response body of every SMTP-settings endpoint
never contains the plaintext password.
