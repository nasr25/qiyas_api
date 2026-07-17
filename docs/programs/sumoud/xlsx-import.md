# Sumoud — XLSX Import/Export

Uses the exact same `QiyasRequirementsTemplateExport`/`QiyasImportValidator`/
`QiyasImportService` classes Qiyas uses (their "Qiyas" naming is legacy —
their implementation is already fully program-generic, confirmed by
reading the source: columns are resolved from the acting program's
`import` configuration, and the hidden metadata sheet's `program_code` is
checked against the *current* program on every validate call). See
`known-limitations.md` for the honest note on the class naming itself.

Sumoud's template (`SumoudProgramConfigurationSeeder`'s `import` category)
exposes: Domain Code, Category Code, Requirement Code, Requirement Name
(Arabic/English), Description, Objective, Evidence Requirements, Weight,
Default Due Date — generic machine column keys, bilingual visible headings
only.

The one real code change this phase: the downloaded template's file name
was hardcoded to `qiyas-requirements-template.xlsx` regardless of program
— fixed to `strtolower($program->code).'-requirements-template.xlsx'` so a
downloaded Sumoud template is identifiably named.

## Cross-program protection — proven, not assumed

`SumoudProgramEngineTest::test_sumoud_import_template_is_rejected_by_qiyas_validator_and_vice_versa`
generates a real Sumoud template, feeds it to the Qiyas validator, and
asserts `WRONG_PROGRAM`. The Playwright cross-program isolation suite
repeats this at the HTTP level through the real `/requirements-import/preview`
endpoint.

## Not built this phase

A full Sumoud XLSX Playwright journey (download → upload valid fixture →
preview → confirm → verify hierarchy; upload invalid rows → verify no
partial import; unsupported version rejection) — the underlying validator
is unchanged and covered at the PHPUnit level
(`QiyasImportTest.php`, unaffected), but no Sumoud-specific fixture files
or E2E journey were built. Same class of gap Phase 4 left for Qiyas
itself.
