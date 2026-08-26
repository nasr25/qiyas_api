# NDMO — Reports

`ProgramReportController`/`WorkflowReportController` already resolve the
program from the authorized `{program}` route parameter — reused
unmodified for NDMO, confirmed reachable in the lifecycle test and
correctly denied to an unauthorized user in
`tests/e2e/cross-program/ndmo-isolation.spec.ts`.

Report exports enforce the same `authorizeManage()`/program-membership
checks as the on-screen reports — no separate, weaker export path.

## Not built this phase

Hierarchy-grouped reports (by Domain/Policy/Standard), responsibility-
assignment reports, and content-version history reports are not
implemented — same category of gap noted for ECC.
