# Qiyas Role Permissions (Phase 2 Workflow)

Builds on `docs/roles-and-scopes.md` (Phase 1). This document covers only
the Phase 2 operational-workflow actions.

## Actions by role

| Action | Program Manager | Auditor | Department Manager | Employee | Executive Viewer | Super Admin |
|---|:---:|:---:|:---:|:---:|:---:|:---:|
| Assign a requirement | ✅ | ❌ | ❌ | ❌ | ❌ | ✅ |
| Reassign department (reason required) | ✅ | ❌ | ❌ | ❌ | ❌ | ✅ |
| Change due date / instructions / employee | ✅ | ❌ | ❌ | ❌ | ❌ | ✅ |
| Create/edit draft, upload/remove evidence | ❌ | ❌ | ❌ | ✅ (own dept, or the specifically assigned employee) | ❌ | ✅ |
| Submit / resubmit | ❌ | ❌ | ❌ | ✅ | ❌ | ✅ |
| Request extension | ❌ | ❌ | ❌ | ✅ | ❌ | ✅ |
| View own department's submissions | ❌ (program-wide instead) | ✅ (program-wide) | ✅ (own dept only) | ✅ (own dept only) | ✅ (read-only) | ✅ |
| Approve/reject at Department Manager stage | ❌ | ❌ | ✅ (own dept only) | ❌ | ❌ | ✅ |
| Approve/reject at Auditor stage | ❌ | ✅ | ❌ | ❌ | ❌ | ✅ |
| Approve/reject at Program Manager (final) stage | ✅ | ❌ | ❌ | ❌ | ❌ | ✅ |
| Decide an extension request | ❌ | ✅ | ❌ (view only) | ❌ | ❌ | ✅ |
| View/edit SLA settings | ✅ (own program) | ❌ | ❌ | ❌ | ❌ | ✅ (any program) |
| Download/import Qiyas XLSX template | ✅ | ❌ | ❌ | ❌ | ❌ | ✅ |
| Manage email templates | ❌ | ❌ | ❌ | ❌ | ❌ | ✅ |
| Download evidence file | anyone who can view the submission (see policy below) | | | | | |

Every row above is enforced server-side by a Policy method, never by
frontend visibility alone — see the classes listed below.

## Enforcement points

- `RequirementAssignmentPolicy::manage()` — program-manager role or Super
  Admin, checked in `RequirementAssignmentController` before create/update/
  reassign.
- `RequirementAssignmentPolicy::view()` / `EvidenceSubmissionPolicy::view()`
  — Super Admin/Executive always; Program Manager/Auditor program-wide;
  Department Manager/Employee only if their `program_user_roles.department_id`
  matches the record's `department_id`.
- `EvidenceSubmissionPolicy::edit()` — only while the submission is
  `draft`/`returned_for_revision`, only the assignment's department, and
  only the specifically assigned employee **if one was set** (otherwise any
  employee in that department).
- `EvidenceSubmissionPolicy::reviewAsDepartmentManager()` /
  `reviewAsAuditor()` / `reviewAsProgramManager()` — used by
  `ReviewQueueController` (shared base class for all three review
  controllers) to gate approve/reject per stage.
- `ExtensionRequestPolicy::decide()` — Auditor (program-scoped) or Super
  Admin only; a Department Manager gets 403, never a silent no-op.
- `SlaSettingPolicy::manage()` — that program's Program Manager or Super
  Admin.

## Department isolation mechanics

Unlike Phase 1's manual per-controller `where('department_id', ...)`
filters, Phase 2 resolves the acting user's department **from their
program-scoped role**, not their platform `users.department_id` column:

- `User::managedDepartmentId($program)` — the department they manage, if
  they hold `department-manager` in that program.
- `User::employeeDepartmentId($program)` — the department they belong to as
  an employee in that program (falls back to `users.department_id` if no
  program-role row exists, for backward compatibility).

This means a user's department scope is **per-program** — the same person
could in principle be a Department Manager in one program and have no role
at all in another, and the platform `department_id` column alone is never
sufficient to authorize a Phase 2 action.

## Cross-program isolation

Every Phase 2 controller resolves nested resources (assignment ID,
submission ID, extension request ID, import log ID) by **both** the
route-resolved `compliance_program_id` **and** the record's own
`compliance_program_id` — mismatches return 404, matching the same
IDOR-resistant pattern established in Phase 1's `EnsureProgramAccess`
middleware (see `docs/multi-program-architecture.md` §7).

## Quick Login test accounts (Phase 2)

| Username | Platform role | Program role | Department |
|---|---|---|---|
| `superadmin` | Super Admin | — (implicit) | — |
| `executive_viewer` | Executive Viewer | — (implicit) | — |
| `qiyas_admin` | — | program-manager | — |
| `auditor_1`, `auditor_2` | — | auditor | — |
| `it_manager` | — | department-manager | Information Technology (Dept A) |
| `hr_manager` | — | department-manager | Human Resources (Dept B) |
| `it_employee_1`, `it_employee_2` | — | employee | Information Technology (Dept A) |
| `hr_employee_1`, `hr_employee_2` | — | employee | Human Resources (Dept B) |

Password for all: `Password123!` (also usable via Quick Login with no
password, local/debug only — see `docs/roles-and-scopes.md` §6 for the
production-disable guarantee, re-verified for Phase 2 by
`test_quick_login_is_unavailable_when_environment_is_production`).

`QiyasWorkflowDemoSeeder` seeds 12 `RequirementAssignment` rows across these
accounts covering every workflow status, an overdue requirement, a pending
and an approved extension request, and a backdated SLA warning/breach pair —
run automatically by `php artisan db:seed`.
