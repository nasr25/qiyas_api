# NDMO — Notifications

Uses the exact same domain-event pipeline built in Phase 4
(`WorkflowNotificationRequested` + `SendWorkflowNotification`) — no NDMO
event, listener, or template class exists. Every notification carries
`$assignment->program`, so an NDMO action renders with NDMO's program
name/code and resolves an action URL under `/programs/NDMO/...`
automatically.

## Not built this phase

- Literal notification-content Playwright assertions for NDMO's named
  events — same documented gap as the three other programs.
- The "Responsibility assigned" and "Content version activated"/"Cycle
  activated"/"Cycle closed" notification events named in the brief are
  not wired to the domain-event pipeline this phase — only the existing
  workflow-stage events (assignment/submission/review/extension/SLA) fire
  for NDMO, identical to the other three programs.
