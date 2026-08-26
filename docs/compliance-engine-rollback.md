# Compliance Engine — Rollback Plan

> **Status update — 26 August 2026.** This page covers the Phase 4 rollback
> plan. The dynamic hierarchy migrations have their own **tested** rollback
> procedure and evidence — see
> [`dynamic-hierarchy-rollback.md`](dynamic-hierarchy-rollback.md), which
> records an actually-executed rollback (all checks passed) rather than a
> plan, including two real defects the test itself uncovered.


## Database

All five Phase 4 migrations
(`2026_07_19_000001` through `_000005`) are purely additive new tables
(`program_configurations`, `program_configuration_versions`,
`workflow_definitions`, `workflow_stage_definitions`,
`workflow_transition_definitions`) — none alter or drop an existing
column. Rollback is a standard reversible migration:

```bash
php artisan migrate:rollback --step=5
```

Each migration's `down()` simply `dropIfExists`s its own table, in reverse
creation order (Laravel handles this automatically) — no data-loss risk to
any pre-existing table.

## Application code

If the Workflow Engine refactor needs to be rolled back specifically
(leaving the new tables in place but unused), reverting
`app/Services/WorkflowService.php` and `app/Services/WorkflowDefinitionRepository.php`
to their pre-Phase-4 state (available in the previous commit) restores the
hardcoded `NEXT_STAGE`/`STATUS_FOR_STAGE` constants — this is safe because
the seeded `workflow_transition_definitions` rows are an exact
transcription of those constants, so behavior is identical either way; the
rollback is a pure implementation-detail reversion, not a business-rule
change.

Similarly, `ExtensionRequestPolicy`, `EvidenceUploadValidator`,
`ExtensionService`, the XLSX template export, and
`WorkflowDashboardController` can each be reverted independently — every
Phase 4 change to them has a fallback path already built in (platform
`Setting` for evidence limits, the literal `'auditor'` role for
extensions), so partial
rollback of any one engine does not require rolling back the others.

## Frontend

`AuditorExtensionQueueView.vue` + its route
(`program-extension-queue`) and nav entry are new, additive files — removing
them (and the router/nav entries) fully reverts to the pre-Phase-4 state
where this queue had no dedicated UI (the legacy
`/auditor/extensions` page remains unaffected either way, since it is a
separate, untouched code path).

The `CycleDetailView.vue` permission-check fix
(`isQiyasAdmin` addition) and the `MyRequirementDetailView.vue`
rejection-reason-banner fix are **not** safe to roll back in isolation —
both fix confirmed, real defects (a Program Manager unable to create
standards; an Employee unable to see why their submission was rejected).
Rolling either back would reintroduce a known bug, not just undo a
refactor.

## Configuration data

`program_configurations`/`program_configuration_versions` rows for Qiyas
can be cleared with `DELETE FROM program_configurations WHERE
compliance_program_id = (SELECT id FROM compliance_programs WHERE code =
'QIYAS')` — the application code that reads these (via
`ProgramConfigurationService::get()`) always falls back to its own
hardcoded default when no row exists (see each engine's fallback,
documented per-engine above), so clearing the configuration table does not
break Qiyas; it only reverts each engine to its pre-Phase-4 hardcoded
value for that specific setting.

## What a rollback does NOT need to touch

`SlaService`, `SlaSetting`, `ReviewQueueController`,
`EnsureProgramAccess`, `AuditService` — unchanged this phase, nothing to
roll back.
