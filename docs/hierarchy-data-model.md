# Dynamic Hierarchy — Data Model

**Status: implemented and verified** (2026-08-26). `ComplianceNode` is the
single authoritative compliance model; the legacy `Standard` /
`EvidenceRequirement` authoring path has been retired and its tables are
empty. Companion to
[`compliance-hierarchy-audit.md`](compliance-hierarchy-audit.md), which
records the fixed-depth engine this replaces, and to
[`dynamic-compliance-structure.md`](dynamic-compliance-structure.md), which
describes the runtime behaviour built on this model.

Structures in service today: 3 levels (Sumoud, TEST3, PERF3), 5 levels
(Qiyas, ECC, TEST5, PERF5), 6 levels (NDMO), 7 levels (TEST7, PERF7) — from
the same schema, with no code branch per program.

## Entities

```
ComplianceProgram
  └── HierarchyDefinition          one editable revision of a structure
        ├── HierarchyLevelDefinition   one row per level (depth = row count)
        └── ProgramStructureVersion    immutable snapshot, frozen at activation
              └── AssessmentCycle      pins structure_version_id
ComplianceNode                     the content placed inside the structure
```

### `hierarchy_definitions`

One row per *revision* of a program's structure.
`draft → active → superseded`. Exactly one `active` per program, enforced
transactionally in `HierarchyDefinitionService::activate()` (MySQL has no
partial unique index) and asserted by `compliance:verify-hierarchy`.

### `hierarchy_level_definitions`

The table that makes the engine dynamic. **Depth is a row count, not a
schema fact.** Every behaviour the platform previously hard-coded is a
column:

| Group | Columns | Replaces |
|---|---|---|
| Identity | `key`, `name_ar`, `name_en`, `plural_name_ar`, `plural_name_en`, `icon` | hard-coded Arabic-only labels (H5) |
| Position | `level_order`, `parent_level_id` | array position in a JSON blob (M6) |
| Structure | `is_required`, `is_active`, `allow_children` | no disable path (M6) |
| Behaviour | `is_assignable`, `is_assessable`, `accepts_evidence` | unenforceable semantics (H7) |
| Surfaces | `appears_in_dashboard`, `appears_in_reports`, `appears_in_filters`, `appears_in_breadcrumb` | fixed 2-level taxonomy (H1, H2, H3) |
| Form fields | `code_required`, `description_enabled`, `objective_enabled`, `weight_enabled`, `due_date_enabled`, `instructions_enabled` | two hard-coded form shapes (H6) |
| Extension | `metadata_schema` | — |

Uniqueness: `(hierarchy_definition_id, key)` and
`(hierarchy_definition_id, level_order)`.

**Two fields from the brief's suggested list are deliberately absent**, per
its own instruction not to add unused fields blindly:

- `singular_name_*` — `name_*` already *is* the singular ("Perspective");
  `plural_name_*` covers list headings. A third pair could never differ.
- `sort_order` — for a *level* the only ordering axis is depth, which
  `level_order` expresses. Sibling ordering belongs to nodes, where
  `compliance_nodes.sort_order` already exists.

### `program_structure_versions`

Immutable JSON snapshot written at activation; never updated afterwards
except `active → superseded`. This is what makes historical reporting
reproducible after a rename or reorder — see
[`hierarchy-versioning.md`](hierarchy-versioning.md).

### `compliance_nodes` (extended)

Existing adjacency-list table, now bound to its level:

- `hierarchy_level_id` → the authoritative level link
- `structure_version_id` → the structure in force when created
- `objective_ar/_en`, `weight`, `default_due_date` → fields the level enables
- `is_assignable_override`, `is_assessable_override`, `accepts_evidence_override`
  → `NULL` inherits the level; `true`/`false` deviates deliberately
- `archived_at` → non-destructive removal

`node_type` and `level` are retained as denormalised copies (level key and
0-based depth) for logs and exports.

## Tree strategy: adjacency list + MySQL 8 recursive CTE

Chosen over closure table and nested set because it is the simplest option
that meets measured need:

- The table and its indexes already exist and are correct.
- MySQL 8.4 is confirmed in use and supports recursive CTEs.
- Realistic depth is ≤ 8; realistic volume is thousands of nodes.
- Structure edits and imports (writes) are far more frequent than the deep
  descendant reads that justify a closure table's write amplification.

**This was measured, not assumed.** On a 9,336-node fixture spanning 3-, 5-
and 7-level programs, a subtree recursive CTE runs in 0.4–0.7 ms P50 and a
full-depth breadcrumb in 1.7–3.9 ms; the 7-level program is *faster* than
the 3-level one because it holds fewer nodes. Cost tracks node count, not
depth, which is exactly the property that makes the adjacency list adequate
here. Full figures and execution plans:
[`performance-evidence.md`](performance-evidence.md).

## Resolving behaviour

Never test `is_assessable` on a node directly. Call:

```php
$node->isAssignable();     // override ?? level ?? false
$node->isAssessable();
$node->acceptsEvidence();
```

## Depth ceiling

One constant: `HierarchyDefinitionService::MAX_LEVELS = 12`. It previously
existed as three unrelated literals with three meanings (audit finding H4),
including an `ancestors($maxHops = 10)` that *silently truncated* rather
than raising. `ancestors()` now bounds at `MAX_LEVELS + 1`, one hop beyond
the deepest legal tree, so valid data can never be silently shortened.
