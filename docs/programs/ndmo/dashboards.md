# NDMO — Dashboards

`WorkflowDashboardController::programManager()` (used by
`/programs/NDMO/dashboards/program-manager`) already reads through
`DashboardMetricsService`, program-scoped by the resolved
`ComplianceProgram` — zero code change needed for NDMO, proven by the
NDMO lifecycle test's dashboard assertion after final approval.

## Known limitation (carried from Phase 4-6, not new)

Department Manager/Auditor/Employee dashboards still duplicate count-
query logic in spirit rather than routing through `DashboardMetricsService`.
Progress-by-domain/policy/standard (hierarchy-grouped metrics the brief
requests) is not implemented — same gap noted for ECC.
