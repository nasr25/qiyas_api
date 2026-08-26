# Compliance Hierarchy Engine — Current-State Audit (Phase A)

> **Status: historical record.** This document describes the platform **as
> it was before** the dynamic compliance engine was built, and is retained
> as the evidence trail for why the work was done. Every Critical and High
> finding in it has since been addressed — see
> [`dynamic-compliance-structure.md`](dynamic-compliance-structure.md) for
> the architecture that replaced it and
> [`final-dynamic-compliance-engine-report-ar.md`](final-dynamic-compliance-engine-report-ar.md)
> for the verified outcome. **Do not read this as a description of current
> behaviour.**

**Audit date:** 2026-08-25
**Branch audited:** `feature/multi-program-compliance-platform` @ `a2760f3`
**Audit type:** Implementation review against the pre-change codebase
**Scope:** Database, backend, frontend, dashboards, reports, XLSX, authorization, cache, tests, seeders
**Status of implementation:** NOT STARTED — this document is the mandatory gate before Phase B.

---

## 1. Executive Summary

The platform is marketed internally as having a "fully generic, arbitrary-depth
compliance engine" (see the class docs on `ComplianceNode` and
`ComplianceNodeService`). **That claim is only partially true, and the part that
is true is not the part the business depends on.**

The audit found **two parallel, incompatible hierarchy implementations**:

| | Storage | Depth | Programs | Used by dashboards/reports/import? |
|---|---|---|---|---|
| **Model A (legacy)** | `standards.perspective`, `standards.axis` — two free-text `VARCHAR(500)` columns | **Fixed 2 parent levels** | QIYAS, SUMOUD | **Yes — everything** |
| **Model B (generic)** | `compliance_nodes` self-referential table | Arbitrary (capped at 10) | ECC, NDMO | **No — nothing** |

Model B is a genuine adjacency-list tree and is the architecturally correct
direction. But it is a **display-only side-structure**. The moment a node needs
to do real work — be assigned, carry evidence, enter a workflow, appear in a
dashboard, appear in a report, or be imported from XLSX — it is *mirrored* into
a Model A `standards` row, and **at that boundary all hierarchy depth beyond two
levels is silently and permanently discarded.**

This was verified empirically, not merely by reading code. After seeding the
NDMO five-level sample hierarchy
(`domain → policy → standard → requirement → subrequirement`), the mirrored
`standards` row for the level-4 subrequirement contains:

```
perspective = "مجال تجريبي لحوكمة البيانات"   ← level 0 (domain)
axis        = "سياسة تجريبية"                 ← level 1 (policy)
                                              ← levels 2 (standard) and 3 (requirement): LOST
```

Two of the five NDMO levels do not survive into the only table that
assignments, evidence, workflow, SLA, dashboards, reports and exports read
from. **NDMO today therefore does rely on a fixed Qiyas-shaped structure**
(audit question 26 = **Yes**).

### Headline answers

- **Is the hierarchy dynamic?** Structurally yes for *storage* (Model B), no for
  *everything operational*.
- **Effective operational depth: 2 parent levels + 1 leaf**, for all four
  programs, regardless of configuration.
- **Level definitions live in a JSON config blob**, seeder-writable only. There
  is no `hierarchy_level_definitions` table, no API to edit them, no UI, and no
  Program Manager capability of any kind.
- **No structure versioning exists.** `ProgramStructureVersion` does not exist
  in any form.
- **Adding a 6th or 7th level requires source-code changes** in the bridge,
  importer, taxonomy endpoints, dashboards and reports.
- **Zero of the 30 target `HierarchyLevelDefinition` fields** governing
  assignable / assessable / evidence / dashboard / report / filter / breadcrumb
  behaviour exist, except `is_assessable`.

### Severity roll-up

| Severity | Count |
|---|---|
| Critical | 6 |
| High | 7 |
| Medium | 6 |
| Low | 2 |

**No Critical finding is resolvable by configuration.** All six require schema
and code change.

---

## 2. Current Hierarchy Architecture

### 2.1 Model A — the legacy fixed hierarchy (QIYAS, SUMOUD)

`standards` carries the hierarchy inline as denormalised text:

```
standards
├── perspective   VARCHAR(500) NULL   ← "level 1", free text, not a foreign key
├── axis          VARCHAR(500) NULL   ← "level 2", free text, not a foreign key
└── standard_number/name_ar/...       ← "level 3", the assessable leaf
```

There is no parent table, no referential integrity, no ordering, no codes, no
bilingual naming, and no way to express a fourth level. Renaming a perspective
is a mass `UPDATE` across rows. Two perspectives with the same name in different
cycles are indistinguishable.

`database/migrations/2026_06_03_000001_add_qiyas_fields_to_standards_table.php`
is explicit that these columns exist to hold DGA Qiyas spreadsheet columns
(المنظور / المحور). This is precisely the "do not design the database around
fixed business fields" anti-pattern named in the brief.

### 2.2 Model B — the generic node tree (ECC, NDMO)

`compliance_nodes` is a proper adjacency list:

```
compliance_nodes
├── id, compliance_program_id, program_cycle_id, content_version_id
├── parent_id          → compliance_nodes.id   (self-referential, cascade delete)
├── node_type          VARCHAR(50)   ← level key as a STRING, not an FK
├── level              TINYINT       ← 0-based depth, denormalised
├── code, name_ar, name_en, description_*, guidance_*
├── sort_order, is_assessable, status, metadata (JSON)
└── standard_id        → standards.id          ← THE BRIDGE
```

Indexes are adequate: `(compliance_program_id, parent_id)`,
`(compliance_program_id, node_type)`, `is_assessable`, plus InnoDB's automatic
FK indexes on `parent_id`, `program_cycle_id`, `content_version_id`,
`standard_id`. Uniqueness is enforced on
`(compliance_program_id, content_version_id, code)`. **This table is well
designed and should be kept as the foundation of Phase B.**

### 2.3 Level definitions — configuration, not schema

Level metadata is a JSON document in `program_configurations` under category
`hierarchy`, validated in `ProgramConfigurationService::validateValue()`:

```php
'hierarchy' => [
    'levels'                 => ['required','array','min:1','max:10'],
    'levels.*.node_type'     => ['required','string','max:50','regex:/^[a-z_]+$/'],
    'levels.*.label_ar'      => ['required','string','max:100'],
    'levels.*.label_en'      => ['required','string','max:100'],
    'levels.*.parent_type'   => ['nullable','string','max:50'],
    'levels.*.is_assessable' => ['required','boolean'],
    'max_depth'              => ['required','integer','min:1','max:10'],
],
```

Five properties per level. The target architecture requires roughly thirty.
Everything governing *behaviour* — assignable, evidence-bearing, dashboard
visibility, report visibility, filter visibility, breadcrumb inclusion, enabled
form fields, ordering, icons, activation — is absent.

### 2.4 Which programs actually have a hierarchy definition

Verified directly against the database:

```sql
SELECT cp.code, pc.category FROM program_configurations pc
JOIN compliance_programs cp ON cp.id = pc.compliance_program_id
WHERE pc.category = 'hierarchy';
```

| Program | `hierarchy` config | Nodes in `compliance_nodes` |
|---|---|---|
| QIYAS | **absent** | 0 |
| SUMOUD | **absent** | 0 |
| ECC | present (4 levels) | 5 |
| NDMO | present (5 levels) | 5 |

Half the production programs are invisible to the "generic" engine.

---

## 3. Findings

Each finding: file · class/component · exact behaviour · why it is a problem ·
affected programs · required correction · production impact.

---

### CRITICAL

#### C1 — Two incompatible hierarchy models; the generic one is unused operationally

- **File:** `database/migrations/2026_06_03_000001_add_qiyas_fields_to_standards_table.php`, `database/migrations/2026_07_21_000002_create_compliance_nodes_table.php`
- **Component:** `Standard`, `ComplianceNode`
- **Exact behaviour:** QIYAS/SUMOUD store hierarchy as `standards.perspective` +
  `standards.axis` free text. ECC/NDMO store it in `compliance_nodes`. No
  program uses one model exclusively for real work; every assessable item in
  every program ultimately resolves to a `standards` row.
- **Why a problem:** There is no single source of truth. Every consumer
  (dashboard, report, filter, export, breadcrumb) must either pick one model and
  be wrong for half the programs, or implement both. Today they all pick Model
  A, which is why ECC/NDMO hierarchy is invisible everywhere except one explorer
  screen.
- **Affected programs:** All four.
- **Required correction:** Promote `compliance_nodes` to the single source of
  truth; migrate QIYAS/SUMOUD `perspective`/`axis` text into real nodes; retain
  `standards` only as a legacy view/compat shim or drop it behind a mapping
  table.
- **Production impact:** Blocks the entire dynamic-hierarchy requirement. Any
  report or dashboard built now on `standards` must be rebuilt later.

#### C2 — `createAssessableNode()` uses fixed array indexes and silently destroys hierarchy depth

- **File:** `app/Services/ComplianceNodeService.php:110-120`
- **Component:** `ComplianceNodeService::createAssessableNode()`
- **Exact behaviour:**
  ```php
  $ancestors     = $parent->ancestors();
  $chain         = [...$ancestors, $parent];
  $domainName    = $chain[0]->name_ar ?? null;
  $subdomainName = $chain[1]->name_ar ?? ($chain[0]->name_ar ?? null);
  // ... written to standards.perspective / standards.axis
  ```
  Only `$chain[0]` and `$chain[1]` are ever read. Every ancestor from index 2
  upward is discarded with no error, no warning and no audit entry.
- **Why a problem:** This is the exact "fixed array indexes" anti-pattern the
  brief prohibits. It converts an arbitrary-depth tree into a 2-level
  projection at the only boundary that matters. It is silent data loss.
- **Evidence:** For NDMO node `NDMO-D1-P1-S1-R1-SR1` (level 4), the mirrored
  standard row 102 holds `perspective = domain`, `axis = policy`; the
  intervening `standard` and `requirement` levels are absent from the row.
- **Affected programs:** ECC (loses level 3+), NDMO (loses levels 2–3). QIYAS
  and SUMOUD unaffected only because they never exceed 2 levels.
- **Required correction:** Eliminate the mirror. Assignments, evidence and
  workflow must reference `compliance_nodes.id` directly.
- **Production impact:** **Critical.** ECC and NDMO compliance reporting is
  structurally incapable of being correct. A subcontrol and its parent control
  are indistinguishable in every report.

#### C3 — XLSX import cannot create hierarchy nodes at all

- **File:** `app/Services/QiyasImportService.php:95-112`
- **Component:** `QiyasImportService::confirm()`
- **Exact behaviour:** The only write in the import pipeline is
  `Standard::updateOrCreate([...], ['perspective' => $row['perspective'], 'axis' => $row['axis'], ...])`.
  No code path in the importer ever instantiates a `ComplianceNode`.
- **Why a problem:** A program's hierarchy can only be populated by hand through
  the explorer UI, one node at a time. Importing an official 5-level NDMO or
  4-level ECC catalogue is impossible. The `import` program-configuration
  category can rename *column labels*, but the write path is hard-bound to two
  hierarchy fields.
- **Affected programs:** ECC, NDMO (cannot import). QIYAS, SUMOUD (work, but
  permanently capped at 2 levels).
- **Required correction:** Rewrite the importer to resolve/create a node chain
  of arbitrary configured depth, driven by the level definitions.
- **Production impact:** Onboarding any real framework catalogue requires manual
  data entry — unusable at 89+ requirements, impossible at ECC/NDMO scale.

#### C4 — No hierarchy level definition entity; no Program Manager control whatsoever

- **File:** `app/Services/ProgramConfigurationService.php:147-155`; absence of any migration
- **Component:** `program_configurations` (category `hierarchy`)
- **Exact behaviour:** Levels are a JSON array inside a config row, writable only
  via `ProgramConfigurationService::set()`, which is called only from seeders.
  There is no `HierarchyLevelDefinition` model, no `/settings/structure` route
  (confirmed against the full 164-route list), no controller, no Vue page.
- **Why a problem:** Every requirement in the "Admin Control of Program
  Structure" and "Program Manager UX" sections of the brief is unimplemented.
  Adding, renaming, reordering, disabling or configuring a level requires a
  developer, a code deploy and a re-seed.
- **Affected programs:** All four.
- **Required correction:** Create `hierarchy_definitions` +
  `hierarchy_level_definitions` tables with the full field set; expose a
  program-scoped CRUD API guarded by program-manager authorization; build the
  Program Structure Settings page.
- **Production impact:** The central business requirement — "without requiring
  source-code changes for every new program" — is not met.

#### C5 — No structure versioning; historical reporting is silently mutable

- **File:** absent (`ProgramStructureVersion` does not exist)
- **Component:** n/a
- **Exact behaviour:** `program_configuration_versions` snapshots config JSON for
  audit, but nothing binds a *cycle*, *assignment*, *report* or *saved view* to
  the structure version in force when it was created. `compliance_content_versions`
  versions framework *content*, which is a different axis and covers ECC/NDMO only.
- **Why a problem:** Renaming level 3 from "Criterion" to "Control" retroactively
  rewrites every historical report label. Reordering levels retroactively changes
  what historical groupings mean. The brief explicitly forbids this.
- **Affected programs:** All four.
- **Required correction:** Introduce `ProgramStructureVersion` (snapshot,
  status, activation date, created_by); stamp cycles and saved reports with the
  version they were created under.
- **Production impact:** Audit and regulatory defensibility risk — historical
  compliance evidence cannot be reproduced as originally reported.

#### C6 — The node↔standard bridge is one-directional; the back-reference is never written

- **File:** `database/migrations/2026_07_21_000003_add_hierarchy_bridge_columns.php`; `app/Services/ComplianceNodeService.php:122-124`
- **Component:** `ComplianceNodeService::createAssessableNode()`
- **Exact behaviour:** The migration adds `standards.compliance_node_id` and its
  docblock states the column "points back to" the node. The service sets
  `$node->update(['standard_id' => $standard->id])` but **never** sets
  `$standard->compliance_node_id`. Verified: all five mirrored ECC/NDMO
  standards have `compliance_node_id IS NULL`.
- **Why a problem:** Any consumer starting from a `standards` row — which is
  every dashboard, report, assignment queue and export — cannot navigate back to
  the node, and therefore cannot recover the hierarchy path, even the truncated
  one. The documented contract is false.
- **Affected programs:** ECC, NDMO.
- **Required correction:** Populate the column in the same transaction, and
  backfill existing rows; add an integrity check to `compliance:verify-hierarchy`.
- **Production impact:** Silent. Makes an otherwise-cheap interim fix (deriving
  breadcrumbs from the node for mirrored standards) impossible without a backfill.

---

### HIGH

#### H1 — Dashboards have no hierarchy grouping or drill-down of any kind

- **File:** `app/Http/Controllers/Api/Programs/ProgramDashboardController.php`
- **Component:** `ProgramDashboardController::index()`, `documentStats()`
- **Exact behaviour:** Aggregation is `Document ... groupBy('status')`, plus a
  per-`Department` loop. There is no grouping by `perspective`, `axis`,
  `node_type` or anything hierarchical, and no drill-down endpoint.
- **Why a problem:** "Progress by Perspective → drill to Axis → drill to
  Criterion" does not exist. Neither does the NDMO equivalent. The entire
  "Hierarchy-Driven Dashboard" section of the brief is unimplemented.
- **Affected programs:** All four.
- **Required correction:** Add a metadata-driven grouping endpoint accepting a
  level key, returning universal metrics per node at that level, with the next
  drill level resolved from the definition.
- **Production impact:** Program managers cannot see where non-compliance is
  concentrated — the primary analytical purpose of the platform.

#### H2 — Reports cannot group or filter by any hierarchy level

- **File:** `app/Http/Controllers/Api/Reports/ReportController.php` (187 lines), `app/Http/Controllers/Api/Programs/ProgramReportController.php`
- **Component:** `ReportController::byDepartment/byStandard/byStatus/cycleSummary`
- **Exact behaviour:** Four fixed reports. `grep` for `perspective|axis|groupBy`
  across the controller returns only `groupBy('status')`. `ProgramReportController`
  is a thin wrapper that injects a default `cycle_id`.
- **Why a problem:** "Group by any enabled reportable hierarchy level" and
  "dynamic cascading hierarchy filters" are entirely absent. There is no report
  dimension registry.
- **Affected programs:** All four.
- **Required correction:** Introduce a safe, whitelisted report-dimension
  registry keyed on level definitions; build a generic grouping query builder.
- **Production impact:** Regulatory reporting by domain/control is impossible
  without manual spreadsheet work.

#### H3 — Taxonomy endpoints are hard-capped at exactly two levels

- **File:** `app/Http/Controllers/Api/Programs/ProgramTaxonomyController.php`
- **Component:** `domains()`, `categories()`
- **Exact behaviour:** Two endpoints, `/domains` and `/categories`, each
  `selectRaw`-grouping the free-text `perspective` / `axis` columns. Level 3+
  has no endpoint. The class docblock concedes this is deferred technical debt.
- **Why a problem:** Hard-coded route names per hierarchy level is explicitly
  prohibited. The API shape itself encodes a 2-level assumption, so any client
  written against it inherits the limit.
- **Affected programs:** All four (ECC/NDMO return empty or misleading data
  because their `perspective`/`axis` values are truncated projections).
- **Required correction:** Replace with `/programs/{program}/structure/levels/{levelKey}/nodes`.
- **Production impact:** Frontend filters silently show the wrong taxonomy for
  ECC/NDMO.

#### H4 — Maximum depth is hard-coded in three independent places

- **File:** `app/Services/ProgramConfigurationService.php:148,154`; `app/Services/ComplianceNodeService.php:170`; `app/Models/ComplianceNode.php:96`
- **Component:** validation rules, `assertWithinMaxDepth()`, `ancestors()`
- **Exact behaviour:** `'levels' => [...,'max:10']`, `'max_depth' => [...,'max:10']`,
  `?? 10` fallback, and `ancestors(int $maxHops = 10)`.
- **Why a problem:** An 11-level program is impossible without a code change.
  Worse, `ancestors()` *silently truncates* at 10 hops rather than raising —
  a deep tree would produce a wrong breadcrumb, not an error.
- **Affected programs:** All (theoretical today; blocking for the brief's
  "8-level program without code changes" acceptance test — 8 passes, 11 fails).
- **Required correction:** Make the ceiling a documented platform constant with
  a single definition, raise it, and convert silent truncation into an explicit
  exception.
- **Production impact:** Low today, but it is a hard ceiling that fails the
  stated acceptance criterion at 11+.

#### H5 — Frontend renders Arabic labels only, ignoring the English locale

- **File:** `src/views/hierarchy/HierarchyExplorerView.vue:24,33,50,133,138`
- **Component:** `HierarchyExplorerView`
- **Exact behaviour:** Breadcrumbs render `crumb.name_ar`; the level heading uses
  `levels.find(...)?.label_ar`; the create button uses `nextLevel.label_ar`; node
  cards render `node.name_ar`. `label_en` / `name_en` are fetched but never
  displayed. 66 occurrences of `label_ar`/`name_ar` across 17 view files.
- **Why a problem:** The platform is contractually bilingual. English-locale
  users see Arabic hierarchy labels throughout.
- **Affected programs:** All four.
- **Required correction:** Resolve display labels through a locale-aware helper
  driven by the level definition's bilingual fields.
- **Production impact:** Bilingual acceptance criteria fail; English-speaking
  auditors cannot use the hierarchy screens.

#### H6 — Node forms cannot be configured per level

- **File:** `src/views/hierarchy/HierarchyExplorerView.vue:73-95`; `app/Http/Controllers/Api/Programs/ComplianceHierarchyController.php:82-96`
- **Component:** create-node modal; `store()` validation
- **Exact behaviour:** The form shows guidance / evidence requirements / weight /
  due date **iff** `nextLevel.is_assessable`. There is no `weight_enabled`,
  `due_date_enabled`, `objective_enabled`, `description_enabled` or
  `code_required` concept anywhere.
- **Why a problem:** "Dynamic Forms" in the brief requires per-level field
  toggles. Today a program gets one of exactly two hard-coded form shapes.
- **Affected programs:** ECC, NDMO (only programs with a node UI).
- **Required correction:** Add the field-enablement flags to the level
  definition and render the form from them.
- **Production impact:** Programs are forced into irrelevant fields, or denied
  needed ones, with no recourse short of a code change.

#### H7 — Authorization is hierarchy-unaware; assignable/evidence semantics are unenforced

- **File:** `app/Policies/*` (6 policies); `app/Http/Middleware/*`
- **Component:** `RequirementAssignmentPolicy`, `EvidenceSubmissionPolicy`, others
- **Exact behaviour:** `grep -rln "ComplianceNode|node_type|is_assessable" app/Policies app/Http/Middleware` returns **no files**. Policies scope by program, department and role only.
- **Why a problem:** The brief requires the backend to reject assignment of a
  non-assignable node and evidence on a non-evidence-bearing node. Because
  everything is assigned via the mirrored `standards` row, the node's level
  semantics are never consulted at authorization time. There is no
  `is_assignable` or `accepts_evidence` concept to consult in the first place.
- **Affected programs:** All four.
- **Required correction:** Add the flags to the level definition and enforce them
  in the assignment and evidence services, not only in the UI.
- **Production impact:** A grouping node mirrored by mistake could be assigned
  and collect evidence, corrupting completion percentages.

---

### MEDIUM

#### M1 — Cache keys omit structure version
- **File:** `app/Services/ProgramConfigurationService.php:221-224`
- **Behaviour:** `"program_configuration.{$program->id}.{$category}"`. Program-scoped (so no cross-program bleed — NDMO config cannot surface in QIYAS), but carries no structure version, cycle, role, department, or language.
- **Problem:** Once structure versioning exists, cached hierarchy metadata will not invalidate per version. Derived dashboard/report caches (not yet built) would inherit the flaw.
- **Programs:** All. **Correction:** Include structure version in the key namespace before building any derived caches. **Impact:** Latent; becomes Critical the moment Phase B caching lands.

#### M2 — `ancestors()` is an N+1 lazy walk, not a recursive CTE
- **File:** `app/Models/ComplianceNode.php:92-105`
- **Behaviour:** `while ($node) { $node = $node->parent; }` — one `SELECT` per level, per node.
- **Problem:** Breadcrumbs on a 7-level tree cost 7 queries; a 100-row listing with breadcrumbs costs ~700. MySQL 8.4 is available and supports recursive CTEs.
- **Programs:** ECC, NDMO. **Correction:** Recursive CTE for ancestor/descendant traversal; keep adjacency list as storage. **Impact:** Page-load degradation at realistic node volumes; unmeasured (no perf fixtures exist).

#### M3 — Program-named classes and duplicated per-program test suites
- **File:** `app/Services/QiyasImportService.php`, `QiyasImportValidator.php`, `app/Http/Controllers/Api/Workflow/QiyasImportController.php`, `app/Exports/Qiyas/*` (6 classes), `src/views/workflow/QiyasImportView.vue`; `tests/e2e/{qiyas,sumoud,ecc,ndmo}/full-lifecycle.spec.ts`
- **Behaviour:** Classes named for one program are the shared implementation for all four. Four near-identical lifecycle E2E suites.
- **Problem:** Naming implies program-specific behaviour where none exists, inviting future forking; duplicated suites multiply maintenance per new program.
- **Programs:** All. **Correction:** Rename to program-neutral; parameterise the lifecycle suite over a program fixture. **Impact:** Maintenance cost and misleading onboarding, not runtime defect.

#### M4 — `DEFAULT_PROGRAM_CODE = 'QIYAS'` hard-coded in the router
- **File:** `src/router/index.js:21` (used by 11 redirect routes, lines 82–92)
- **Behaviour:** Legacy unscoped URLs redirect into QIYAS unconditionally.
- **Problem:** A user with no QIYAS membership following an old link lands on a program they cannot access. Encodes one program as privileged.
- **Programs:** All non-QIYAS. **Correction:** Redirect to the program selector. **Impact:** Confusing 403s for ECC/NDMO/SUMOUD-only users.

#### M5 — Route guards keyed on the legacy `qiyas-admin` spatie role
- **File:** `src/router/index.js:63,69,70,71,73,74,77`
- **Behaviour:** `meta: { roles: [... 'qiyas-admin' ...] }` gates program-generic screens including `program-hierarchy`.
- **Problem:** The program-scoped role is `program-manager`; the spatie role retains Qiyas naming. Two parallel authorization vocabularies (documented in `docs/roles-and-scopes.md` §1) increase the chance of a guard being wrong for a non-Qiyas program.
- **Programs:** All. **Correction:** Converge on program-scoped roles. **Impact:** Authorization confusion; no known bypass.

#### M6 — Level definitions carry no activation, ordering or required-ness
- **File:** `app/Services/ProgramConfigurationService.php:147-155`
- **Behaviour:** No `is_active`, `is_required`, `level_order`, `sort_order`, `icon`, `metadata_schema`. Order is implied by array position; parentage by `parent_type` string matching.
- **Problem:** A level cannot be disabled without deleting it (destructive), and cannot be reordered without rewriting the array and every `parent_type` reference — with no validation that existing nodes remain consistent.
- **Programs:** ECC, NDMO. **Correction:** First-class columns in `hierarchy_level_definitions`. **Impact:** Any structural edit is a hand-written data migration.

---

### LOW

#### L1 — XLSX template geometry is hard-coded to ten columns
- **File:** `app/Exports/Qiyas/RequirementsSheet.php:29-32,60-63`
- **Behaviour:** `COLUMNS` lists 10 keys; `columnWidths()` hard-codes `'A'..'J'`; `array()` returns two sample rows with exactly 10 cells.
- **Problem:** A program configuring 12 import columns gets correct headings (resolved from config) but default widths and malformed sample rows.
- **Programs:** ECC, NDMO. **Correction:** Derive widths/samples from the resolved column list. **Impact:** Cosmetic template defects.

#### L2 — `ImportLog.template_version` initialised to the literal `'unknown'`
- **File:** `app/Services/QiyasImportService.php:38`
- **Behaviour:** Row created with `'template_version' => 'unknown'` before validation reads the real value.
- **Problem:** If validation aborts, the audit trail records `unknown`, weakening the structure-version rejection story the brief requires.
- **Programs:** All. **Correction:** Populate from the metadata sheet, or leave NULL. **Impact:** Minor audit-trail fidelity.

---

## 4. Answers to the 30 Mandatory Audit Questions

| # | Question | Answer | Evidence |
|---|---|---|---|
| 1 | Hierarchy fixed or dynamic? | **Mixed** — dynamic storage (ECC/NDMO), fixed operationally for all | C1 |
| 2 | How many levels supported? | Storage: 10. **Operationally: 2 parent + 1 leaf** | C2, H4 |
| 3 | Max depth hard-coded? | **Yes, 3 places** | H4 |
| 4 | Level names in code or DB? | **DB** (JSON config), but seeder-writable only | C4 |
| 5 | Every program define its own hierarchy? | **No** — QIYAS/SUMOUD have no `hierarchy` config | §2.4 |
| 6 | Different level counts per program? | **Partially** — declarable, but collapses to 2 in operation | C2 |
| 7 | Add a level without migration? | **No** — no API/UI; seeder + deploy required | C4 |
| 8 | Reorder levels? | **No** | C4, M6 |
| 9 | Rename levels? | **No** (no UI); and renaming would corrupt history | C4, C5 |
| 10 | Disable a level? | **No** — no `is_active` | M6 |
| 11 | Define assignable level? | **No** — concept absent | H7 |
| 12 | Define assessable level? | **Partially** — `is_assessable` exists per level | §2.3 |
| 13 | Define evidence level? | **No** — concept absent | H7 |
| 14 | Define dashboard level? | **No** | H1 |
| 15 | Define report level? | **No** | H2 |
| 16 | Reports group by arbitrary level? | **No** — cannot group by *any* level | H2 |
| 17 | Dashboards drill down dynamically? | **No** — no drill-down at all | H1 |
| 18 | XLSX import adapts to hierarchy? | **No** — 2 fields, cannot create nodes | C3 |
| 19 | XLSX export adapts to hierarchy? | **No** — labels configurable, depth is not | C3, L1 |
| 20 | Filters render from metadata? | **No** — `/domains` + `/categories` only | H3 |
| 21 | Breadcrumbs arbitrary depth? | **Yes (frontend)** — the one genuinely dynamic piece | §5 |
| 22 | API responses hierarchy-neutral? | **No** — `perspective`/`axis` in `StandardResource`, `MyRequirementsController`, `ProgramRequirementController` | C1 |
| 23 | Frontend components hierarchy-neutral? | **Partially** — explorer yes; standards/cycles/employee views no | H5, §5 |
| 24 | Policies hierarchy-neutral? | **Neutral but unaware** — cannot enforce level semantics | H7 |
| 25 | Cache keys hierarchy-safe? | **Program-safe, not version-safe** | M1 |
| 26 | Does NDMO rely on fixed Qiyas/ECC structure? | **Yes** — levels 2–3 discarded into `perspective`/`axis` | C2 |
| 27 | Would level 6/7 require code changes? | **Yes** — bridge, importer, taxonomy, dashboards, reports | C2, C3, H1–H3 |
| 28 | Program-specific duplicated pages? | **Yes** — 4 duplicated E2E lifecycle suites; `QiyasImportView` shared under a program name | M3 |
| 29 | Program-specific duplicated queries? | **No** — genuinely shared; this is a real strength | ✅ |
| 30 | Dashboard metrics tied to fixed tables? | **Yes** — all metrics read `documents`/`standards` | H1 |

---

## 5. What Is Already Correct (preserve in Phase B)

An honest audit must record the strengths, because Phase B should not regress them:

1. **`compliance_nodes` is a well-formed adjacency list** with correct indexes,
   cascade semantics and per-(program, content-version) code uniqueness. Keep it.
2. **Frontend breadcrumbs are genuinely depth-agnostic.**
   `HierarchyExplorerView` maintains a breadcrumb array and drills by
   `parent_type` lookup with no fixed-depth assumption — it would render 7
   levels correctly today (subject to H5's Arabic-only defect).
3. **`ComplianceNodeService` centralises writes** and validates program match,
   parent/child type pairs and depth in one place, program-agnostically. The
   *shape* of this service is right; its bridge is wrong.
4. **The Executive dashboard is already hierarchy-neutral** — `grep` finds no
   hierarchy terms in `ExecutiveDashboardView.vue`. It compares programs on
   universal metrics, exactly as the brief requires.
5. **Cross-program isolation is enforced and tested** —
   `rejectCrossProgramCycle()` blocks cycle-id IDOR; `compliance:verify-cross-program`
   exists; dedicated isolation E2E suites exist for ECC and NDMO.
6. **Configuration is versioned and audited** — `program_configuration_versions`
   plus an audit entry on every `set()`. The mechanism to extend for structure
   versioning already exists.
7. **Content versioning (`compliance_content_versions`) is a genuinely good
   design** and is orthogonal to structure versioning — both are needed.
8. **171 backend tests pass** on this branch; the regression safety net for
   Phase B is real.

---

## 6. Risks

| Risk | Severity | Notes |
|---|---|---|
| Migrating QIYAS `perspective`/`axis` text into nodes | **High** | Free text has no codes, no ordering, no bilingual pairs. 96 standards carrying 13 distinct perspective strings and 27 distinct axis strings must be de-duplicated by exact string match; typos and whitespace variants will create spurious nodes. Requires a dry-run report and human review before commit. |
| Removing the `standards` mirror | **High** | `RequirementAssignment`, `EvidenceSubmission`, `Document`, `WorkflowService`, `SlaService`, `ExtensionService`, notifications, all reports and all exports reference `standards.id`. A legacy mapping table is mandatory. |
| ID preservation | **High** | Nodes and standards have independent id sequences. Existing `document.standard_id` links must survive; a `legacy_standard_node_map` is required rather than an id rewrite. |
| Historical reporting drift | **Medium** | Until structure versioning exists, any label change rewrites history. Ship C5 before exposing the Program Manager UI (C4), not after. |
| Depth-driven performance | **Medium** | No performance fixtures exist at any depth. The brief's 5,000-node / 3-5-7-level fixtures must be built before claiming performance. |
| Excel package on PHP 8.5 | **Low** | `maatwebsite/excel` 3.x pins `phpspreadsheet ^1.30`, which declares `php <8.5`; installs only with `--ignore-platform-req=php+`. Export verified working, but XLSX refactoring should be paired with an Excel v4 migration. |

---

## 7. Required vs Optional Refactoring

**Required (blocks the business requirement):** C1, C2, C3, C4, C5, C6, H1, H2, H3, H6, H7.

**Required for stated acceptance criteria:** H4 (11+ levels), H5 (bilingual).

**Optional (quality, do opportunistically):** M2 (CTE performance), M3 (naming/test
parameterisation), M4, M5, M6, L1, L2.

---

## 8. Recommended Target Architecture

```
ComplianceProgram
   └── HierarchyDefinition            (1 active + N draft/superseded per program)
         └── HierarchyLevelDefinition (ordered; ~30 behavioural flags per level)
               └── ComplianceNode     (adjacency list; hierarchy_level_id FK)
                     ├── RequirementAssignment  → compliance_node_id  (was standard_id)
                     └── EvidenceSubmission     → via assignment
ProgramStructureVersion  → snapshot; stamped onto ProgramCycle and SavedReport
```

**Tree strategy — recommended: adjacency list + MySQL 8 recursive CTE.**
Rationale, per the brief's instruction to prefer the simplest approach meeting
measured need: the storage table already exists and is correctly indexed; MySQL
8.4 is confirmed running; realistic depth is ≤ 8 and realistic node counts are
in the thousands, not millions; and writes (structure edits, imports) are far
more frequent than the deep-descendant reads that would justify a closure
table's write amplification. A closure table should be reconsidered only if
measured drill-down latency on the 7-level / 5,000-node fixture proves
unacceptable — that fixture must be built before the decision is revisited.

**Migration principle:** additive first. Create the new tables, backfill
QIYAS/SUMOUD nodes from `perspective`/`axis`, dual-write, cut consumers over
one at a time, and drop the mirror last — never delete `standards` columns until
`compliance:verify-hierarchy` reports zero discrepancies.

---

## 9. Phase A Conclusion

The platform has a **credible foundation and an incomplete engine**. The
`compliance_nodes` table, the centralised write service, the depth-agnostic
breadcrumb, the hierarchy-neutral executive dashboard and the cross-program
isolation work are all genuinely good and should be preserved.

But the engine terminates at a two-level projection, and every operational
consumer reads that projection rather than the tree. **The platform is not
currently a dynamic compliance structure engine.** It is a fixed two-level
engine with a deeper read-only tree attached to two of its four programs.

**Recommendation: proceed to Phase B**, in the order C6 → C5 → C4 → C1 → C2 →
C3 → H1–H3, with the QIYAS backfill gated behind a reviewed dry-run report.
