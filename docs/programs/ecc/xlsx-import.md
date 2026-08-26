# ECC — XLSX Import/Export

There is **no ECC-specific XLSX schema**, and there is no longer a
program-specific import class of any kind. ECC uses the same
structure-driven engine as every other program:

- `App\Exports\Hierarchy\HierarchyTemplateExport`
- `App\Services\HierarchyImportValidator`
- `App\Services\HierarchyImportService`

The workbook's shape is derived from ECC's **active structure version**, so
its level and column counts are a consequence of configuration rather than
of code written for this program.

Verified for ECC: **5 levels / 19 columns**, sheets
`Requirements`, `Instructions`, `Allowed Values`, `_metadata`.

**Full documentation:** [`dynamic-xlsx-engine.md`](../../dynamic-xlsx-engine.md)

> The earlier version of this page referenced
> `QiyasRequirementsTemplateExport` / `QiyasImportValidator` /
> `QiyasImportService`. Those classes were retired with the legacy
> authoring path and no longer exist.
