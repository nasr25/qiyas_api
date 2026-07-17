# Assignment Engine

`RequirementAssignment` + `WorkflowService::assign()` /
`reassignDepartment()` / `updateAssignmentDetails()` were already generic
before Phase 4 — no changes were needed to the engine itself (see the
coupling analysis in `docs/compliance-engine-migration.md`, which found no
Qiyas-specific conditional logic here).

## Program-configured assignment rules

Category `assignment` (see `docs/program-configuration.md`):
`department_required`, `employee_assignment_required`,
`reassignment_reason_required`, `due_date_required`. Qiyas's seeded values
match its actual enforced behavior — `department_required: true` (the
service signature requires a `Department`), `employee_assignment_required:
false` (the `?User $employee` parameter is nullable),
`reassignment_reason_required: true` (`reassignDepartment()` requires a
non-empty `string $reason`), `due_date_required: false` (`?string $dueDate`
is nullable). These flags are **not yet read anywhere** — they describe
the current Qiyas behavior for a future program's reference/validation
layer, but `WorkflowService`'s own method signatures are still the actual
enforcement mechanism. Documented as a real gap, not implied to be wired
up. See `docs/compliance-engine-known-issues.md`.

## Guarantees preserved (all pre-existing, all still tested)

- One active assignment per requirement — `lockForUpdate()` inside
  `DB::transaction()` in `assign()`, verified by
  `test_cannot_double_assign_the_same_requirement`.
- Reassignment preserves history via `previous_assignment_id`, revokes the
  old department's implicit access (the old row becomes `status:
  'reassigned'`, no longer matched by any `active()` scope), verified by
  `test_reassignment_preserves_history_and_revokes_old_department`.
- SLA initialization on assignment — `SlaService::openInstance()` called
  from within `assign()`'s transaction.

## Discovered during Phase 4 E2E testing (fixed)

`RequirementAssignmentsView.vue`'s creation form has no Employee-selection
field at all — "optionally assign a specific Employee" is only reachable
via `updateAssignmentDetails()`'s API, not through this UI. This is a real,
confirmed frontend gap (not a backend limitation), discovered while writing
the mandatory Playwright lifecycle test, and left as-is rather than
patched under time pressure — see
`docs/compliance-engine-known-issues.md`.

`ProgramRequirementController::index()` (the endpoint this form's
requirement dropdown reads from) returned a nested Laravel paginator object
instead of a flat array — the same defect class fixed on other controllers
in Phase 2/3, but missed on this one, so the dropdown silently appeared
empty. **Fixed** — see `docs/qiyas-workflow.md` §6 and the regression test
`tests/Feature/Engine/ProgramRequirementListShapeTest.php`.
