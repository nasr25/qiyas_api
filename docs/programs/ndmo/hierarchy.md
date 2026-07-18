# NDMO — Flexible Hierarchy

## The proof this phase was built to deliver

Phase 6 built `ComplianceNode` generically for ECC's four-level shape
(Domain → Subdomain → Control → Subcontrol). Phase 7's NDMO uses a
**different** five-level shape — Domain → Policy → Standard → Requirement
→ Subrequirement — configured purely through NDMO's own `hierarchy`
program-configuration category, with **no code change** to
`ComplianceNodeService`, the `compliance_nodes` table, or the
`HierarchyExplorerView.vue` frontend component. This is the concrete
evidence that the hierarchy engine is genuinely generic, not
ECC-shaped-with-NDMO-bolted-on.

## Levels

| Level | node_type | parent_type | is_assessable |
|---|---|---|---|
| 0 | domain | *(root)* | no |
| 1 | policy | domain | no |
| 2 | standard | policy | no |
| 3 | requirement | standard | **yes** |
| 4 | subrequirement | requirement | **yes** |

Both Requirement and Subrequirement are assessable so an approved source
that stops at Requirement level still works — the same design choice made
for ECC's Control/Subcontrol.

## Domains, Policies, Standards, Requirements, Subrequirements

Each level is a `ComplianceNode` row: `code`, bilingual `name_ar`/`name_en`,
`description_ar/en`, `guidance_ar/en`, `sort_order`, `status`, `metadata`.
Only Requirement and Subrequirement (the assessable levels) additionally
carry `evidence_requirements_ar`, `weight`, and a default `due_date`
(via the bridged `standards` row — see below). No official NDMO
domain/policy/standard/requirement/subrequirement content exists; the
seeded test hierarchy uses exactly one of each, named unmistakably as
test content (`مجال تجريبي لحوكمة البيانات`, `سياسة تجريبية`, `معيار
تجريبي`, `متطلب تجريبي`).

## Assessable and assignable items

"Assessable" (`is_assessable=true`) and "assignable" are the same concept
in the current engine — an assessable node is exactly the kind of node
`RequirementAssignmentController` can assign, because it is the kind of
node that gets bridged into `standards`. The brief's `is_assignable`
field was evaluated and judged redundant with `is_assessable` for this
phase's actual assignment mechanism (which operates on the bridged
`Standard`, not on `ComplianceNode` directly) — not implemented as a
separate column to avoid a distinction with no behavioral difference yet.
Documented here as a deliberate simplification, not an oversight.

## Validation — proven against a NEW pair of programs

`NDMOProgramEngineTest.php` proves the exact validations the brief
requires, using ECC (not just Qiyas) as the "foreign program" this time —
demonstrating the check is genuinely program-agnostic, not hardcoded to
any specific pair:

- `test_invalid_parent_child_type_pairs_are_rejected` — a `requirement`
  cannot be created directly under a `domain`.
- `test_ndmo_node_cannot_use_an_ecc_parent_or_a_qiyas_cycle` — an NDMO
  node cannot use an ECC node as its parent.
- `test_ndmo_node_cannot_use_a_qiyas_cycle` — an NDMO node cannot use a
  Qiyas cycle.
- `test_ndmo_hierarchy_supports_five_configured_levels` — all five levels
  create correctly, each assessable node bridges into `standards`.

Circular-hierarchy and maximum-depth checks are the same generic logic
proven for ECC in Phase 6, re-exercised here via `compliance:verify-
program NDMO` (clean on the real dataset) rather than duplicated as new
NDMO-specific tests, since the logic itself is provably program-agnostic
(reads `hierarchy.max_depth` from configuration, not a constant).

## Why a bridge, not a rewrite (unchanged reasoning from Phase 6)

See `docs/programs/ecc/hierarchy.md`, "Why a bridge, not a rewrite" — the
same architecture, same tradeoffs, apply unchanged to NDMO's deeper five-
level shape.
