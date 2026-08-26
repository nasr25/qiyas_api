# Review Engine

No changes in Phase 4 — the coupling analysis
(`docs/compliance-engine-migration.md`, finding #11) confirmed
`ReviewQueueController` was already exactly the "Review Engine" the brief
describes: one shared abstract base class implementing `index()`/
`approve()`/`reject()`/`decide()`, with `DepartmentManagerReviewController`
/`AuditorReviewController`/`ProgramManagerReviewController` supplying only
`stage()`, `ability()`, `roleLabel()`, and `scopeToReviewer()` — no
duplicated approval logic exists to consolidate.

`WorkflowService::decide()` (the actual state-transition logic underlying
every review decision) is covered in `docs/workflow-engine.md`.

## What a future program's review stages reuse

A future program with a different stage sequence reuses
`ReviewQueueController` and `WorkflowService::decide()` unchanged — the
per-stage-role authorization already comes from `hasProgramRole($program,
$roleKey)` (generic), and the transition target now comes from
`workflow_transition_definitions` (see `docs/workflow-engine.md`), not a
per-stage subclass method. Adding a new review stage for a future program
is a data change (a new `workflow_stage_definitions` row + transitions)
plus one small controller subclass supplying that stage's role/label — not
a new copy of the approval logic.

## Verified

Every existing Phase 2/3 review test (department manager/auditor/program
manager approve/reject, concurrency conflict, cross-department/cross-
program isolation) passes unchanged after the Phase 4 refactor — see
`docs/compliance-engine-migration.md`'s "run the full existing test suite"
verification step. The Playwright rejection-journey suite additionally
exercises all three stages' rejection paths through real UI actions,
confirming a genuinely new finding along the way: opening a returned
requirement auto-creates a new draft version whose own decision list is
empty, which silently hid the rejection reason from the Employee — fixed,
see `docs/compliance-engine-known-issues.md`.
