# NDMO — XLSX Import/Export

Uses the same generic export/validator/service classes every other
program uses (columns resolved from NDMO's own `import` configuration;
the hidden metadata sheet's `program_code` is checked against the current
program on every validate call). NDMO's template exposes Domain Code,
Policy Code, Requirement Code, Requirement Name (Arabic/English),
Description, Guidance, Evidence Requirements, Weight, Default Due Date.

The downloaded file name reflects the program code
(`ndmo-requirements-template.xlsx`, same mechanism fixed for Sumoud in
Phase 5).

## Cross-program protection — proven, not assumed

The `WRONG_PROGRAM` metadata check (unchanged since Phase 4) rejects an
NDMO template uploaded to any other program and vice versa — the same
mechanism already proven for Sumoud/ECC; no NDMO-specific work was
needed to inherit this protection.

## Honest limitation, carried forward unchanged from ECC (Phase 6)

**The importer still targets the flat `Standard` level, not the full
five-level `ComplianceNode` tree, and does not create a
`ComplianceContentVersion` record on confirm.** This is the same gap
documented in `docs/programs/ecc/xlsx-import.md`, now confirmed to affect
a second program (NDMO) as well — strengthening the case that this is a
priority engine gap, not an ECC-specific oversight. See
`known-limitations.md` and `content-versioning.md`.
