# Sumoud — Notifications

Uses the exact same domain-event pipeline built in Phase 4:
`WorkflowService`/`ExtensionService` fire `WorkflowNotificationRequested`;
the sole listener `SendWorkflowNotification` resolves recipients and
renders bilingual templates. No Sumoud-specific event, listener, or
template class exists.

Every notification carries `$assignment->program` (the acting program),
so a Sumoud action always renders with Sumoud's program name/code and
resolves an action URL under `/programs/SUMOUD/...` — the frontend never
had a hardcoded "QIYAS" assumption in the notification action-URL builder
(confirmed by grep; no code change was needed here).

## Not specifically re-verified this phase

Literal notification-content Playwright assertions (recipient/language/
action-URL correctness for each of the ~13 named events) were not built
for Sumoud specifically — same gap already documented for Qiyas in Phase
4. State-level correctness (the right people get *a* notification, not
duplicated) is exercised indirectly by the Sumoud lifecycle test's
dashboard/history checks, not asserted on raw notification content.
