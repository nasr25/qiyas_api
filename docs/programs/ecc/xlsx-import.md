# ECC — XLSX Import/Export

Uses the same `QiyasRequirementsTemplateExport`/`QiyasImportValidator`/
`QiyasImportService` classes Qiyas/Sumoud use — already fully
program-generic (columns resolved from the acting program's `import`
configuration; the hidden metadata sheet's `program_code` is checked
against the current program on every validate call). ECC's template
(`ECCProgramConfigurationSeeder`'s `import` category) exposes Main Domain
Code, Subdomain Code, Control Code, Control Name (Arabic/English),
Description, Guidance, Evidence Requirements, Weight, Default Due Date.

The downloaded file name already reflects the program code (fixed for
Sumoud in Phase 5, reused unmodified: `ecc-requirements-template.xlsx`).

## Cross-program protection — proven, not assumed

The `WRONG_PROGRAM` metadata check (unchanged since Phase 4/5) rejects an
ECC template uploaded to Qiyas/Sumoud and vice versa — the same mechanism
already proven bidirectionally for Sumoud; the Phase 6 backend/E2E work
did not need to re-implement it, only rely on it.

## Honest limitation: the importer targets `Standard`, not `ComplianceNode`

`QiyasImportService::confirm()` creates/updates `standards` rows directly
— it has NOT been extended to also create the parent `ComplianceNode`
chain (Domain/Subdomain) an imported ECC row would need. **This means the
"Official Content Import Mode" described in the Phase 6 brief (download →
populate from approved source → validate → preview hierarchy → confirm →
content-version record) is NOT functionally complete this phase** — the
underlying validator/confirm pipeline works unchanged for flat
Standard-shaped rows (matching Qiyas/Sumoud's two-level hierarchy), but
does not yet resolve/create a matching four-level `ComplianceNode` tree
from a flat XLSX row, nor record a `ComplianceContentVersion` on import.
This is the single most significant deferred item this phase — see
`known-limitations.md` and `content-versioning.md`. The hierarchy UI
(`HierarchyExplorerView.vue`) and `ComplianceNodeService` are the correct,
tested foundation this importer extension would build on; building it was
judged out of scope for the time available without an actual approved ECC
source file to validate against.
