# ECC — Dashboards

`WorkflowDashboardController::programManager()` (used by
`/programs/ECC/dashboards/program-manager`) already reads through
`DashboardMetricsService`, program-scoped by the resolved
`ComplianceProgram` — zero code change needed for ECC, proven by the ECC
lifecycle test's dashboard assertion after final approval.

## Known limitation (carried from Phase 4/5, not new)

Department Manager/Auditor/Employee dashboards and report controllers
still duplicate count-query logic in spirit rather than routing through
`DashboardMetricsService` — documented already; correctly program-scoped
(each resolves the acting program from the route), just not consolidated.
Progress-by-domain/subdomain (a Phase 6-specific dashboard metric the
brief requests) is not implemented — `DashboardMetricsService` has no
awareness of `ComplianceNode` hierarchy grouping yet.
