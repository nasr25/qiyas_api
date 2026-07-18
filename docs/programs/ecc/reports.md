# ECC — Reports

`ProgramReportController`/`WorkflowReportController` already resolve the
program from the authorized `{program}` route parameter — reused
unmodified for ECC, confirmed reachable (200 OK) in the ECC lifecycle test
and correctly 403/404 for an unauthorized user in
`tests/e2e/cross-program/ecc-isolation.spec.ts`.

Report exports enforce the same `authorizeManage()`/program-membership
checks as the on-screen reports (same controller, same method) — no
separate, weaker export path.

## Not built this phase

Domain/Subdomain-grouped reports (a hierarchy-aware breakdown) are not
implemented — same gap noted in `dashboards.md`. Content-version history
reporting is not implemented — see `content-versioning.md`.
