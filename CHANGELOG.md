# Changelog — Qiyas API (backend)

All notable changes to the backend are recorded here, newest first.
Format: **Added / Changed / Fixed / Security / Deps**.

## [Unreleased]

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
