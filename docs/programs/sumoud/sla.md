# Sumoud — SLA

Uses the same per-program `SlaSetting`/`SlaInstance`/`SlaService` classes
Qiyas uses (already generic since Phase 1/2 — zero code change this
phase). Sumoud's row is created independently on first access via
`SlaService::settingsFor($sumoudProgram)`; no Qiyas SLA row was copied.

Development defaults (organizational defaults, not official regulatory
values — no approved Sumoud SLA policy exists):

- Employee submission / Department Manager review / Auditor review /
  Program Manager review: same value/unit shape as the platform-wide
  `SlaSetting` model defaults.
- Business-day/working-hours/timezone/warning-threshold/pause behavior:
  same fields as Qiyas, independently stored.

## Independence proof

`SumoudProgramEngineTest::test_sumoud_sla_settings_are_independent_from_qiyas`
mutates Sumoud's `employee_submission_sla_value` at runtime and asserts
Qiyas's own SLA row is unaffected.

## Not built this phase

Deterministic time-travel SLA Playwright tests (warning/breach detection
watched through a real browser session) were not built — same gap already
documented for Qiyas in Phase 4's `known-issues.md`, unchanged by Sumoud.
SLA correctness is verified at the PHPUnit level only this phase.
