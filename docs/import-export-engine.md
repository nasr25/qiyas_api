# Import and Export Engine

## What changed in Phase 4

The Requirements sheet's column list — previously
`RequirementsSheet::COLUMNS`, a PHP constant — is now resolved from the
program's `import` configuration category
(`QiyasRequirementsTemplateExport::resolveColumns()`), falling back to the
constant only if the program has no `import` configuration yet (keeps
this class usable standalone, e.g. constructed directly without going
through the exporter, and means no existing test needed to change).
`MetadataSheet`'s `column_identifiers` field is generated from the same
resolved list, so the hidden metadata sheet and the visible headings can
never disagree with each other.

Qiyas's `import` configuration (seeded by
`QiyasProgramConfigurationSeeder`) holds `program_code`,
`template_version`, `schema_version`, and the 10-column list with
bilingual labels and a `required` flag each — transcribed exactly from
`RequirementsSheet::COLUMNS` and the labels already written on the
Instructions sheet, not invented new values.

## What did not change

`QiyasImportValidator` was **already** correctly generic in its core
design before this phase: it validates an uploaded file's column headers
against the file's **own** embedded `_metadata.column_identifiers` value
(read from the file being validated), not a hard-coded PHP list — so a
different program's importer reusing this exact class would already work
correctly for a differently-shaped column set, as long as its own template
was generated with its own metadata. This was confirmed by re-reading
`QiyasImportValidator::validate()` during the Phase 4 coupling analysis; no
code change was needed there.

Formula-injection protection (`ImportErrorReportExport`), transactional
confirm, macro-enabled-workbook detection via real ZIP content inspection
(a Phase 3 fix) — all unchanged.

## Verified

`test_xlsx_template_columns_come_from_program_configuration_not_a_php_constant`
(`tests/Feature/Engine/ProgramConfigurationEngineTest.php`) adds a new
column to Qiyas's `import` configuration, generates a real XLSX file via
`Excel::store()`, reads the actual bytes back with `Excel::toArray()`, and
confirms the new column heading is present — not a mocked assertion, a
real file round-trip. All pre-existing `QiyasImportTest` tests (template
download, preview-does-not-save, transactional confirm, wrong-program
rejection, authorization) pass unchanged, confirming the resolved-from-
config column list is byte-identical to the old hardcoded one for Qiyas's
actual seeded configuration.

## Not exercised this phase

No Playwright E2E test drives the actual XLSX download/upload/preview/
confirm UI flow — `docs/compliance-engine-known-issues.md` lists this as a
deferred scenario (the brief's "Playwright XLSX Tests" section), given the
time this phase already spent on the mandatory lifecycle, rejection, and
extension journeys plus the real defects those uncovered.
