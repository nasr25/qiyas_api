# Sumoud — Dashboards

`WorkflowDashboardController::programManager()` (used by
`/programs/SUMOUD/dashboards/program-manager`) already reads through
`DashboardMetricsService`, program-scoped by the resolved `ComplianceProgram`
— zero code change needed for Sumoud.

`SumoudProgramEngineTest::test_dashboard_metrics_service_counts_are_program_scoped`
creates a Sumoud assignment and asserts Qiyas's independently-queried
dashboard counts are unaffected (`array_sum($qiyasCounts) === 0` in that
test's isolated fixture context).

## Known limitation (carried from Phase 4, not new)

Three other dashboard controllers (Department Manager, Auditor, Employee)
and the report controllers still duplicate count-query logic in spirit
rather than routing through `DashboardMetricsService` — documented already
in `docs/dashboard-reporting-engine.md`. They are correctly program-scoped
(each resolves `$request->attributes->get('compliance_program')`), just not
yet consolidated onto the shared metrics service. Not reopened this phase.
