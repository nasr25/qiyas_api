# System Settings — Full Catalog

**Author:** Nasser

## What actually exists as a UI-configurable setting category

The Settings page (`/admin/settings`) has six real tabs, each backed
by either the generic `settings` key-value table or a dedicated table
introduced in Phase 8:

| Tab | Backing store | Key fields |
|---|---|---|
| Branding | `branding_assets` (versioned) + `settings` group `branding` | Platform name (AR/EN), 8 logo/favicon asset types — see `docs/administration/branding.md` |
| Email Settings | `smtp_settings` (encrypted) | See `docs/administration/smtp-settings.md` |
| Email Templates | `email_templates` | See `docs/administration/email-templates.md` |
| Upload Settings | `settings` group `upload` | Max file size (MB), allowed file extensions |
| Notifications | `settings` group `notifications` | Per-event toggle: notify on submit/approve/reject/deadline/extension |
| Localization | `settings` group `localization` | Default locale (AR/EN), timezone, date format |

Each non-secret field change through the generic `settings` group is
saved via `POST /admin/settings` with `{group, key, value, type}` and
is **not** currently versioned (only branding and SMTP changes write
to `setting_versions` — see below).

## Requested-but-not-implemented categories

An earlier specification for this phase described 16 settings
categories, including several this platform does **not** currently
expose as a configurable settings UI:

| Requested category | Actual status |
|---|---|
| General | Partially covered by Branding's platform-name fields; no separate "General" tab |
| Security | Not a settings UI — password policy, rate limits, etc. are code/config constants (see `docs/security/security-hardening.md`), not Super-Admin-editable |
| Sessions | Not exposed — JWT TTL is `config/jwt.php`, not a settings-page field |
| Date/Time | Merged into Localization (timezone + date format), not a separate tab |
| Program Defaults | Program-level configuration exists (`program_configurations` table) but is not surfaced on this global Settings page — see `docs/programs/{program}/configuration.md` |
| Maintenance | No maintenance-mode toggle exists in this settings UI (Laravel's own `php artisan down`/`up` is available at the CLI/ops level — see `docs/operations/operations-guide.md` — but is not a Settings-page control) |
| Audit/Retention | Audit logs exist and are viewable (`/admin/audit-logs`) but retention period is not a configurable setting |
| Integrations | No optional-integration toggle framework exists; SMTP is the only external integration, and it has its own dedicated tab |
| Offline Assets | Not a settings-page concept — offline-readiness is a build-time/deployment property, documented in `docs/offline-assets.md`, not a runtime toggle |
| Health Monitoring | Not a settings-page control — see `docs/operations/health-checks.md` for the actual health-check endpoints |

This is an honest scope gap, not a claim of completeness — building
all 16 categories as genuinely Super-Admin-configurable settings was
not completed in this phase and is recorded as an open item in the
final readiness report rather than silently omitted.

## Field metadata

For the six real categories, each field is validated server-side
(type-checked: string/boolean/integer/float/json) but does **not**
currently carry the richer per-setting metadata an earlier
specification requested (secret-classification flag, restart-required
flag, cache-invalidation-behavior description, allowed-roles list,
bilingual description) as structured, machine-readable data — that
metadata exists only informally, in this documentation and in code
comments, not as queryable API data. The two exceptions are Branding
and SMTP, which do have real, structured versioning
(`setting_versions`, secret-aware) and, for SMTP, an explicit
`is_secret` flag per field.

## What a Super Admin cannot change through any settings UI

Arbitrary environment variables, arbitrary files, the database
password, `APP_KEY`, OS/AD-service-account/IIS-admin credentials, raw
server paths, or PHP/IIS configuration — by design, not by omission.
See `docs/security/secrets-management.md`.
