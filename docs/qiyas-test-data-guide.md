# Qiyas — Test Data Guide

Describes what `php artisan db:seed` produces and how to use it for UAT.
**No confidential or real employee data is used anywhere in this seed
data** — every name, department, and standard below is synthetic.

## Seeding

```bash
php artisan migrate:fresh --seed
```

Runs (in order, via `DatabaseSeeder`): roles/permissions, the QIYAS
compliance program + a demo cycle + standards, `TestUsersSeeder`, 16 email
templates (`EmailTemplatesSeeder`), and `QiyasWorkflowDemoSeeder` (12
workflow scenarios covering every status).

`QiyasWorkflowDemoSeeder` specifically is **not** raw database inserts — it
drives every scenario through the real `WorkflowService`/`ExtensionService`
domain services, the same code path a real user's actions go through, so
the seeded data is guaranteed to be structurally valid (correct decision
history, correct SLA instances) rather than a hand-crafted shortcut that
might not match what the application would actually produce.

## Test accounts (`TestUsersSeeder`)

All passwords: `Password123!`. Quick Login (no password) also works for
these accounts, but **only when `APP_ENV=local` or `APP_DEBUG=true`** — see
`docs/qiyas-role-permissions.md` and
`test_quick_login_is_disabled_outside_local_or_debug`. Never rely on Quick
Login existing in a production or UAT-on-a-shared-server environment.

| Username | Platform role | Program role | Department |
|---|---|---|---|
| `superadmin` | Super Admin | — | — |
| `executive_viewer` | Executive Viewer | — | — |
| `qiyas_admin` | — | Program Manager (Qiyas) | — |
| `auditor_1`, `auditor_2` | — | Auditor (Qiyas) | — |
| `it_manager` | — | Department Manager (Qiyas) | Information Technology |
| `hr_manager` | — | Department Manager (Qiyas) | Human Resources |
| `it_employee_1`, `it_employee_2` | — | Employee (Qiyas) | Information Technology |
| `hr_employee_1`, `hr_employee_2` | — | Employee (Qiyas) | Human Resources |
| `finance_employee_1`, `finance_employee_2` | — | Employee (Qiyas) | Finance |
| `legal_employee_1`, `legal_employee_2` | — | Employee (Qiyas) | Legal |
| `operations_employee_1`, `operations_employee_2` | — | Employee (Qiyas) | Operations |

Five departments exist (IT, HR, Finance, Legal, Operations) specifically so
department-isolation testing has more than the two departments strictly
needed — a tester can confirm an IT employee is blocked from Finance data
too, not just from the one "other" department a minimal fixture would have.

## Seeded workflow scenarios (`QiyasWorkflowDemoSeeder`)

12 `RequirementAssignment` records, each driven through the real
`WorkflowService`, covering:

| # | Scenario | Status you'll see |
|---|---|---|
| 1 | Newly assigned, no draft started yet | `assigned` |
| 2 | Draft started, no files uploaded | `draft` |
| 3 | Draft with files, not yet submitted | `draft` |
| 4 | Submitted, awaiting Department Manager | `pending_department_manager` |
| 5 | Department Manager approved, awaiting Auditor | `pending_auditor` |
| 6 | Auditor approved, awaiting Program Manager | `pending_program_manager` |
| 7 | Returned by Department Manager | `returned_for_revision` |
| 8 | Fully approved | `approved` |
| 9 | Overdue (effective due date in the past, no submission yet) | `assigned` + overdue flag |
| 10 | SLA warning (a stage's clock is past the configured warning threshold) | pending, SLA warning |
| 11 | SLA breach (backdated past the due date) | pending, SLA breached |
| 12 | Extension request pending Auditor decision | pending + extension request |

Plus one already-**approved** extension request (distinct from the pending
one in scenario 12) demonstrating the effective-due-date-changed,
original-due-date-preserved state.

## Cycle / taxonomy data

One active demo cycle, 97 standards across 10 perspectives and 24 axes
(reused from the pre-existing Qiyas demo dataset, not newly authored for
Phase 2/3) — enough breadth to exercise the My Requirements/review-queue
filters (perspective, axis, status) meaningfully without needing to author
a large synthetic taxonomy from scratch.

## Valid and invalid XLSX examples for import testing

No static example files are checked into the repository — generate them on
demand, which also guarantees they're never stale relative to the current
template version:

- **Valid file**: download via `GET
  /api/v1/programs/QIYAS/requirements-template` (Program Manager /
  `qiyas_admin`), then fill in a few rows under the visible `Requirements`
  sheet and re-upload — the hidden `_metadata` sheet is untouched, so it
  will validate.
- **Invalid — wrong program**: take a valid Qiyas template, and if a second
  program exists, download that program's template instead and try
  uploading it to Qiyas's import screen (`WRONG_PROGRAM` error).
- **Invalid — macro-enabled**: save the downloaded template as `.xlsm` from
  Excel (enables macros), then rename the file back to `.xlsx` before
  uploading — this reproduces the Phase 3-fixed
  `MACRO_ENABLED_REJECTED` check exactly (see
  `docs/qiyas-security-review.md` finding #3).
- **Invalid — missing required field**: delete a `standard_number` or
  `name_ar` cell in a data row before uploading (`REQUIRED` error).
- **Invalid — duplicate**: duplicate a `standard_number` value across two
  rows in the same file (`DUPLICATE_IN_FILE` error).

## Resetting between test rounds

```bash
php artisan migrate:fresh --seed
```
This is destructive to whatever is currently in the database — only run it
against a dev/UAT database, never against production data. It is the
correct way to get back to a known-clean state between UAT rounds.
