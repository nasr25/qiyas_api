# Qiyas — Phase 3 Performance Review

This review is a **static code and query-pattern review**, not a load test —
no environment with realistic data volume (hundreds of employees, thousands
of standards/assignments) was available. Every claim below is either a
direct code observation or a measurement against the current dev database
(1 program, 97 standards, 12 assignments, ~7 submissions). See
`docs/qiyas-known-issues.md` for what a real load rehearsal would still need
to confirm.

## Database indexing — reviewed, adequate for the current schema

Every Phase 2 migration was checked for indexes matching its actual query
patterns:

- `requirement_assignments`: indexed on `(compliance_program_id,
  program_cycle_id)`, `(department_id, status)`, `(requirement_id, status)`,
  `employee_id`, `effective_due_date` — covers the department-scoped
  dashboard queries, the overdue-date range queries, and the
  one-active-assignment-per-requirement lookup used by
  `WorkflowService::assign()`.
- `evidence_submissions`: unique on `(requirement_assignment_id,
  version_number)` (also the mechanism that makes duplicate-version writes
  impossible at the DB level, not just in application code), indexed on
  `(compliance_program_id, status)`, `(department_id, status)`,
  `current_stage`.
- `sla_instances`: indexed on `(requirement_assignment_id, stage)` (used by
  `SlaService::closeActiveInstance()`'s lookup) and `(status, due_at)` (used
  by `ProcessSlaCommand`'s breach/warning scan).
- `notification_logs`: unique on `idempotency_key` — this is also the
  mechanism that makes duplicate notification delivery structurally
  impossible under concurrent/repeated dispatch, not merely discouraged.

No missing index was found for any query actually issued by the Phase 2
controllers/services/commands.

## N+1 query review

Reviewed every list/dashboard/report endpoint for eager loading:

- `MyRequirementsController::index()`, `ReviewQueueController::index()`,
  `RequirementAssignmentController::index()` all eager-load
  `requirement`/`department`/`employee`/`currentSubmission` before mapping —
  no N+1 in the paginated list endpoints employees and reviewers actually
  hit repeatedly.
- `WorkflowDashboardController::departmentComparison()` (used by the Program
  Manager dashboard) loops over `Department::active()->get()` and issues a
  fresh aggregate query per department. For the current 2-department demo
  dataset this is 2 extra queries; at a realistic scale of, say, 20-30
  departments this is 20-30 small indexed count queries per dashboard
  load — not N+1 in the classic per-row sense, but worth converting to a
  single grouped query if department count grows materially. Not fixed in
  Phase 3 (dashboard behavior change, out of scope for a hardening pass) —
  documented here as a forward-looking optimization, not a defect.

## Unbounded (non-paginated) result sets

`WorkflowReportController::overdueRequirements()`,
`::slaBreaches()`, and `::extensionRequests()` all return the **full**
matching result set as a flat array rather than a paginated page. At the
current data volume (tens of rows) this is fine and matches how a "report"
is typically consumed (export/filter client-side). At real scale — the
brief's "thousands of Qiyas standards and assignments" — an overdue-report
query with no cap could return thousands of rows in one response. This was
**not changed in Phase 3**: converting these to paginated responses is a
response-shape change that the frontend's report views would need
coordinated updates for (the same class of bug fixed in Phase 2's paginator
serialization issue — see `docs/qiyas-workflow.md` §6), and doing that
safely needs its own dedicated pass with frontend verification, not a rushed
retrofit under a hardening-focused phase. **Recommendation for the next
phase**: add pagination (or at minimum a hard row cap with a "narrow your
filters" message) to all three report endpoints before onboarding a program
with a genuinely large standard count.

Dashboard endpoints (`WorkflowDashboardController`) also use `->get()`
rather than pagination, but this is architecturally correct for a
dashboard — the whole point is a computed summary over the full active set,
not a list a user pages through. At extreme scale this would shift from an
N+1 concern to a "how many rows fit in memory to `groupBy()` in PHP"
concern; not a problem at any volume this platform is likely to see for a
single-digit-departments deployment, but worth revisiting if Sumoud/ECC
onboarding significantly increases per-program row counts.

## XLSX import/export memory behavior

- **Export** (`App\Exports\Hierarchy\HierarchyTemplateExport`): uses
  `Excel::download()`, which streams the generated file rather than
  building the full response body in memory first. The template is always
  small — column count is `(3 × levels) + enabled attributes`, currently
  14–26 columns across the programs in service, with a handful of rows.
  Measured template generation: 8.2–13.8 ms.
- **Import** (`HierarchyImportValidator::validate()`): calls `Excel::toArray()`,
  which loads the **entire** workbook into a PHP array. Combined with the
  existing `MAX_ROWS = 5000` cap and the 10 MB upload size limit already
  enforced in `HierarchyImportController::preview()`, worst case is a 5,000-row,
  array of at most 26 columns — a few megabytes, not a memory risk at the configured
  cap. If the row cap or upload size limit is ever raised significantly,
  this should move to `Excel::import()` with a chunked/queued reader instead
  of `toArray()`.
- **Evidence file downloads** (`EvidenceSubmissionController::downloadFile()`):
  uses `Storage::disk('private')->download()`, which streams the file
  directly from disk rather than reading it fully into memory — correct
  behavior, verified by reading the implementation (it does not call
  `Storage::get()` first).

## Frontend bundle

`npm run build` output (see below) shows route-level code splitting is
already in effect — each view (`MyRequirementsView`, `ReviewQueueView`,
`SlaSettingsView`, etc.) is its own small chunk (1–15 KB gzipped), not one
monolithic bundle. The largest shared chunks are `vendor` (~61 KB gzipped)
and `charts` (~54 KB gzipped, only loaded on dashboard/report pages that use
Chart.js) — reasonable for an internal line-of-business SPA, no bundle-size
concern identified.

```
dist/assets/index-*.js     96.36 kB │ gzip: 31.36 kB
dist/assets/charts-*.js   156.78 kB │ gzip: 54.40 kB
dist/assets/vendor-*.js   170.54 kB │ gzip: 61.37 kB
```

## Scheduler / queue job sizing

`ProcessSlaCommand` processes active SLA instances in `chunkById(200, ...)`
and overdue assignments in `chunkById(200, ...)` — bounded batch size, will
not load an unbounded result set into memory regardless of how many active
instances exist. Runs every 30 minutes per `routes/console.php`.

## Summary

No Critical or High performance defect was found in the current
implementation at current or near-term data volumes. The one concrete,
scale-relevant gap — unbounded report endpoints — is documented above with
a specific recommendation rather than silently left unaddressed. A genuine
load rehearsal at the brief's target scale (500 employees, thousands of
assignments) remains the single most valuable follow-up before treating
performance as "verified" rather than "reviewed."
