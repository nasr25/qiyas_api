# NDMO — SLA (Operational, Not Official)

Uses the same per-program `SlaSetting`/`SlaInstance`/`SlaService` classes
every other program uses (already generic since Phase 1/2 — zero code
change). NDMO's row is created independently on first access via
`SlaService::settingsFor($ndmoProgram)`.

**These are internal organizational operational settings, not official
NDMO requirements.** No approved NDMO SLA policy has been supplied.

Covers all four stages, business/calendar time, working days/hours,
timezone, warning thresholds, pause/resume, snapshots — identical
mechanism already proven independent for Sumoud and ECC. Changing NDMO's
SLA settings does not affect any other program's rows.

Deadline tracking (original/effective due date, days remaining/overdue,
current responsible role/department/user) is the same unchanged
`RequirementAssignment` logic — reviewer delay is never attributed to the
Requirement Owner, same guarantee as every other program.

## Not built this phase

Deterministic time-travel SLA Playwright tests were not built for NDMO —
same documented gap left open for the three other programs.
