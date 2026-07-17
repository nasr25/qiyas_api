# Sumoud — Workflow

Seeded by `database/seeders/SumoudWorkflowDefinitionSeeder.php` into
Sumoud's own `workflow_definitions`/`workflow_stage_definitions`/
`workflow_transition_definitions` rows — separate primary keys from
Qiyas's definition, read through the exact same `WorkflowService`/
`WorkflowDefinitionRepository` classes Qiyas uses.

## Stages (identical shape to Qiyas's initial pattern, per the brief)

`employee` → `department_manager` → `auditor` → `program_manager` → `approved`

## Transitions

| From | Action | To |
|---|---|---|
| employee | submit | department_manager |
| department_manager | approve | auditor |
| department_manager | reject | employee |
| auditor | approve | program_manager |
| auditor | reject | employee |
| program_manager | approve | approved |
| program_manager | reject | employee |

Every rejection returns directly to Employee. Every submission/resubmission
targets `department_manager`. No committees, extra approval levels, or RACI
were introduced.

## Independence proof

`SumoudProgramEngineTest::test_changing_sumoud_workflow_definition_does_not_change_qiyas`
mutates Sumoud's `department_manager → approve` transition at runtime
(retargeting it to `program_manager`, skipping Auditor) and asserts Qiyas's
own transition for the same stage/action is unaffected — proving separate
configuration versions, not just separate initial rows.

## Known limitation (shared with Qiyas, not new)

`evidence_submissions.status` remains a fixed six-value MySQL enum matching
this exact five-stage shape. A future program needing a *differently
shaped* review sequence (different reviewer count) would need a migration
— documented already in Phase 4's `docs/workflow-engine.md` and unchanged
by Sumoud, since Sumoud's stage count matches Qiyas's.
