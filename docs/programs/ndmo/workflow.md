# NDMO — Workflow

Seeded by `database/seeders/NDMOWorkflowDefinitionSeeder.php` into NDMO's
own `workflow_definitions`/`workflow_stage_definitions`/
`workflow_transition_definitions` rows — separate primary keys from all
three other programs, read through the exact same `WorkflowService`.

**This is an internal operational workflow, not a claim about an official
NDMO regulatory approval process.** No approved NDMO-specific workflow
has been supplied.

## Stages

`employee` (Requirement Owner) → `department_manager` → `auditor` (NDMO
Auditor) → `program_manager` (NDMO Program Manager) → `approved`

## Transitions

Identical shape to the other three programs: every rejection returns
directly to the Requirement Owner; every submission/resubmission targets
`department_manager`.

## Independence proof

`NDMOProgramEngineTest::test_ndmo_full_lifecycle_completes_via_the_generic_workflow_service`
and `test_ndmo_rejection_returns_directly_to_employee_and_resubmission_restarts_at_department_manager`
drive a real submission through all four stages and both the rejection
and resubmission paths, using the exact same `WorkflowService` class —
no NDMO-specific workflow code exists.
