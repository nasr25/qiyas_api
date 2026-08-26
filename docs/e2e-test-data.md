# E2E Test Data

The isolated `qiyas_e2e_db` database (see `docs/playwright-e2e-guide.md`)
is seeded by the exact same `DatabaseSeeder` chain used for local
development — no separate E2E-only seeder was written, since the existing
seed chain already produces everything the brief's "Required entities"
list asks for.

## Test accounts (all seeded by `TestUsersSeeder`, password `Password123!`, Quick Login available)

| Helper constant | Username | Role |
|---|---|---|
| `USERS.superAdmin` | `superadmin` | Super Admin |
| `USERS.executiveViewer` | `executive_viewer` | Executive Viewer |
| `USERS.programManager` | `qiyas_admin` | Qiyas Program Manager |
| `USERS.auditor` / `auditor2` | `auditor_1` / `auditor_2` | Auditor |
| `USERS.deptManagerA` | `it_manager` | Department Manager, Information Technology |
| `USERS.employeeA` / `employeeA2` | `it_employee_1` / `it_employee_2` | Employee, Information Technology |
| `USERS.deptManagerB` | `hr_manager` | Department Manager, Human Resources |
| `USERS.employeeB` | `hr_employee_1` | Employee, Human Resources |

Matches the brief's required user list exactly (`tests/e2e/helpers/auth.ts`).

## Entities

- Qiyas program (`compliance_programs`, code `QIYAS`) + an active cycle —
  both exist from the moment migrations run (the program row is seeded by
  a migration itself, the demo active cycle by `DemoDataSeeder`).
- Departments: Information Technology, Human Resources, Finance, Legal,
  Operations (`DepartmentsSeeder`) — more than the minimum two, so
  department-isolation tests can also confirm a user is blocked from a
  *third*, unrelated department, not just "the one other department a
  minimal fixture would have."
- Standards: 97 pre-existing (89 real DGA Qiyas standards + 8 demo) plus
  whatever a given test run creates fresh via `uniqueStandardCode()`
  (`tests/e2e/data/fixtures.ts` — timestamp + random suffix, so parallel
  or repeated runs never collide on `standard_number`).
- Evidence file fixtures: generated in-memory per test
  (`Buffer.from('%PDF-1.4 ...')`), not checked-in binary files — see
  `docs/playwright-e2e-guide.md`.

## Not yet provided

Valid/invalid/unsupported-version/wrong-program XLSX fixture files — no
XLSX E2E scenario was written this phase (see
`docs/compliance-engine-known-issues.md`), so these fixtures were not
generated. `HierarchyImportApiTest.php` (backend PHPUnit) already builds a valid
template file on the fly via `Excel::store()` for its own coverage; the
same technique would apply for a future Playwright XLSX suite rather than
checked-in binary fixtures.

## Resetting between runs

```bash
mysql -u root -e "DROP DATABASE qiyas_e2e_db; CREATE DATABASE qiyas_e2e_db CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
cd backend
DB_DATABASE=qiyas_e2e_db php artisan migrate --force
DB_DATABASE=qiyas_e2e_db php artisan db:seed --force
```

No automated per-test cleanup/reset exists — each spec file creates its
own uniquely-named fixtures (standards, assignments) rather than relying
on a clean slate, so tests are safe to run repeatedly against an
accumulating database without collision. A full `DROP`/recreate is only
needed if the accumulated state itself becomes inconvenient (e.g. very
slow list queries after thousands of runs), not for correctness.
