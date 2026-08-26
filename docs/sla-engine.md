# SLA Engine

No changes in Phase 4. The coupling analysis
(`docs/compliance-engine-migration.md`, finding #12) confirmed
`SlaService`/`SlaSetting`/`SlaInstance` were already fully program-agnostic
before this phase — no `$program->code` branching anywhere, every
calculation scoped by `SlaSetting::forStage()` reading a program-scoped
settings row, `settings_snapshot` preserving historical accuracy against
later configuration changes. See `docs/sla-design.md` (Phase 2) for the
full design — it is unchanged and still accurate.

## What Phase 4 did touch

`ProcessSlaCommand`'s notification dispatch calls
(`notifyResponsible()`/the overdue-detection loop) are now wrapped in
`try/catch`, logging failures instead of letting one bad recipient/mailer
error abort the entire scheduled batch — discovered via Phase 4 E2E
testing (a synchronous SMTP connection failure was crashing whole HTTP
requests; the same class of fragility existed in the scheduled command,
just less visible since it runs unattended). See
`docs/notification-engine.md` and
`docs/compliance-engine-known-issues.md`.

## Verified

All pre-existing SLA tests (`test_sla_instance_opens_for_employee_stage_on_assignment`,
`test_sla_completes_when_stage_ends_and_next_stage_opens`,
`test_scheduled_command_detects_breach_and_does_not_duplicate_on_second_run`,
`test_reviewer_delay_is_not_attributed_to_employee`) pass unchanged.
`docs/qiyas-production-readiness.md` (Phase 3) already documented that SLA
time-travel/pause-resume Playwright scenarios specifically were not
exercised with real time manipulation — that remains true in Phase 4; no
fake-clock E2E harness was added this phase either. See
`docs/compliance-engine-known-issues.md`.
