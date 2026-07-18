# Super Admin Guide

**Author:** Nasser

The Super Admin role bypasses all program/department authorization
checks (`Gate::before` in `AppServiceProvider`) and is the only role
with access to platform-wide administration. This guide indexes every
Super Admin capability; each linked document covers its area in full.

## Settings page tabs (`/admin/settings`)

| Tab | What it manages | Document |
|---|---|---|
| Branding | Versioned platform logos/favicon | `docs/administration/branding.md` |
| Email Settings | Encrypted SMTP configuration + test connection | `docs/administration/smtp-settings.md` |
| Email Templates | Global notification email templates | `docs/administration/email-templates.md` |
| Upload Settings | Max file size, allowed file types (platform-wide) | `docs/administration/system-settings.md` |
| Notifications | Which workflow events send an email vs. in-app only | `docs/administration/system-settings.md` |
| Localization | Default locale, timezone, date format | `docs/administration/system-settings.md` |

## Other Super Admin surfaces

- **Users** (`/admin/users`) — create/edit users, LDAP search/import,
  reset password, activate/deactivate, assign program roles.
- **Audit Logs** (`/admin/audit-logs`) — every logged action platform-
  wide, filterable by user/action/date/IP.
- **Email Logs** (`/admin/email-logs`) — every outbound email attempt,
  recipient, subject, body, status (sent/failed/pending), error.
- **Programs** — the four active Compliance Programs (Qiyas, Sumoud,
  ECC, NDMO); program-level configuration is documented per-program
  under `docs/programs/{program}/configuration.md`.

## Authorization boundary

Super Admin access is enforced by the `role:super-admin` route
middleware group (`routes/api.php`) on every admin endpoint, and by
the frontend router's `meta.roles` guard (redirects a non-Super-Admin
to `/programs`, never showing a broken/partial admin page). Both layers
were verified this phase with Playwright tests confirming a 403 from
the API and a redirect from the UI for auditor/employee/program-manager
roles against branding, SMTP, and email-template endpoints.

## What a Super Admin cannot do through this UI

By design, no Super Admin UI surface allows: arbitrary environment
variable editing, arbitrary file editing, command execution, editing
the database password/`APP_KEY`/OS credentials/AD service-account
password/raw server paths/IIS admin credentials/PHP or IIS
configuration/Windows service credentials, or viewing a previously
saved SMTP password. Those remain under the approved deployment/
secret-management process — see
`docs/security/secrets-management.md`.
