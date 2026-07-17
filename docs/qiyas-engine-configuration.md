# Qiyas — Engine Configuration Reference

How Qiyas's existing behavior is represented as engine data as of Phase 4.
Seeded by `QiyasWorkflowDefinitionSeeder` and
`QiyasProgramConfigurationSeeder` (in that order — the workflow definition
must exist before `QiyasWorkflowDemoSeeder` drives real submissions
through it), both called from `DatabaseSeeder::run()` and from
`WorkflowTestCase::setUp()` for the test suite.

## Workflow definition

| Stage key | Role | Requires rejection reason | SLA applies | Final |
|---|---|---|---|---|
| `employee` | `employee` | — | yes | no |
| `department_manager` | `department-manager` | yes | yes | no |
| `auditor` | `auditor` | yes | yes | no |
| `program_manager` | `program-manager` | yes | yes | no |
| `approved` | — | — | no | **yes** |

Transitions: `employee --submit--> department_manager`,
`department_manager --approve--> auditor`,
`department_manager --reject--> employee`,
`auditor --approve--> program_manager`,
`auditor --reject--> employee`,
`program_manager --approve--> approved`,
`program_manager --reject--> employee`.

This is an exact transcription of the pre-Phase-4
`NEXT_STAGE`/`STATUS_FOR_STAGE` PHP constants — Qiyas's business process
did not change, only its representation. See `docs/workflow-engine.md`.

## Program configuration values

| Category | Key | Value | Source |
|---|---|---|---|
| `terminology` | `domain` | `{ar: المنظور, en: Perspective}` | `docs/qiyas-workflow.md` |
| `terminology` | `category` | `{ar: المحور, en: Axis}` | `docs/qiyas-workflow.md` |
| `terminology` | `requirement` | `{ar: المعيار, en: Standard}` | `docs/qiyas-workflow.md` |
| `terminology` | `evidence` | `{ar: مستند الإثبات, en: Evidence Document}` | `docs/qiyas-workflow.md` |
| `terminology` | `cycle` | `{ar: دورة القياس, en: Qiyas Cycle}` | `docs/qiyas-workflow.md` |
| `extensions` | `requester_role` | `employee` | `ExtensionService::request()` signature |
| `extensions` | `reviewer_role` | `auditor` | Pre-Phase-4 literal in `ExtensionRequestPolicy` |
| `extensions` | `rejection_reason_required` | `true` | `ExtensionService::decide()` |
| `extensions` | `allow_multiple_pending` | `false` | `ExtensionRequest::pending()` scope check in `request()` |
| `evidence` | `allowed_extensions` | `pdf,doc,docx,xls,xlsx,ppt,pptx,zip,jpg,jpeg,png` | `EvidenceUploadValidator::DEFAULT_EXTENSIONS` |
| `evidence` | `max_file_size_mb` | `20` | Same |
| `evidence` | `max_files_per_submission` | `10` | Same |
| `evidence` | `max_total_submission_size_mb` | `100` | Same |
| `assignment` | `department_required` | `true` | `WorkflowService::assign()` signature |
| `assignment` | `employee_assignment_required` | `false` | Same |
| `assignment` | `reassignment_reason_required` | `true` | `reassignDepartment()` signature |
| `assignment` | `due_date_required` | `false` | `assign()` signature |
| `import` | `program_code` | `QIYAS` | — |
| `import` | `template_version` | `QIYAS-TEMPLATE-1.0` | `MetadataSheet::TEMPLATE_VERSION` |
| `import` | `columns` | 10 columns, bilingual labels | `RequirementsSheet::COLUMNS` + Instructions sheet text |
| `features` | (10 flags) | all `true` | Every Phase 2 feature Qiyas already uses |

Every value above is a **transcription**, not an invented default — see
`database/seeders/QiyasProgramConfigurationSeeder.php` for the exact code
and the comment on each line naming its pre-Phase-4 source.

## Categories deliberately not yet populated for Qiyas

`identity`, `hierarchy`, `review`, `deadlines`, `sla`, `notifications`,
`export`, `dashboards`, `reports`, `scoring`, `security` — these remain
governed by code (already program-agnostic code, per the coupling
analysis), not configuration. Populating them was not required to satisfy
any Phase 4 completion condition and was left for a future pass.
