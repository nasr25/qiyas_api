# Dynamic Compliance Structure — Architecture

> **Status: implemented, tested and measured.** Backend 271/271 · Playwright
> Chromium 231/231 · Firefox smoke 4/4 · WebKit **environment blocked, not
> tested**. Evidence: [`../docs/testing/dynamic-hierarchy-playwright.md`](testing/dynamic-hierarchy-playwright.md),
> [`performance-evidence.md`](performance-evidence.md),
> [`dynamic-hierarchy-rollback.md`](dynamic-hierarchy-rollback.md).

Companion to [`compliance-hierarchy-audit.md`](compliance-hierarchy-audit.md)
(what was wrong), [`hierarchy-data-model.md`](hierarchy-data-model.md) (the
tables) and [`hierarchy-versioning.md`](hierarchy-versioning.md) (revisions).

## The one-sentence change

Hierarchy **depth, terminology and behaviour moved out of PHP and into rows**
a Program Manager can edit, and every consumer — assignment, evidence,
workflow, dashboard, report, XLSX — now reads those rows instead of assuming
a shape.

## Before and after

```
BEFORE                                    AFTER
──────                                    ─────
standards                                 hierarchy_definitions
  ├─ perspective  VARCHAR ← "level 1"       └─ hierarchy_level_definitions  (depth = row count)
  ├─ axis         VARCHAR ← "level 2"            └─ program_structure_versions (frozen snapshot)
  └─ (the row)              ← "level 3"
                                          compliance_nodes
compliance_nodes  (ECC/NDMO only)           ├─ hierarchy_level_id  → the level it sits on
  └─ standard_id → standards                ├─ parent_id           → arbitrary depth
       ↑                                    └─ structure_version_id
       │ MIRROR: kept chain[0], chain[1]
       │ and DISCARDED everything deeper   requirement_assignments.compliance_node_id
       └─ audit finding C2                 evidence_submissions.compliance_node_id
```

The mirror is gone. There is exactly one representation of a requirement.

## Component map

```
HierarchyDefinitionService   structure CRUD, validation, impact, activation
HierarchyPathResolver        bulk ancestor resolution (one query, not N×depth)
ComplianceNodeService        node content writes, level-aware validation
HierarchyDashboardService    universal metrics + metadata-driven drill-down
HierarchyReportService       whitelisted dimensions, grouping, cascading filters
HierarchyImportValidator     structure-driven XLSX validation
HierarchyImportService       transactional, all-or-nothing import
HierarchyStructurePolicy     Program Manager scoped to their own program
```

Frontend:

```
ProgramStructureSettingsView   the Program Manager's editor
StructureAnalyticsView         metrics, drill-down, filters, dynamic report
HierarchyBreadcrumb            arbitrary depth, locale-aware
HierarchyFilter                cascading, generated from filterable levels
```

## The four rules that keep it generic

1. **Never test a level by name.** Ask the level definition: `isAssignable()`,
   `isAssessable()`, `acceptsEvidence()` on `ComplianceNode` resolve
   override → level → false. No caller may read `is_assessable` directly.
2. **Never index a path positionally.** `$chain[0]` / `$chain[1]` is the exact
   defect that caused audit finding C2. Use `pathLabels()` / `breadcrumb()`,
   which return whatever depth exists.
3. **Never add a route per level.** `/dashboard/by-level/{levelKey}` and
   `/reports/filter-options/{levelKey}` take the level as a parameter. The
   old `/domains` + `/categories` pair *was* a two-level assumption encoded
   in the URL space.
4. **Never let a hierarchy filter widen authorization.** Department and
   program scoping are applied last and unconditionally in both the
   dashboard and report services.

## The full chain

```
ComplianceProgram
  └── ProgramStructureVersion        frozen snapshot, pinned by each cycle
        └── HierarchyLevelDefinition depth = row count; ~20 behaviour flags
              └── ComplianceNode     the content; parent_id = arbitrary depth
                    ├── RequirementAssignment  → compliance_node_id
                    │     └── EvidenceSubmission → Workflow → SLA → Extension
                    ├── Dashboard    groups by dashboard-visible levels
                    ├── Reports      groups/filters by report-visible levels
                    └── XLSX         template/import/export columns per level
```

Every consumer reads the level definitions. None assumes a depth.

## Live structures

| Program | Depth | Levels |
|---|---|---|
| Sumoud | 3 | Domain → Category → Requirement |
| Qiyas | 5 | Perspective → Axis → Criterion → Application Requirement → Evidence Requirement |
| ECC | 5 | Main Domain → Subdomain → Control → Subcontrol → Implementation Requirement |
| NDMO | 6 | Domain → Policy → Standard → Requirement → Subrequirement → Control Activity |
| TEST3 / TEST5 / TEST7 | 3 / 5 / 7 | Depth-proof fixtures |
| TESTX | 5 | Reserved for structure-MUTATING tests, so they never poison a shared fixture |

All eight were built by the **same** code path. `HierarchyStructureSeeder`
and `TestHierarchyFixtureSeeder` contain no per-program branch; the fixtures
differ only by a depth number.

## What a level definition controls

| Group | Fields | Effect |
|---|---|---|
| Terminology | `name_ar`, `name_en`, `plural_name_ar`, `plural_name_en`, `icon` | Every visible label, resolved per locale |
| Ordering | `level_order`, `parent_level_id` | Depth position and the parent chain |
| Structure | `is_required`, `is_active`, `allow_children` | Whether a level must be filled, is enabled, may nest |
| Behaviour | `is_assignable`, `is_assessable`, `accepts_evidence` | What work may happen there — enforced backend-side |
| Surfaces | `appears_in_dashboard`, `appears_in_reports`, `appears_in_filters`, `appears_in_breadcrumb` | Where the level shows up |
| Form fields | `code_required`, `description_enabled`, `objective_enabled`, `weight_enabled`, `due_date_enabled`, `instructions_enabled` | Which inputs the node form renders, and which the API accepts |

A level may legitimately be **assignable without accepting evidence** — a
Qiyas Criterion groups the Application Requirements that carry the files.
The engine models that distinction rather than assuming assignment and
evidence coincide.

## Test fixtures

`php artisan compliance:seed-test-fixtures`

Creates TEST3 / TEST5 / TEST7, each with its own structure version, active
cycle, node tree, two departments, and a Program Manager, Auditor,
Department Manager and two Employees — plus assignments, evidence and SLA
rows. Accounts are `test{3,5,7}_{pm,auditor,dept_manager,employee,employee_b}`,
password `Password123!`.

Their purpose is negative evidence: if any of them ever needed a special
case, the engine would not be generic.

## Adding a program

No code. Create the program, open a structure draft, add levels, activate,
then import content from the generated XLSX template. Dashboards, reports,
filters, breadcrumbs and exports adapt on their own.

## Verification

```bash
php artisan compliance:verify-hierarchy
php artisan compliance:verify-program-structure NDMO
php artisan compliance:verify-cross-program
```

All read-only.
