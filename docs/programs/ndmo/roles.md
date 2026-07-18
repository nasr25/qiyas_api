# NDMO — Roles and Permissions

Uses the same `program_user_roles` engine built in Phase 1 — no new role
model, no NDMO-specific authorization table.

## Test accounts (`database/seeders/NDMOTestAccountsSeeder.php`)

| Username | NDMO role | Department |
|---|---|---|
| ndmo_pm | program-manager | — |
| ndmo_auditor | auditor | — |
| ndmo_dept_a_manager | department-manager | Information Technology |
| ndmo_employee_a | employee | Information Technology |
| ndmo_dept_b_manager | department-manager | Human Resources |
| ndmo_employee_b | employee | Human Resources |
| ndmo_data_owner_a | employee | Information Technology |
| ndmo_data_steward_a | employee | Information Technology |

`ndmo_data_owner_a`/`ndmo_data_steward_a` hold only the `employee`
program role — their Data Owner/Data Steward status is a separate
responsibility label (see `responsibilities.md`), never an elevated role.

## Quad-program role scenario (brief's explicit example)

`quadprogram_qiyas_pm_sumoud_auditor_ecc_emp_ndmo_deptmgr`: Qiyas Program
Manager, Sumoud Auditor, ECC Employee, NDMO Department Manager — four
different roles across four different programs, on one user. Proven by
`NDMOProgramEngineTest::test_quad_program_user_resolves_a_different_role_in_each_program`
(backend) and `tests/e2e/cross-program/quad-role.spec.ts` (Playwright,
real UI navigation across all four programs).

No pre-existing Qiyas/Sumoud/ECC account was granted NDMO access — every
grant is a new, explicit `program_user_roles` row.
