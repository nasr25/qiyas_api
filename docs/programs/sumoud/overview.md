# Sumoud — Overview

Sumoud (صمود, code `SUMOUD`) is the platform's second active compliance
program, added in Phase 5 entirely through the generic Compliance Engine
built in Phase 4. No Qiyas controller, service, workflow class, SLA class,
evidence class, notification class, dashboard class, or authorization class
was copied to make Sumoud work.

## What exists today

- The `SUMOUD` `compliance_programs` row (migration
  `2026_07_20_000001_seed_sumoud_compliance_program.php`, mirroring exactly
  how `QIYAS` itself was bootstrapped).
- Independent program configuration (terminology, workflow, extensions,
  evidence, assignment, import, features) — see `configuration.md`.
- An independent workflow definition — see `workflow.md`.
- Program-scoped roles for six dedicated Sumoud test accounts plus three
  cross-program role scenarios — see `roles.md`.
- One seeded active test cycle plus two development-only sample
  requirements — see `test-data.md`. **No official Sumoud domains,
  controls, requirements, evidence descriptions, or scoring formula exist
  in this repository.** Everything under a Domain/Category/Requirement in
  Sumoud today is explicitly test content, never presented as regulatory.

## What is NOT in this phase

ECC, NDMO, ISO 27001, ISO 22301, COBIT, official Sumoud regulatory content,
an approved Sumoud scoring formula, additional approval levels, RACI,
cross-framework evidence sharing, SMS/WhatsApp/Teams notifications.

## Proof of generic reuse

`backend/docs/compliance-engine-migration.md` (Phase 4) already showed 6 of
14 engine areas needed zero code change to become program-agnostic. Phase 5
found and closed the remaining real gaps — see
`known-limitations.md` and the main Phase 5 report for the full list
(cycle write-endpoint fallback, hardcoded legacy cycle links, spatie-
permission-only hierarchy/department authorization, and the frontend
router/nav global-role blindness) — every one of them was a genuine
pre-existing engine defect, not something Sumoud-specific was built around.
