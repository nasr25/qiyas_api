# NDMO — Overview

NDMO (National Data Management Office / مكتب إدارة البيانات الوطنية, code
`NDMO`) is the platform's fourth active compliance program, added entirely
through the generic Compliance Engine built across Phases 4-6. It proves
the engine genuinely generalizes: NDMO uses a five-level hierarchy (Domain
→ Policy → Standard → Requirement → Subrequirement) — a different depth
and different level names than ECC's four levels (Domain → Subdomain →
Control → Subcontrol) — with **zero changes** to `ComplianceNodeService`,
`ComplianceContentVersion`, or any workflow/evidence/review/SLA/extension/
notification/dashboard/report class.

## What exists today

- The `NDMO` `compliance_programs` row (migration
  `2026_07_22_000003_seed_ndmo_compliance_program.php`).
- Independent program configuration (terminology, hierarchy — 5 levels,
  workflow, extensions, evidence, assignment, **responsibilities — new
  this phase**, import, features with scoring/Not-Applicable/assessment-
  result all disabled).
- A new, generic **responsibility engine** (`ComplianceResponsibility` /
  `ResponsibilityService`) — Data Owner/Data Steward labels that never
  grant workflow authority — available to any program, enabled for NDMO.
- Optional, unpopulated evidence-classification metadata column
  (`evidence_files.classification_metadata`) — prepared, not enforced; no
  values invented.
- An independent workflow definition, reusing the exact same
  `WorkflowService` all three other programs use.
- Eight NDMO test accounts (including two Data Owner/Data Steward test
  people) plus a quad-program cross-program role scenario.
- One seeded active test cycle, one development content version, and a
  small five-level test hierarchy (1 requirement + 1 subrequirement, with
  a sample Data Owner/Data Steward assignment). **No official NDMO
  domains, policies, standards, requirements, subrequirements, evidence
  descriptions, or scoring formula exist in this repository.**

## What is NOT in this phase

ISO 27001, ISO 22301, COBIT, additional compliance programs, official
NDMO regulatory content, an approved NDMO scoring formula, a Not-
Applicable approval workflow, a formal assessment-result model
(compliant/partially_compliant/non_compliant), data-classification value
enforcement, automatic cross-program control mapping, SMS/WhatsApp/Teams
notifications.

## Proof of generic reuse (the point of this phase)

`docs/programs/ndmo/hierarchy.md` documents the exact test evidence that
the SAME `ComplianceNodeService` validates NDMO's five-level shape
correctly (rejecting an NDMO node under an ECC parent, rejecting an NDMO
node on a Qiyas cycle, enforcing NDMO's own `parent_type` rules) with no
NDMO-specific code anywhere. `compliance:verify-program NDMO` and
`compliance:verify-cross-program` (now checking all 6 pairs across four
programs) both worked correctly the first time they were run against
NDMO, with zero code changes beyond the two explicitly-new metrics this
phase added (responsibility counts).
