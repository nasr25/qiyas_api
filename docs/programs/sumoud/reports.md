# Sumoud — Reports

`ProgramReportController`/`WorkflowReportController` (requirement status,
department performance, overdue, SLA breaches, extension requests,
rejection frequency, employee performance) already resolve the program from
the authorized `{program}` route parameter — reused unmodified for Sumoud.

Report exports enforce the same `authorizeManage()`/program-membership
checks as the on-screen reports (same controller, same method) — no
separate, weaker authorization path exists for exports.

## Not built this phase

Sumoud-specific report-content Playwright assertions were not built;
report correctness is exercised indirectly through the Sumoud lifecycle
test's final "approved report inclusion" check
(`GET /programs/SUMOUD/reports/overdue-requirements` returns 200 after the
lifecycle completes) rather than asserted row-by-row.
