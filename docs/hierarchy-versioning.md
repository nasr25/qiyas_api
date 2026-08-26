# Hierarchy Versioning

Two independent version axes exist. Confusing them is the most likely
source of future bugs, so the distinction is stated first:

| Axis | Table | Answers |
|---|---|---|
| **Structure** | `program_structure_versions` | *What shape* was the hierarchy? How many levels, named what, with which semantics? |
| **Content** | `compliance_content_versions` | *Which catalogue release* filled that shape? |

A cycle pins both: `structure_version_id` and `content_version_id`.

## Why structure versioning exists

Audit finding C5: before this, renaming a level retroactively rewrote every
historical report label, and reordering retroactively changed what
historical groupings meant. Nothing bound a cycle to the structure in force
when it ran.

The brief is explicit: *"Do not silently change historical reporting because
the Program Manager renamed or reordered a level."*

## Lifecycle

```
openDraft()   → clones the active structure into a new draft revision
addLevel()    → depth is a row count; no migration, no deploy
updateLevel() → draft only; an active structure refuses edits
moveLevel()   → rewrites level_order 1..N and re-links the parent chain
validateDraft() → structural rules (below)
previewImpact() → what activation would touch, classified
activate()    → supersedes the previous, freezes an immutable snapshot
```

`activate()` re-evaluates impact **server-side**, so a stale client preview
can never force an unsafe change. Every step above is available to the
Program Manager through Structure Settings — see
[`program-structure-settings.md`](program-structure-settings.md) — with no
deployment, migration or developer involvement.

## Validation rules

Enforced in `HierarchyDefinitionService::validateDraft()`:

1. At least one level; at most `MAX_LEVELS`.
2. At least one level enabled.
3. Exactly one root level.
4. `level_order` contiguous from 1.
5. Parent chain linear and acyclic; a child always sits deeper than its parent.
6. At least one enabled level assessable — otherwise nothing can ever enter a workflow.
7. A level that accepts evidence must be assessable — otherwise evidence never reaches a reviewer.
8. A level that is assignable must be assessable — otherwise an assignment could never be completed.

## Impact classification

| Class | Meaning | Behaviour |
|---|---|---|
| `safe` | Presentation-only, or the program has no content yet | Activates freely |
| `requires_migration` | Structural change against existing content | Requires `acknowledgeMigration: true` |
| `not_allowed` | Would orphan or corrupt live content | Refused outright; refusal is audit-logged |

Currently blocked outright:

- Removing a level that holds nodes while a cycle is active.
- Reordering a populated hierarchy while a cycle is active.

**Verified in the browser, against the API, not the UI.** Six tests in
`tests/e2e/dynamic-hierarchy/active-cycle-protection.spec.ts` run against a
dedicated mutable fixture (`TESTX`) and confirm the backend refuses each
case — including refusing a `not_allowed` change *even when the client sends
`acknowledgeMigration: true`*. Acknowledgement escalates a
`requires_migration` change; it never unlocks a `not_allowed` one. Renaming
labels and toggling visibility remain permitted mid-cycle.

Always safe, even mid-cycle (`DISPLAY_ONLY_FIELDS`): Arabic/English display
names, plural names, icon, and dashboard/report/filter/breadcrumb
visibility.

## Audit trail

Every structure action writes an `audit_logs` entry carrying program, actor,
role, old value, new value and impact:

`hierarchy_structure.draft_opened` · `.level_added` · `.level_updated` ·
`.level_reordered` · `.activated` · `.activation_rejected`

## Verification

```bash
php artisan compliance:verify-hierarchy
php artisan compliance:verify-program-structure NDMO
```

Both are read-only. They assert single-active-definition, single-active
snapshot, contiguous order, one root, no circular level or node references,
every node bound to a level of its own program, no node on a disabled level,
no assignment on a non-assignable level, no evidence on a non-evidence
level, and the two legacy-mirror defects (C2 depth truncation, C6 missing
back-reference).
