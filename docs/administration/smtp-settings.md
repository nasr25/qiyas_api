# SMTP Settings Administration

**Author:** Nasser

Super Admin → Settings → Email Settings (`/admin/settings`). Full
security detail is in `docs/security/smtp-security.md`; this document
is the administrator-facing usage guide.

## Fields

Enabled toggle, host, port, encryption mode (STARTTLS / TLS / None —
None only accepted when "Approved trusted internal relay" is also
checked), authenticate toggle, username, password (write-only — see
below), from-email, bilingual from-name (AR/EN), reply-to email/name,
connection timeout, send timeout, certificate verification, queue
enabled, retry count/delay, environment label, internal-relay-mode.

## The password field

The password field is **always empty when the page loads**, regardless
of whether a password is already configured — the API never returns
the real password. Below the field, a status line reads "Password
configured" or "Password not configured," with the last-changed date
when applicable. To change the password, type a new value and save;
to leave it as-is, leave the field blank — an empty password on save
**preserves** the existing one rather than clearing it.

## Test Connection

Fill in the form (a saved configuration is not required first) and
click "Test Connection." A short-timeout connection attempt runs
against the entered host/port/encryption/credentials; a test recipient
email is optional (leave blank to test connectivity only, without
sending a message). The result is a plain success/failure message —
never a raw server banner or certificate detail. If the password field
is left blank, the currently *saved* password is used for the test
(so re-testing after a save doesn't require re-typing the password).

## Saving with `encryption=none`

The backend rejects an unencrypted configuration with a 422 unless
"Approved trusted internal relay" is explicitly checked — this is
enforced server-side, not just hinted at in the UI, so it cannot be
bypassed by calling the API directly either.

## Queue-worker refresh after a change

Saving takes effect immediately for new mail send attempts within the
same request cycle (the effective-config cache is invalidated on
save), but a **running queue worker process** will not pick up the
change until it restarts — run `php artisan queue:restart` (or restart
the worker service) after a production SMTP change. See
`docs/operations/queue-and-scheduler.md`.

## Audit trail

Every save and every test-connection attempt is recorded in the audit
log, and every non-secret field change is recorded with its old/new
value in the settings version history — the password itself is
recorded only as a `configured`/`changed`/`removed` marker, never a
value.

## Authorization

Restricted to Super Admin, enforced both server-side (403 for any
other role) and in the frontend router.
