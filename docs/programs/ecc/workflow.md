# ECC — Workflow

Seeded by `database/seeders/ECCWorkflowDefinitionSeeder.php` into ECC's own
`workflow_definitions`/`workflow_stage_definitions`/
`workflow_transition_definitions` rows — separate primary keys from
Qiyas's and Sumoud's, read through the exact same `WorkflowService`/
`WorkflowDefinitionRepository` classes.

**This is an organizational implementation workflow, not a claim about the
official ECC regulatory assessment procedure.** No approved ECC-specific
workflow has been supplied; until one is, ECC uses the same initial
pattern as Qiyas/Sumoud per the brief's explicit instruction.

## Stages

`employee` (Control Owner) → `department_manager` → `auditor` (ECC
Auditor) → `program_manager` (ECC Program Manager) → `approved`

## Transitions

Identical shape to Qiyas/Sumoud: every rejection returns directly to the
Control Owner; every submission/resubmission targets
`department_manager`. No committees, extra approval levels, or RACI were
introduced.

## Independence proof

`ECCProgramEngineTest::test_ecc_full_lifecycle_completes_via_the_generic_workflow_service`
and `test_ecc_rejection_returns_directly_to_employee_and_resubmission_restarts_at_department_manager`
drive a real submission through all four stages and both the rejection and
resubmission paths, using the SAME `WorkflowService` class Qiyas/Sumoud
tests exercise — no ECC-specific workflow code exists to test separately.
