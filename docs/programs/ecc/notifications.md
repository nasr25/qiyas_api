# ECC — Notifications

Uses the exact same domain-event pipeline built in Phase 4
(`WorkflowNotificationRequested` + `SendWorkflowNotification`) — no ECC
event, listener, or template class exists. Every notification carries
`$assignment->program`, so an ECC action renders with ECC's program
name/code and resolves an action URL under `/programs/ECC/...`
automatically — no hardcoded "QIYAS"/"SUMOUD" assumption exists in the
notification action-URL builder (confirmed by grep, unchanged since
Phase 5).

## Not specifically re-verified this phase

Literal notification-content Playwright assertions for ECC's named events
were not built — same documented gap as Qiyas (Phase 4) and Sumoud
(Phase 5). State-level correctness is exercised indirectly by the ECC
lifecycle test's dashboard/history checks.
