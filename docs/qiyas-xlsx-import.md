# Qiyas XLSX Import — superseded

> ⚠️ **This document described the retired, Qiyas-specific XLSX schema.**
> That schema, and the `QiyasRequirementsTemplateExport` /
> `QiyasImportValidator` / `QiyasImportService` classes it documented, **no
> longer exist**. There is no program-specific XLSX schema in the platform
> any more.

Qiyas now uses the same structure-driven engine as every other program. The
workbook is generated from the program's active structure, so its shape is a
consequence of configuration rather than of a schema written for one
program: Qiyas currently produces **5 levels / 19 columns**, Sumoud 3 / 14,
NDMO 6 / 22, TEST7 7 / 26 — all from one generator.

**Current documentation:** [`dynamic-xlsx-engine.md`](dynamic-xlsx-engine.md)

| Then | Now |
|---|---|
| `QiyasRequirementsTemplateExport` | `App\Exports\Hierarchy\HierarchyTemplateExport` |
| `QiyasImportValidator` | `App\Services\HierarchyImportValidator` |
| `QiyasImportService` | `App\Services\HierarchyImportService` |
| `GET /programs/QIYAS/import/template` | `GET /programs/{program}/hierarchy-template` |
| `POST /programs/QIYAS/import/preview` | `POST /programs/{program}/hierarchy-import/preview` |
| Single sheet, fixed columns | 4 sheets — Requirements · Instructions · Allowed Values · `_metadata` |
| Column identity = heading text | Column identity = machine identifier in `_metadata` |
