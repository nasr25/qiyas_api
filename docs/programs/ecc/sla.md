# ECC — SLA (Operational, Not Official)

Uses the same per-program `SlaSetting`/`SlaInstance`/`SlaService` classes
Qiyas/Sumoud use (already generic since Phase 1/2 — zero code change).
ECC's row is created independently on first access via
`SlaService::settingsFor($eccProgram)`.

**These are organizational operational settings, not official ECC
requirements.** No approved ECC SLA policy has been supplied.

Covers all four stages (Employee submission, Department Manager review,
ECC Auditor review, ECC Program Manager final review), business/calendar
time, working days/hours, timezone, warning thresholds, pause/resume,
snapshots — identical mechanism already proven independent for Sumoud.

Changing ECC's SLA settings does not affect Qiyas's or Sumoud's rows (same
independent-row guarantee, unchanged mechanism).

## Not built this phase

Deterministic time-travel SLA Playwright tests were not built for ECC —
same documented gap left open for Qiyas (Phase 4) and Sumoud (Phase 5).
SLA correctness is exercised at the PHPUnit level only.
