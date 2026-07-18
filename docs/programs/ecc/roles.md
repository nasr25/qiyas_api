# ECC — Roles and Permissions

Uses the same `program_user_roles` engine built in Phase 1 (role keys
`program-manager`/`auditor`/`department-manager`/`employee`) — no new role
model, no ECC-specific authorization table.

## Test accounts (`database/seeders/ECCTestAccountsSeeder.php`)

| Username | ECC role | Department |
|---|---|---|
| ecc_pm | program-manager | — |
| ecc_auditor | auditor | — |
| ecc_dept_a_manager | department-manager | Information Technology |
| ecc_employee_a | employee | Information Technology |
| ecc_dept_b_manager | department-manager | Human Resources |
| ecc_employee_b | employee | Human Resources |

Departments are the same shared, global rows Qiyas/Sumoud use.

## Tri-program role scenarios (brief's explicit examples)

| Username | Qiyas | Sumoud | ECC |
|---|---|---|---|
| triprogram_qiyas_pm_sumoud_auditor_ecc_employee (User A) | program-manager | auditor | employee |
| triprogram_qiyas_emp_sumoud_deptmgr_ecc_pm (User B) | employee | department-manager | program-manager |

No pre-existing Qiyas or Sumoud account was granted ECC access — every
grant above is a new, explicit `program_user_roles` row.

## Role resolution

Unchanged since Phase 1: `hasProgramAccess()`/`hasProgramRole()` resolve
per (user, program) with no session-level caching — every request
re-derives from `program_user_roles`. `ECCProgramEngineTest::test_tri_program_user_resolves_different_roles_per_program`
and the Playwright `cross-program/multi-role.spec.ts` both prove this for
all three programs simultaneously on one user.
