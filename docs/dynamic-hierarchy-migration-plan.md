# Dynamic Hierarchy — Migration Plan and Outcome

> **Status: executed and verified.** This records what was actually done,
> not a proposal. Rollback was tested in isolation — see
> [`dynamic-hierarchy-rollback.md`](dynamic-hierarchy-rollback.md).

**Status: executed.** This records what was actually done, not a proposal.

## Decision that shaped everything

The platform owner confirmed on 2026-08-25 that **existing Qiyas/Sumoud
hierarchy and requirement data is disposable** and must not be migrated.
That removed the highest-risk item in the original audit — de-duplicating 96
standards carrying 13 free-text perspective strings and 27 axis strings by
exact string match, where typos would have produced spurious nodes.

Consequently: **no legacy backfill, no legacy mapping table, no duplicate
cleanup, no dry-run migration** was built. The engine was built clean and
test content recreated.

## What was preserved vs cleared

Enforced by `compliance:reset-hierarchy-content`, which lists both sets
explicitly in code so the guarantee is reviewable, prints a dry run, and
refuses to run outside local/testing without `--force`.

**Preserved** (verified by count before and after): users (43), departments
(5), program memberships (51), programs (4), roles, permissions,
program configurations, settings, branding, SMTP, email templates, audit
logs, workflow definitions, SLA settings.

**Cleared** (449 rows): compliance nodes, standards, department_standard,
documents, document versions, evidence requirements, requirement
assignments, evidence submissions, evidence files, workflow decisions,
workflow events, SLA instances, extension requests, comments, notifications,
import logs, assessment cycles, content versions.

## Table mapping

| Before | After |
|---|---|
| `standards.perspective` (VARCHAR) | `compliance_nodes` at level 1 |
| `standards.axis` (VARCHAR) | `compliance_nodes` at level 2 |
| `standards` row | `compliance_nodes` at the assessable level |
| `hierarchy` program-configuration JSON | `hierarchy_definitions` + `hierarchy_level_definitions` |
| *(nothing)* | `program_structure_versions` |
| `requirement_assignments.requirement_id → standards` | `.compliance_node_id → compliance_nodes` |
| `evidence_submissions.requirement_id → standards` | `.compliance_node_id → compliance_nodes` |
| `compliance_nodes.standard_id` (the mirror) | **dropped** |
| `standards.compliance_node_id` (never written) | **dropped** |

`standards`, `documents` and `evidence_requirements` **still exist**. They
back the legacy Qiyas document path, which is now orphaned from the workflow
engine — see "Remaining work".

## Per-program outcome

| Program | Structure | Nodes | Assignments |
|---|---|---|---|
| SUMOUD | 3 levels, v1 | 14 | 4 at `requirement` |
| QIYAS | 5 levels, v1 | 30 | 4 at `criterion` |
| ECC | 5 levels, v1 | 30 | 4 at `control` |
| NDMO | 6 levels, v1 | 38 | 4 at `requirement` |

Assignments land at genuinely different depths per program — the case the
old two-level mirror could not represent.

## Migrations applied

1. `create_hierarchy_definitions_table`
2. `create_hierarchy_level_definitions_table`
3. `create_program_structure_versions_table`
4. `add_dynamic_hierarchy_columns_to_compliance_nodes`
5. `repoint_workflow_tables_to_compliance_nodes`
6. `drop_standards_mirror_columns`
7. `widen_import_log_template_version`

Applied additively: new tables and columns first, consumers cut over one at
a time, mirror columns dropped **last** — only after
`compliance:verify-hierarchy` reported zero rows relying on them.

## Verification after migration

```
compliance:verify-hierarchy        — all checks passed, all 7 programs
compliance:verify-cross-program    — no contamination detected
compliance:verify-migration        — all integrity checks passed
php artisan test                   — 273/273
```

## Rollback

Tested in isolation; two real bugs found and fixed. See
[`dynamic-hierarchy-rollback.md`](dynamic-hierarchy-rollback.md).

## Remaining work

**The legacy Standard-authoring UI is orphaned.** `CycleDetailView` can still
create a `Standard` with free-text perspective/axis, but a Standard can no
longer be assigned — assignment requires a ComplianceNode. Requirement
authoring should now go through the hierarchy explorer or XLSX import, and
the legacy screens retired. Until then those screens can create records that
cannot enter a workflow.

This is why **9 pre-existing E2E lifecycle tests fail** (Qiyas 6, Sumoud 1,
ECC 1, NDMO 1) — they author a Standard through the old UI and then try to
assign it. The failure is correct behaviour meeting an obsolete test, but
the tests have not been rewritten and the legacy path has not been removed,
so this is **unfinished, not merely cosmetic**.
