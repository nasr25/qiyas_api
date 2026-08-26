# Dashboard and Reporting Engine

## What changed in Phase 4 — partial, by design

New `DashboardMetricsService` with four reusable, program-scoped
count-builders: `submissionStatusCounts()`, `overdueAssignmentCount()`,
`upcomingDeadlineCount()`, `assignedRequirementIds()`. Adopted by
`WorkflowDashboardController::programManager()`, replacing its private
`statusCounts()` method and three inline query expressions with calls to
the shared service.

## What did not change — and why, explicitly

The coupling analysis (`docs/compliance-engine-migration.md`, finding #6)
found **four** dashboard controllers (`DashboardController` — legacy
pre-Phase-1 flat routes, `ExecutiveDashboardController`,
`ProgramDashboardController` — Phase 1 taxonomy view,
`WorkflowDashboardController` — Phase 2 workflow-stage view) and **three**
report controllers, each independently constructing similar count
queries. Consolidating all seven onto one shared engine was explicitly
scoped as "partial" going into this phase, not attempted in full: each of
those controllers serves a genuinely different audience and existing,
tested, live-verified consumers (legacy bookmarked URLs, the Executive
dashboard, per-role Phase 2 dashboards). Rewriting all of them under time
pressure in a phase already focused on workflow/configuration risked
breaking working code for cosmetic consolidation. This is reported
honestly as **not done**, not glossed over — see
`docs/compliance-engine-known-issues.md` for the explicit recommendation
to complete this in a dedicated future pass with its own regression
coverage.

`WorkflowReportController` (the five Phase 2 report endpoints) was not
touched this phase; it was already program-scoped and authorization-
correct per Phase 3's review.

## Verified

`WorkflowDashboardController::programManager()`'s response shape and
values are unchanged after adopting `DashboardMetricsService` — confirmed
by the pre-existing dashboard-consuming tests passing unchanged, and by
the Playwright full-lifecycle test's final step, which calls this exact
endpoint after a real approval and asserts `status_counts.approved >= 1`
against live data, not a mock.
