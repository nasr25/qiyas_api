# Roles and Scopes

## 1. Two authorization layers

| Layer | Mechanism | Governs |
|---|---|---|
| Platform-level | Global `spatie/laravel-permission` roles (`super-admin`, `executive`, plus legacy `qiyas-admin`/`auditor`/`coordinator`/`employee`) | Every pre-existing route (`/cycles`, `/standards`, `/documents`, `/admin/*`, `/reports/*`, ...) |
| Program-level | `program_user_roles` table, `role_key` string | `/api/v1/programs/*` — program access, and (via `department_id`) department scoping inside a program |

Both layers are active simultaneously in Phase 1. See
`multi-program-architecture.md` §4 for why they were not collapsed into
one system yet.

## 2. Platform-level roles

### Super Admin (spatie `super-admin`)

- Access all programs (implicit — no `program_user_roles` row needed).
- Manage users, departments, platform roles/permissions.
- Manage program assignments *(program CRUD/assignment UI itself is
  Phase 2 — see "Deferred" below; today this means: can query/see every
  program including inactive ones, and is the only role
  `ComplianceProgramPolicy::manage()` returns true for)*.
- Manage branding, email settings, notification templates, system
  settings.
- View audit logs.
- Unchanged from before Phase 1.

### Executive Viewer (spatie `executive`)

- Read-only access to every **active** program's dashboard/reports
  (implicit — no `program_user_roles` row needed).
- No evidence upload, no approve/reject actions, no configuration changes
  — enforced because the `executive` spatie role was never granted any
  `*.create`/`*.edit`/`*.approve` permission (see `RolesAndPermissionsSeeder`,
  unchanged).
- Verified by `test_executive_viewer_has_read_only_implicit_access` and
  the pre-existing `test_executive_cannot_create_cycle` /
  `test_executive_can_view_reports`.

## 3. Program-level roles (`program_user_roles.role_key`)

### Program Manager (`program-manager`)

- Mapped from legacy `qiyas-admin`.
- Access assigned programs only.
- Manage cycles, manage requirements, assign requirements to departments,
  monitor progress, view reports — via the legacy `qiyas-admin` spatie
  permissions (unchanged); the `program_user_roles` row additionally
  gates the new `/api/v1/programs/{program}/*` routes.
- Additional workflow permissions: deferred to a later phase per the
  brief.

### Auditor (`auditor`)

- Mapped from legacy `auditor`.
- Access assigned programs only.
- View requirements and evidence; approve/reject via the existing
  `/auditor/*` routes (unchanged, `role:auditor|super-admin`).
- Detailed approval workflow finalization: deferred.

## 4. Department-level roles (used inside a program)

### Department Manager (`department-manager`)

- Mapped from legacy `coordinator`.
- Access the user's own department only (`program_user_roles.department_id`).
- Works across any program they're assigned to.
- Detailed review workflow: deferred.

### Employee (`employee`)

- Mapped from legacy `employee`.
- Access the user's own department only.
- View and work on assigned requirements via existing document endpoints.
- Detailed evidence workflow: deferred.

Department isolation itself is enforced the same way it always was —
per-controller `where('department_id', $user->department_id)` checks
(`DocumentController`, `StandardsController`, dashboards) — **not** by the
new program layer, which only decides *program* access. See
`tests/Feature/RolePermissionTest.php` (pre-existing, all still passing)
for the enforced department-isolation matrix, and
`tests/Feature/ComplianceProgramAccessTest.php` for the new program-access
matrix.

## 5. Full access matrix

| Action | Super Admin | Executive | Program Manager | Auditor | Dept. Manager | Employee |
|---|:---:|:---:|:---:|:---:|:---:|:---:|
| See a program on Program Selection | all active + inactive | all active | assigned only | assigned only | assigned only | assigned only |
| `GET /programs/{program}` | ✅ (incl. inactive) | ✅ (active only) | ✅ if assigned | ✅ if assigned | ✅ if assigned | ✅ if assigned |
| `GET /programs/{program}/dashboard` | ✅ | ✅ read-only | ✅ if assigned | ✅ if assigned | ✅ if assigned (own dept data via legacy dashboard) | ✅ if assigned (own dept data via legacy dashboard) |
| Create/activate/close cycle | ✅ | ❌ | ✅ (legacy `cycles.*` perms) | ❌ | ❌ | ❌ |
| Create/edit requirements (standards) | ✅ | ❌ | ✅ | ❌ | ❌ | ❌ |
| Approve/reject evidence | ✅ | ❌ | ❌ | ✅ | ❌ | ❌ |
| Upload/submit evidence | ✅ | ❌ | ❌ | ❌ | ✅ (own dept) | ✅ (own dept) |
| View own-department documents | ✅ | ✅ (all depts) | ✅ (all, via legacy perms) | ✅ (all, via legacy perms) | ✅ own dept only | ✅ own dept only |
| View another department's documents | ✅ | ✅ | ✅ | ✅ | ❌ (403) | ❌ (403) |
| Manage users / departments | ✅ | ❌ | ❌ | ❌ | ❌ | ❌ |
| View audit logs | ✅ | ❌ | ❌ (unless `qiyas-admin` granted `audit-logs.view`) | ✅ | ❌ | ❌ |
| Access an unassigned program by URL/ID | ✅ (all) | ✅ (active only) | ❌ (404) | ❌ (404) | ❌ (404) | ❌ (404) |
| Access an inactive program | ✅ | ❌ (404) | ❌ (404) | ❌ (404) | ❌ (404) | ❌ (404) |

## 6. Quick Login test accounts (local/debug only)

`database/seeders/TestUsersSeeder.php`, password `Password123!` for all:

| Username | Spatie role | Program role | Department |
|---|---|---|---|
| `superadmin` | super-admin | — (implicit) | — |
| `executive_viewer` | executive | — (implicit) | — |
| `qiyas_admin` | qiyas-admin | program-manager (QIYAS) | — |
| `auditor_1`, `auditor_2` | auditor | auditor (QIYAS) | — |
| `it_manager` | coordinator | department-manager (QIYAS) | Information Technology |
| `hr_manager` | coordinator | department-manager (QIYAS) | Human Resources |
| `{it,hr,finance,legal,operations}_employee_{1,2}` (10 accounts) | employee | employee (QIYAS) | respective department |

`it_manager`/`hr_manager` were added in Phase 1 — the Department Manager
role previously had no dedicated test account. Two departments
(IT, HR) now have both an Employee and a Department Manager account, so
isolation between them is directly testable via Quick Login.

`GET /api/v1/auth/dev-users` now also returns each account's `programs`
array (`{program: "QIYAS", role_key: "..."}`), so the Quick Login panel
can display the assigned program/role/department, not just the platform
role.

Quick Login is gated to `app()->environment('local') || config('app.debug')`
(unchanged logic) — verified unavailable in a simulated `production`
environment by `test_quick_login_is_unavailable_when_environment_is_production`.

## 7. Deferred to a later phase

- Program CRUD (create/edit/activate/deactivate a `ComplianceProgram`) and
  `program_user_roles` assignment/revocation via an API/UI — Phase 1
  provisions QIYAS through migrations/seeders only.
- Collapsing the platform-role and program-role systems into one
  (e.g. spatie "teams" mode keyed on `compliance_program_id`).
- Full approval/review workflow detail for Auditor and Department Manager
  roles beyond what already exists.
