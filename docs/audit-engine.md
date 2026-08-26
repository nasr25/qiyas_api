# Audit Engine

No changes in Phase 4. `AuditService::log()` was already the single,
unified audit interface every Phase 1-3 module used — confirmed during
the coupling analysis, no duplicated audit-logging path was found to
consolidate.

## What's new that flows through it

`ProgramConfigurationService::set()` calls `AuditService::log(
'program_configuration.updated', ..., $oldValue, $newValue,
complianceProgramId: $program->id)` on every configuration write — the
Program Configuration Engine's entire change history is therefore already
visible through the existing platform-wide audit log view (`GET
/api/v1/admin/audit-logs`), not a separate mechanism a Super Admin would
need to learn.

## Verified

`test_every_configuration_write_is_versioned_and_audited`
(`tests/Feature/Engine/ProgramConfigurationEngineTest.php`) asserts a real
`audit_logs` row with `action = 'program_configuration.updated'` exists
after a configuration write, using the same `AuditService` every other
Phase 1-3 action already relies on.
