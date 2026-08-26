# Changelog — Qiyas API (backend)

**Author:** Nasser

All notable changes to the backend are recorded here, newest first.
Format: **Added / Changed / Fixed / Security / Deps**.

## [Phase 8] Production readiness, security hardening, offline readiness

### Added
- Versioned, validated/sanitized Super Admin branding asset management
  (`branding_assets` table, `BrandingService`, `BrandingController`) —
  supersedes the earlier extension-only-validated upload endpoint,
  which is removed. See `docs/administration/branding.md`.
- Encrypted-at-rest Super Admin SMTP configuration
  (`smtp_settings` table, `SmtpSettingsService`,
  `SmtpSettingsController`) with a test-connection action and a
  password that is never returned by the API. See
  `docs/administration/smtp-settings.md`.
- Append-only, secret-aware settings versioning (`setting_versions`
  table) — records old/new values for non-secret fields and only a
  `configured`/`changed`/`removed` action (never a value) for secrets.
- A Super Admin Email Templates administration page (the API existed
  from an earlier phase; the frontend UI is new this phase).
- `scripts/scan-prohibited-references.sh` — a deterministic scan for
  automated-code-generation-tool references, wired at the time into a
  GitHub Actions workflow alongside `composer audit` and the test suite.
  *Historical status: that workflow has since been retired. The scan
  itself is unchanged and is now run manually —
  `bash scripts/scan-prohibited-references.sh`.*
- `scripts/backup.sh` / `scripts/restore.sh` — DB + evidence + branding
  storage backup and checksum-verified restore, run for real against
  the dev database as a drill.
- `scripts/generate-release-manifest.sh` and `tests/load/smoke.js` (k6
  smoke-scale load test, executed for real: 10 VUs/30s, 0% failure).
- Additional security headers: `Cross-Origin-Opener-Policy`,
  `Cross-Origin-Resource-Policy`, a default `Cache-Control: no-store`.
- Self-hosted fonts (`@fontsource/*`) in the frontend, replacing a
  Google Fonts CDN import, for full offline operation.

### Changed
- The public `GET /branding` endpoint now reads active branding
  versions directly instead of the legacy flat settings table.
- `AppServiceProvider::applyMailSettings()` now delegates to the
  encrypted `SmtpSettingsService` instead of an unencrypted (and, in
  every environment this platform has run in, never actually
  populated) generic-settings read path.

### Removed
- The unused backend-root Vite/Node scaffold (referenced a public
  Bunny Fonts CDN for an unused font — a latent offline-first
  violation in dead code) and the default Laravel welcome page.
- The old, extension-only-validated `POST
  /admin/settings/branding/upload` endpoint.

## [Phase 4–7] Multi-program platform (summary)

Phases 4 through 7 progressively built: a configuration-driven
Compliance Engine and Playwright E2E suite (Phase 4); Sumoud as a
second active program with cross-program role resolution (Phase 5); a
generic, arbitrary-depth hierarchy engine (`ComplianceNode`/
`ComplianceContentVersion`) introduced for ECC as a third program
(Phase 6); and NDMO as a fourth program plus a generic Responsibility
engine, proving the hierarchy engine required zero changes for a new
program shape (Phase 7). See `docs/multi-program-architecture.md`,
`docs/compliance-engine-architecture.md`, and the per-program docs
under `docs/programs/`.

## [Unreleased]

### Changed
- Housekeeping: remove editor/tooling directories from `.gitignore` and tidy
  project metadata.

### Added
- **Email delivery log** for Super Admin: `email_logs` table, `MessageSending`/
  `MessageSent` listeners (with `Queue::failing` to mark failures), and
  `GET /admin/email-logs` returning recipient, subject, **body**, status
  (sent/failed/pending), error, and timestamps.
- Employee dashboard: `extension_requests` + `upcoming_deadlines` counts.
- Feature tests: `RolePermissionTest` (role perms + department isolation),
  `AuthTest` (login / quick-login gating / audit).
- README: Business Workflow, Role Permission Summary, Testing Users, Data Access Rules.
- Audit log now stores **role** and **department_id**; `auth.quick_login` and
  `standard.assigned` actions logged.
- Role `qiyas-admin`; `DepartmentsSeeder` (IT/HR/Finance/Legal/Operations);
  `TestUsersSeeder` (every role + 2 employees/department, all `Password123!`);
  `DemoDataSeeder` + `php artisan system:demo-data`; dev `GET /auth/dev-users`
  and `POST /auth/quick-login` (gated to local/debug).
- Standards Excel import (`StandardsImport` + template) and the 89-standard DGA
  catalog seed; `qiyas:generate-requirements`; `GET /standards/{id}`.

### Changed
- `reports/by-status` now returns a **status breakdown** (counts + percentages),
  `cycle_id` optional.
- Mutating routes locked down with Spatie `permission:` middleware
  (cycles/standards/requirements/departments/documents/comments/extensions);
  reports allow `qiyas-admin`; `GET /departments` requires `departments.view`;
  audit-logs gated by `permission:audit-logs.view` (auditor granted it).
- SMTP mailer configured from DB settings at boot; `Setting::get` caches values
  (not the model).

### Fixed
- Document upload: removed undefined `getMimeTypes()`; validate by extension
  (allow images + Office); proper KB size rule.
- LDAP anonymous-search guard; notifications table migration; pivot
  `withTimestamps()` removed (add-standard error); `/auth/me` includes department;
  department-less users (auditor create + 401); document reject uses `reason`.

### Security
- Employees hard-scoped to their `department_id`; cross-department standard/
  document access returns **403** (show/upload/submit/download).

### Deps
- `laravel/framework` → v13.15.0, `guzzlehttp/psr7` → 2.11.0, `vite` → ^8.0.16.
