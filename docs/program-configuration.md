# Program Configuration Engine

## Storage

`program_configurations` — one row per `(compliance_program_id, category)`,
holding the CURRENT active value as validated JSON, plus a `version`
counter. `program_configuration_versions` — append-only, one row per
write, never updated or deleted; the full history of every value a
category has ever held. Both tables and the `ProgramConfiguration`/
`ProgramConfigurationVersion` models are described in
`docs/compliance-engine-migration.md`'s refactoring plan.

## The only read/write path: `ProgramConfigurationService`

- `get(ComplianceProgram $program, string $category, array $default = []): array`
  — cached (1 hour TTL, keyed per program+category), returns `$default`
  if nothing has been configured yet.
- `set(ComplianceProgram $program, string $category, array $value, ?User $actor): ProgramConfiguration`
  — validates, versions, writes both tables, audit-logs via
  `AuditService::log('program_configuration.updated', ...)`, invalidates
  the cache. Wrapped in `DB::transaction()` with `lockForUpdate()` so two
  concurrent writes to the same category cannot interleave.

No other class writes to `program_configurations` directly.

## The 18 categories

`identity, terminology, hierarchy, workflow, assignment, evidence, review,
deadlines, extensions, sla, notifications, import, export, dashboards,
reports, scoring, security, features` — this exact list is
`ProgramConfiguration::CATEGORIES`. `set()` rejects any other category
name with `InvalidProgramConfigurationException` (422).

Categories actually populated for Qiyas as of Phase 4: `terminology`,
`extensions`, `evidence`, `assignment`, `import`, `features` — see
`docs/qiyas-engine-configuration.md` for the exact seeded values and their
source. The remaining categories are defined (and validated if written)
but not yet populated — Qiyas's current behavior for e.g. `dashboards` or
`scoring` is still governed by code, not configuration; extracting those
into configuration was scoped out of Phase 4 (see
`docs/compliance-engine-known-issues.md`).

## Validation — "no unsafe arbitrary configuration"

`ProgramConfigurationService::validateValue()` has a dedicated Laravel
`Validator` rule set for `terminology`, `extensions`, `evidence`,
`assignment`, `features`, and `import` — each accepts only a fixed,
whitelisted shape (e.g. `extensions.reviewer_role` must be one of
`program-manager|auditor|department-manager`, not an arbitrary string).
Categories without a dedicated schema yet fall back to
`assertSafeScalarStructure()`: rejects non-scalar leaf values, depth over
5, and more than 500 total elements — so even an unconfigured category
cannot be used to smuggle in a callable, an object, or an oversized
payload.

## Verified, not just designed

`tests/Feature/Engine/ProgramConfigurationEngineTest.php` proves, with
real HTTP-equivalent assertions, not just unit mocks:

- `test_program_configuration_service_rejects_an_unknown_category`
- `test_program_configuration_service_rejects_an_invalid_extensions_value`
- `test_every_configuration_write_is_versioned_and_audited` — writes twice,
  confirms both the version-history row count and an
  `audit_logs` entry with action `program_configuration.updated`.
- `test_extension_reviewer_role_is_configurable_and_the_extension_engine_honors_it`
  — reconfigures Qiyas's extension reviewer role from `auditor` to
  `department-manager` at runtime and confirms the actual authorization
  `Gate` check flips accordingly, with zero code change.
- `test_xlsx_template_columns_come_from_program_configuration_not_a_php_constant`
  — adds a column to the config, re-downloads the real generated XLSX
  file, and confirms the new column heading appears in the actual bytes.

These are the tests that justify calling this "configuration-driven"
rather than merely "configuration-shaped."
