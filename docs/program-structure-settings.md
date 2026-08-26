# Program Structure Settings

**Status: implemented and verified** (2026-08-26).

The Program Manager's control over their own program's hierarchy.
Route: `/programs/{program}/settings/structure`.

Adding a level, renaming one, changing its semantics or reordering the
structure is a **configuration action performed by the Program Manager in
the browser**. It requires no migration, no deployment and no developer.
Depth is a row count in `hierarchy_level_definitions`, never a schema fact —
see [`hierarchy-data-model.md`](hierarchy-data-model.md).

## Who can do what

| | Super Admin | Program Manager (own) | Program Manager (other) | Other member |
|---|---|---|---|---|
| View structure | ✅ | ✅ | 404 | ✅ |
| Open/edit draft | ✅ | ✅ | 404 | 403 |
| Activate | ✅ | ✅ | 404 | 403 |
| View version history | ✅ | ✅ | 404 | ✅ |

**403 vs 404 is deliberate.** A user with no membership gets **404** so
program codes cannot be enumerated by probing. A user who can see the
program but lacks the role gets **403**. Enforced in
`HierarchyStructurePolicy`, tested in `ProgramStructureApiTest` — including
the case most likely to be wrong: a user who is Program Manager of one
program and merely an employee in another.

## What a manager can configure per level

| Group | Fields |
|---|---|
| Identity | Arabic/English name, Arabic/English plural, icon |
| Position | order (move up/down), enable/disable |
| Behaviour | assignable · assessable · accepts evidence |
| Visibility | dashboard · reports · filters · breadcrumb |
| Form fields | code required · description · objective · weight · due date · instructions |

The technical `key` is set once at creation and never changes — it is what
the XLSX contract and every stored node reference. Display names are what a
manager renames.

**No raw JSON is ever exposed**, per the brief. The editor is grouped
checkboxes and text inputs.

## Draft → Preview → Activate

1. **Open draft** — clones the active structure so the manager edits a copy.
2. **Edit** — add, rename, reorder, disable, remove levels.
3. **Preview impact** — see below.
4. **Activate** — supersedes the previous definition and freezes an
   immutable `ProgramStructureVersion` snapshot.

An active structure cannot be edited directly; the API refuses.

## Impact classification

Re-evaluated **server-side at activation**, so a stale client preview can
never force a change through.

| Class | Meaning | Behaviour |
|---|---|---|
| `safe` | Presentation-only, or no content yet | Activates freely |
| `requires_migration` | Structural change against existing content | Needs explicit acknowledgement |
| `not_allowed` | Would orphan or corrupt live content | Refused; refusal is audit-logged |

Currently blocked outright:

- Removing a level that holds nodes while a cycle is active.
- Reordering a populated hierarchy while a cycle is active.

Always safe, even mid-cycle: display names, plurals, icon, and
dashboard/report/filter/breadcrumb visibility.

The preview reports affected nodes, nodes on removed levels, assignments,
evidence submissions, active cycles and historical cycles — real counts, not
estimates.

**Verified in the browser.** `tests/e2e/dynamic-hierarchy/active-cycle-protection.spec.ts`
(6 tests, run against the dedicated mutable `TESTX` fixture so the shared
depth fixtures stay clean) confirms each refusal at the **API**, not merely
that a button is hidden — including that a `not_allowed` change stays
refused when the client sends `acknowledgeMigration: true`. Acknowledgement
escalates a `requires_migration` change; it never unlocks a forbidden one.

## Validation

A draft cannot activate unless: at least one level; at most 12; at least one
enabled; exactly one root; contiguous order from 1; a linear acyclic parent
chain; at least one assessable level; every evidence-bearing level is also
assessable; every assignable level is also assessable.

The last two prevent structures that look valid but cannot work — evidence
that never reaches a reviewer, or an assignment that can never complete.

The same rules are enforced at runtime, not only at activation:
`WorkflowService::getOrCreateDraft()` refuses to open an evidence draft on a
node whose level does not accept evidence. That gap was found by the
platform's own `compliance:verify-hierarchy` command during this work, and
closed.

## Audit trail

Every action writes an `audit_logs` row with program, actor, role, old value,
new value and impact:

`hierarchy_structure.draft_opened` · `.level_added` · `.level_updated` ·
`.level_reordered` · `.level_removed` · `.activated` · `.activation_rejected`
