# Dynamic XLSX Engine

**Status: implemented and tested** — 32 backend tests at 3, 5 and 7 levels
(`tests/Feature/Hierarchy/HierarchyXlsxTest.php`), 6 API-level tests
(`tests/Feature/Workflow/HierarchyImportApiTest.php`), and 7 Playwright
tests (`tests/e2e/dynamic-xlsx/template-depth.spec.ts`) including the
structure-version rejection proof.

Replaces a hand-written, fixed ten-column Qiyas template and an importer
that could only ever write two hierarchy fields (audit findings **C3** and
**L1**).

## Template

`GET /api/v1/programs/{program}/hierarchy-template`

Four sheets:

| Sheet | Purpose |
|---|---|
| `Requirements` | The data sheet. Headings are **localized program terminology**. |
| `Instructions` | Bilingual explanation of every column, plus level order and semantics — generated from the structure. |
| `Allowed Values` | Controlled values and this program's level semantics (assignable / assessable / evidence-bearing). |
| `_metadata` | Machine identity, validated **before** any visible sheet is trusted. |

### Columns follow the structure

Three columns per level (`{level_key}_code`, `_name_ar`, `_name_en`), plus
attribute columns for whichever fields the deepest level enables. **Verified
by generating each program's live template and reading `_metadata`:**

| Program | Levels | Template columns |
|---|---|---|
| SUMOUD | 3 | **14** |
| QIYAS | 5 | **19** |
| ECC | 5 | **19** |
| NDMO | 6 | **22** |
| TEST3 | 3 | **14** |
| TEST5 | 5 | **20** |
| TEST7 | 7 | **26** |

Column count is **not** a pure function of depth. QIYAS and TEST5 are both
5-level but produce 19 and 20 columns, because they enable different
per-level attribute fields (`weight_enabled`, `due_date_enabled`,
`objective_enabled`, …). The count is `3 × levels + enabled attributes`.

Adding a level widens the template with no code change — which is the point.

### Localized headings, machine identity

This is the design decision that makes the format safe to translate:

| | Where | Purpose |
|---|---|---|
| **Visible headings** | `Requirements` row 1 | Localized program terminology — "رمز المنظور" / "Perspective Code". A Program Manager filling the sheet reads their own vocabulary. |
| **Machine identifiers** | `_metadata.column_identifiers` | `perspective_code`, `axis_name_ar`, … in **exact column order**. This is what the importer reads. |

The importer maps columns **positionally** from `column_identifiers` and
never parses a visible heading. Consequences:

- Renaming a level's display label cannot change how a file imports.
- A template downloaded in Arabic and one in English are interchangeable —
  a test asserts their `column_identifiers` are **identical** while their
  headings differ.
- A test asserts every metadata identifier matches `/^[a-z0-9_]+$/`, so a
  translated label cannot become an identity.
- `level_keys` is carried separately, so a structure whose level *keys*
  changed is rejected with `LEVEL_KEYS_MISMATCH` even if the column count
  happens to match.

### Workbook metadata

`_metadata` carries, and the importer checks:

| Key | Purpose |
|---|---|
| `template_version` | `HIERARCHY-TEMPLATE-1.0` — the workbook *shape* |
| `schema_version` | Column-contract version |
| `program_code` | Rejects a template built for another program |
| `structure_version` | Rejects a template built from a superseded structure |
| `hierarchy_definition_version` | The definition the structure came from |
| `level_count` | Depth at generation time |
| `cycle_id` | The cycle it was generated for |
| `generated_at` | ISO-8601 timestamp |
| `column_identifiers` | The full expected column list |

`template_version` and `structure_version` are deliberately separate:
the first changes when the *workbook format* changes; the second changes
whenever a Program Manager edits their levels.

## Import

`POST .../hierarchy-import/preview` → `POST .../hierarchy-import/{log}/confirm`

Program Manager role required for this program.

### One row = one complete hierarchy path

Each row describes a full path from root to the deepest filled level,
repeating its ancestors' codes. Rows sharing ancestor codes **reuse the same
parent nodes** rather than duplicating them — resolution is by
`(program, cycle, hierarchy level, code)`, a deterministic identity. Display
names are never used for matching: there is no fuzzy matching and no
normalising two different records into one.

### Validation, all structure-driven

- Required levels present (from `is_required` on each level).
- Parent chain unbroken — a filled level below a blank ancestor is
  `BROKEN_PARENT_CHAIN`.
- Depth within the program's structure.
- Codes present where `code_required`; Arabic names mandatory.
- Duplicate detection across the whole file (`DUPLICATE_CODE`, `DUPLICATE_ROW`).
- Structure version must match the active one.

### Preview before anything is written

The preview reports totals **and a per-level breakdown** — how many nodes
each level would gain and how many already exist and would be reused — so a
manager reviews the shape of the change, not just a row count:

```
المستوى 1  new=1  reused=0
المستوى 2  new=1  reused=0
…
المستوى 7  new=1  reused=0
```

### Error report

`GET /programs/{program}/hierarchy-import/{importLog}/error-report` returns
an XLSX naming sheet, row, column, hierarchy level, original value, a stable
error **code** and bilingual messages. Internal exception text and SQL are
never included — a database error is a platform bug, not something a
Program Manager can act on. Values are prefixed with `'` on the way out so a
formula payload cannot re-arm inside the report itself.

### Security (brief requirement 10)

| Threat | Behaviour | Error code |
|---|---|---|
| VBA macros (incl. `.xlsm` renamed to `.xlsx`) | Rejected — `xl/vbaProject.bin` detected in the zip container | `MACRO_ENABLED_REJECTED` |
| Formula injection (`=`, `+`, `-`, `@`, tab, CR leaders) | **Rejected, not sanitised** — the uploader learns their file was altered rather than having it silently changed | `FORMULA_INJECTION_REJECTED` |
| Malformed / non-zip workbook | Clean refusal, no stack trace | `UNREADABLE_WORKBOOK` |
| Oversized file | Rejected **before** parsing cost is incurred (10 MB cap) | `FILE_TOO_LARGE` |
| Too many rows | 5,000-row cap | `TOO_MANY_ROWS` |
| Invalid depth | Rejected against the program's own depth | `INVALID_DEPTH` |
| Duplicate nodes | Same code on two paths, or a repeated row | `DUPLICATE_CODE` / `DUPLICATE_ROW` |
| Cross-program metadata | Template for another program refused | `WRONG_PROGRAM` |
| Outdated structure | Template from a superseded structure version refused | `INCOMPATIBLE_STRUCTURE_VERSION` |
| Tampered confirm request | `confirm()` re-reads and re-validates the **stored** file and checks its SHA-256 against the value recorded at upload | — |

### No partial imports

A single error anywhere aborts the entire file — there is no "import the
good rows" path. The write runs inside one `DB::transaction`, so a failure
part-way leaves the database exactly as it was. A test asserts that a file
with one good row and one bad row imports **zero** nodes.

Re-importing the same file updates rather than duplicates: nodes are matched
on (program, cycle, level, code).

## Export

`GET /api/v1/programs/{program}/hierarchy-export`

Emits one row per leaf node using the **same column contract** the template
declares, so an export can be edited and re-imported without transformation.
A test asserts `export.headings() === template columns` at 3, 5 and 7 levels.

## Verified structure-version rejection

The single most important proof that this engine is structure-driven rather
than schema-driven, executed live and again in the browser:

1. Download a program's template at structure **v1** (5 levels).
2. Add a sixth level and activate — structure becomes **v2**.
3. Upload the **old** template → refused:

```
INCOMPATIBLE_STRUCTURE_VERSION
This template was generated from structure version v1 but the active
version is v2. Download the current template.
```

4. Download the **new** template → 6 levels, imports successfully.

Older templates are never silently reinterpreted against a newer structure.

## Idempotency and transactions

- `confirm()` re-reads and re-validates the **stored** file and checks its
  SHA-256 against the hash recorded at upload, so a tampered confirm request
  cannot change what is imported.
- The whole file is written inside one `DB::transaction`.
- **No partial imports**: a single error anywhere aborts everything. A test
  submits one good row and one bad row and asserts **zero** nodes created.
- Re-confirming the same import resolves the same nodes by identity and
  updates rather than duplicating — asserted by
  `test_confirming_twice_does_not_duplicate_nodes`.

## Performance

Measured on 9,336 nodes — see [`performance-evidence.md`](performance-evidence.md):

| Operation | P50 (7-level) | Queries |
|---|---|---|
| Template generation | 17.1 ms | 1 |
| Hierarchy export | 664 ms | 3 |
| Import validation | 14.9 ms | 11 |

Export was originally 2,930 ms across 6,914 queries; bulk path resolution
(`HierarchyPathResolver`) brought it to 3 queries.

## Known limitation

`maatwebsite/excel` 3.x pins `phpoffice/phpspreadsheet ^1.30`, which declares
`php <8.5`. On PHP 8.5 it installs only with `--ignore-platform-req=php+`.
Generation and parsing are verified working, but an upgrade to Excel v4
should be scheduled rather than relying on the bypass indefinitely.
