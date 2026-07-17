# Sumoud — Roles and Permissions

Program-scoped roles use the same generic `program_user_roles`
(`ProgramUserRole` model, role keys `program-manager`/`auditor`/
`department-manager`/`employee`) engine built in Phase 1 — no new role
model, no new authorization table.

## Test accounts (`database/seeders/SumoudTestAccountsSeeder.php`)

| Username | Sumoud role | Department |
|---|---|---|
| sumoud_pm | program-manager | — |
| sumoud_auditor | auditor | — |
| sumoud_dept_a_manager | department-manager | Information Technology |
| sumoud_employee_a | employee | Information Technology |
| sumoud_dept_b_manager | department-manager | Human Resources |
| sumoud_employee_b | employee | Human Resources |

Departments are the same shared, global `departments` rows Qiyas uses —
never duplicated.

## Cross-program role scenarios (see `roles.md` companion doc
`cross-program-role-resolution.md` at the platform level)

| Username | Qiyas role | Sumoud role |
|---|---|---|
| cross_pm_qiyas_auditor_sumoud (User A) | program-manager | auditor |
| cross_employee_qiyas_deptmgr_sumoud (User B) | employee | department-manager |
| auditor_2 (existing Qiyas account, untouched — User C) | auditor | *(none)* |
| cross_employee_both_programs (User D) | employee (IT dept) | employee (same IT dept) |

None of the pre-existing Qiyas test accounts were granted Sumoud access —
every Sumoud/cross-program grant above is a new, explicit
`program_user_roles` row created by `SumoudTestAccountsSeeder`, never a
blanket migration.
