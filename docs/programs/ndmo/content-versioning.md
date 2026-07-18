# NDMO — Content Versioning

Uses the exact same `ComplianceContentVersion` model built in Phase 6 for
ECC — **zero new code this phase.** NDMO's content version row
(`DEV-TEST-V1`) was created through the identical model/service path,
proving the content-versioning engine is genuinely program-agnostic, not
ECC-specific infrastructure that happened to also work for NDMO.

`assessment_cycles.content_version_id` ties NDMO's cycle to exactly one
content version for its entire lifetime.

## Historical-cycle protection — proven again, for a new program

`NDMOProgramEngineTest::test_updating_content_version_does_not_alter_a_historical_ndmo_cycle`
creates a V2 content version after NDMO's cycle already exists on V1 and
asserts the cycle's `content_version_id` is unchanged.

## Not built this phase (same gap as ECC, unchanged)

Version comparison reporting, a dedicated "create cycle against version
X" flow, and import-driven content-version creation are not built — see
`docs/programs/ecc/content-versioning.md` for the full reasoning, which
applies identically to NDMO.
