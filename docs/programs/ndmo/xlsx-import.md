# NDMO — XLSX Import/Export

Uses the same generic engine every other program uses —
`App\Exports\Hierarchy\HierarchyTemplateExport`,
`App\Services\HierarchyImportValidator`,
`App\Services\HierarchyImportService`.

**Columns are resolved from NDMO's active structure version**, not from an
`import` configuration category (that category was removed) and not from a
PHP constant. Because NDMO's structure has six levels, its template has
**6 levels / 22 columns** — verified by generating the live template and
reading its `_metadata` sheet.

That matters historically: NDMO is the program on which the audit proved the
old engine's **silent path truncation** (finding C2), because only the first
two levels of its chain were ever mirrored. It now exports and imports at its
full depth.

The workbook has four sheets — `Requirements`, `Instructions`,
`Allowed Values`, `_metadata` — and the hidden metadata sheet's
`program_code` and `structure_version` are both checked on every validate
call.

The downloaded file name reflects the program code
(`ndmo-requirements-template.xlsx`, same mechanism fixed for Sumoud in
Phase 5).

## Cross-program protection — proven, not assumed

The `WRONG_PROGRAM` metadata check rejects an NDMO template uploaded to any
other program and vice versa — the same mechanism proven for Sumoud/ECC; no
NDMO-specific work was needed to inherit it.

A second, independent check rejects a file generated against a **different
structure version of NDMO itself** with `INCOMPATIBLE_STRUCTURE_VERSION`.
Program identity and structure identity are separate guarantees.

**Full documentation:** [`dynamic-xlsx-engine.md`](../../dynamic-xlsx-engine.md)

## Honest limitation, carried forward unchanged from ECC (Phase 6)

**The importer still targets the flat `Standard` level, not the full
five-level `ComplianceNode` tree, and does not create a
`ComplianceContentVersion` record on confirm.** This is the same gap
documented in `docs/programs/ecc/xlsx-import.md`, now confirmed to affect
a second program (NDMO) as well — strengthening the case that this is a
priority engine gap, not an ECC-specific oversight. See
`known-limitations.md` and `content-versioning.md`.
