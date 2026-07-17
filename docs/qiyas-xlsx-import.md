# Qiyas XLSX Requirements Import

## Template structure

`QiyasRequirementsTemplateExport` (`app/Exports/Qiyas/`) generates a
4-sheet workbook — this is the **only** file format the importer accepts;
an arbitrary Excel file is never treated as valid, no matter how closely it
resembles the template (see "Validation" below):

| Sheet | Purpose |
|---|---|
| `Requirements` | The actual data rows. Header row uses **stable, untranslated, snake_case machine identifiers** (`perspective`, `axis`, `standard_number`, `name_ar`, `name_en`, `description`, `application_requirements`, `evidence_documents`, `weight`, `due_date`) — never renamed, never translated, so the importer can locate columns reliably regardless of UI language. |
| `Instructions` | Bilingual (Arabic/English) explanation of every column, using Qiyas terminology (المنظور/Perspective, المحور/Axis, المعيار/Standard) for human readers — this is where translated labels live, deliberately kept separate from the machine header row. |
| `Allowed Values` | The program's existing distinct `perspective`/`axis` values, shown for consistency — not a hard allowlist. |
| `_metadata` (hidden) | `template_version`, `program_code`, `schema_version`, `export_date`, `cycle_id`, `column_identifiers` — checked **before** anything on the visible sheets is trusted. |

### Column mapping to the existing Qiyas data model

No new database columns were introduced for the import — every visible
column maps directly onto an existing `standards` table field (see
`docs/multi-program-architecture.md` for why `Standard` was not split into
separate Domain/Category tables in Phase 1): `perspective`→`perspective`,
`axis`→`axis`, `standard_number`→`standard_number`, `name_ar`/`name_en`,
`description`, `application_requirements` (the brief's "Objective" column),
`evidence_documents` (the brief's "Evidence Requirements" column), `weight`,
`due_date`. A `notes` column was intentionally **not** added, since
`Standard` has no dedicated notes field and inventing one was judged
unnecessary for Phase 1.

## Import workflow

1. **Download** — `GET /api/v1/programs/{program}/requirements-template`
   (Program Manager only), optionally scoped to a cycle for the "Allowed
   Values" sheet content.
2. **Fill and upload** —
   `POST /api/v1/programs/{program}/requirements-import/preview`
   (`.xlsx` only, size-limited, requires a target `cycle_id`). The file is
   stored on the `private` disk and an `ImportLog` row is created
   (`status: validating`).
3. **Validate** — `QiyasImportValidator::validate()` runs against the
   *stored* file (never re-reads the client's original upload directly, so
   the same code path runs again identically on confirm). **Nothing is
   saved to `standards` during this step.**
4. **Preview** — the response returns row/error counts and a structured
   error list; the `ImportLog` status becomes `ready_for_confirmation` only
   if there are zero errors, otherwise `validation_failed`.
5. **Confirm** — `POST .../requirements-import/{importLog}/confirm`
   (Program Manager, explicit action) re-reads and **re-validates** the
   same stored file (defense against the file changing between preview and
   confirm), then writes every valid row inside a single
   `DB::transaction()`. If validation fails on confirm, nothing is written
   and the log is marked `failed`.
6. **Error report** — `GET .../requirements-import/{importLog}/error-report`
   downloads an XLSX listing every error (sheet/row/column/value/code/
   bilingual message), generated on demand from the JSON error list saved
   during validation.

## Validation rules implemented

- File extension must be `.xlsx` (macro-enabled `.xlsm` is rejected outright
  by extension check — no macro is ever executed).
- Workbook must be readable (corrupt files fail cleanly with a bilingual
  error, not a 500).
- `_metadata` sheet must exist, with `program_code` matching the resolved
  program and `template_version` matching the currently supported version
  (`QIYAS-TEMPLATE-1.0` — deliberately not a bare numeric string, since
  Excel silently coerces `"1.0"` to the number `1`, which broke the
  version check during development and was fixed before release).
- `Requirements` sheet must exist with a header row matching the expected
  column identifiers (validated against the metadata sheet's own
  `column_identifiers` list, not a hard-coded copy — the two must agree).
- Per row: `standard_number` and `name_ar` are required; `standard_number`
  max length 50, `name_ar` max length 500; duplicate `standard_number`
  within the same file is rejected; duplicate `standard_number` already
  present in the target cycle is rejected (create mode only — see below);
  `weight` must be numeric 0–100 if present; `due_date` must be a valid
  `YYYY-MM-DD` string or a valid Excel date serial.
- Fully empty rows are skipped silently (not reported as errors).
- Row count is capped (5,000) to reject pathological files.

Not implemented in Phase 1 (documented, not silently dropped): formula-cell
detection/rejection on *import* (formulas are simply read as their
evaluated text/number by PhpSpreadsheet, so there is no code-execution risk,
but a dedicated "this cell contains a formula" warning is not surfaced),
and content-based malware scanning beyond extension/MIME checks.

## No partial import

The transactional confirm step means a file with **any** blocking error
never reaches the database — `preview` never writes, and `confirm` only
writes inside one transaction that either fully commits or fully rolls
back. There is no code path that saves "the valid rows" from a file that
also contained errors.

## Import modes

Only **create** mode (which also updates an existing standard with the same
`standard_number` in the same cycle, matching the pre-existing
`StandardsImport` behavior for the legacy flat import) is implemented. A
separate, explicit "update mode" with pre-change-preview and audit-logged
old-value capture, as described in the brief, is deferred — see the final
report.

## Formula injection protection (exports)

`ImportErrorReportExport` prefixes any user-controlled cell value that
starts with `=`, `+`, `-`, or `@` with a leading apostrophe before writing
it, so re-opening the generated error report in Excel/Sheets never
evaluates attacker-controlled content as a formula.

## Security

- Only the resolved program's Program Manager (or Super Admin) may
  download the template, upload, preview, confirm, or download an error
  report — enforced identically in every `QiyasImportController` method.
- Every step is audit-logged: `import.template_downloaded`,
  `import.validated`, `import.completed` (or the underlying validation
  failure state visible via `ImportLog.status`).
