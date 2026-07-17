# Qiyas Operational Workflow (Phase 2)

## 1. Entities

| Entity | Purpose | Relationship to Phase 1 |
|---|---|---|
| `RequirementAssignment` | One requirement assigned to one primary department (+ optional employee) | New — `department_standard` (Phase 1 pivot, many-to-many) is left untouched for backward compatibility with the legacy `/standards` views; assignment is now modeled explicitly, one row per active assignment, history preserved as superseded rows |
| `EvidenceSubmission` | One row per submission **version** for an assignment | New, generic ("Evidence Submission" was already named in `docs/multi-program-architecture.md` as the target generic entity). Coexists with the legacy `Document`/`DocumentVersion` model, which stays fully functional for existing routes/reports but is not extended further |
| `EvidenceFile` | A file attached to one `EvidenceSubmission` version | New |
| `WorkflowDecision` | Append-only reviewer decision at a stage | New |
| `WorkflowEvent` | Append-only full timeline | New |
| `ExtensionRequest` | Employee-requested due-date extension, Auditor decides | Existing Phase 1 table, extended additively (see `extension-request-workflow.md`) |
| `SlaSetting` | Program-scoped SLA configuration | New |
| `SlaInstance` | Per-stage SLA measurement, one row per stage occurrence | New |
| `EmailTemplate` | Bilingual, variable-driven notification template | New |
| `NotificationLog` | Delivery/idempotency record | New |
| `ImportLog` | XLSX import run record | New |

## 2. Status model

`EvidenceSubmission.status` (the authoritative workflow status):

```
draft
  -> pending_department_manager   (Employee submits)
  -> returned_for_revision        (Department Manager rejects)  [also reachable from auditor/program_manager reject]

pending_department_manager
  -> pending_auditor              (Department Manager approves)
  -> returned_for_revision        (Department Manager rejects)

pending_auditor
  -> pending_program_manager      (Auditor approves)
  -> returned_for_revision        (Auditor rejects)

pending_program_manager
  -> approved                     (Program Manager approves — terminal)
  -> returned_for_revision        (Program Manager rejects)

returned_for_revision
  -> draft                        (Employee starts correcting — new EvidenceSubmission version)
```

**Every rejection, regardless of stage, sets `current_stage = employee` and status
`returned_for_revision`.** There is no "return to previous reviewer" path — this
is enforced in `WorkflowService::reject()`, the single place any status
transition is permitted.

**Resubmission always creates a new `EvidenceSubmission` row** (`version_number`
+ 1) and always restarts at `pending_department_manager` after the employee
submits — never at `pending_auditor` or `pending_program_manager`, even if
those stages had already approved a previous version. This is enforced by
`WorkflowService::submit()`, which always sets the next stage to
`department_manager` regardless of which stage rejected the prior version.

`RequirementAssignment` has its own, separate, simpler status
(`active`/`reassigned`/`completed`) describing the assignment record's
lifecycle, not the workflow. The requirement's display status combines both:
no active assignment → `unassigned`; assignment exists, no submission yet →
`assigned`; otherwise the latest `EvidenceSubmission.status`.

## 3. Roles and allowed actions per stage

| Stage | Actor | Actions |
|---|---|---|
| (pre-workflow) | Program Manager | Create/reassign `RequirementAssignment` |
| `draft` | Employee (assignment's department, or specifically assigned employee) | Upload/remove files, edit comment, submit |
| `pending_department_manager` | Department Manager (same department) | Approve → auditor; Reject (reason required) → employee |
| `pending_auditor` | Auditor (program-scoped) | Approve → program manager; Reject (reason required) → employee |
| `pending_program_manager` | Program Manager (program-scoped) | Approve → **final** approved; Reject (reason required) → employee |
| any pending stage | Employee | Request extension (goes to Auditor only) |

## 4. Concurrency and idempotency

Every transition in `WorkflowService` is wrapped in `DB::transaction()` with a
row lock (`lockForUpdate()`) on the `EvidenceSubmission` row, and re-checks
the current `status`/`current_stage` against the expected precondition before
writing — a stale request (two reviewers acting on the same submission, or a
reviewer acting after the employee already resubmitted) fails with a
`WorkflowConflictException` mapped to HTTP 409, not a silent overwrite.

See `docs/evidence-versioning.md` and `docs/extension-request-workflow.md`
for the two areas expanded separately.

## 5. API routes (all under `/api/v1/programs/{program}/`, `program.access` middleware)

```
GET|POST   assignments[/{assignment}]
PUT        assignments/{assignment}
POST       assignments/{assignment}/reassign
GET        assignments/{assignment}/history
POST       assignments/{assignment}/draft
POST|GET   assignments/{assignment}/extension-requests
GET        my-requirements
GET        evidence-submissions/{submission}[/timeline]
POST       evidence-submissions/{submission}/files
POST       evidence-submissions/{submission}/submit
DELETE     evidence-files/{file}
GET        evidence-files/{file}/download
POST       extension-requests/{extensionRequest}/cancel
GET|POST   reviews/{department-manager|auditor|program-manager}[/{submission}/approve|reject]
GET|POST   reviews/auditor/extension-requests[/{id}/approve|reject]
GET|PUT    sla-settings
GET        requirements-template
GET        requirements-imports
POST       requirements-import/preview
POST       requirements-import/{importLog}/confirm
GET        requirements-import/{importLog}/error-report
GET        dashboards/{program-manager|department-manager|auditor|employee}
GET        reports/{overdue-requirements|sla-breaches|extension-requests|rejection-frequency|employee-performance}
```

Platform-level (not program-scoped): `GET|PUT /api/v1/admin/email-templates[/{id}]`,
`POST .../{id}/preview`, `POST .../{id}/test-send` (Super Admin only).

## 6. Implementation status

Fully implemented, tested (82 backend tests passing — see the final Phase 2
report for the full list), and verified live end-to-end through the actual
browser UI: assignment → draft → upload → submit → Department Manager
approve/reject → Auditor approve/reject → Program Manager final approve/
reject → resubmission restarting at Department Manager; extension request →
Auditor-only decision; SLA instance open/close/breach detection; XLSX
template generation and import validation/confirm; all five role
dashboards; core reports; bilingual UI (Arabic RTL / English LTR).

Deferred technical debt is listed in the final Phase 2 completion report,
section 42.
