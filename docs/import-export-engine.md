# Import and Export Engine — superseded

> ⚠️ **This document described the Phase 4 engine, in which XLSX columns were
> resolved from a program's `import` **configuration category**.**
> That mechanism has been replaced. Columns are now resolved from the
> program's **active structure version**, which is strictly stronger: the
> `import` configuration category was removed, and the workbook can no longer
> disagree with the hierarchy it describes.

**Current documentation:** [`dynamic-xlsx-engine.md`](dynamic-xlsx-engine.md)

## What replaced what

| Phase 4 | Now |
|---|---|
| `RequirementsSheet::COLUMNS` (PHP constant) → `import` config category | Derived from `hierarchy_level_definitions` of the active structure version |
| `QiyasRequirementsTemplateExport` | `App\Exports\Hierarchy\HierarchyTemplateExport` |
| `QiyasImportValidator` / `QiyasImportService` | `App\Services\HierarchyImportValidator` / `HierarchyImportService` |
| Single Requirements sheet + hidden metadata | 4 sheets: Requirements · Instructions · Allowed Values · `_metadata` |
| `test_xlsx_template_columns_come_from_program_configuration_not_a_php_constant` | `…test_xlsx_template_columns_come_from_the_program_structure` |

Column count is now `(3 × levels) + enabled level attributes`, verified
per program: SUMOUD 3/14 · QIYAS 5/19 · ECC 5/19 · NDMO 6/22 · TEST3 3/14 ·
TEST5 5/20 · TEST7 7/26.

## What genuinely carried over

The design principle this document was right about — **a file's column
identity lives in its own embedded metadata, not in a PHP list** — is
unchanged and is now the foundation of the engine. Visible headings are
localized; identity is the machine identifier in `_metadata`. Renaming a
translated heading does not change import identity.

Formula-injection **rejection** (not sanitisation), macro-enabled-workbook
detection via real ZIP content inspection, and all-or-nothing transactional
confirm are likewise unchanged.

## Correction to the old "Not exercised this phase" note

That note stated no Playwright test drove the XLSX UI flow. **That is no
longer true.** Browser coverage now exists and passes:

- `tests/e2e/dynamic-xlsx/template-depth.spec.ts` — **7 tests**, including
  downloading a 5-level template, activating a 6-level structure, and
  confirming the old template is rejected with `INCOMPATIBLE_STRUCTURE_VERSION`
  while the newly generated 6-level template imports successfully.
- `tests/e2e/dynamic-reports/reports-and-xlsx.spec.ts` — **21 tests**
  covering the export contract.
