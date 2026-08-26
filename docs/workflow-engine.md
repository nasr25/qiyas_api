# Workflow Engine

## Data model

- `workflow_definitions` — one active definition per `(program, key)`,
  `key` defaults to `'requirement_review'`.
- `workflow_stage_definitions` — one row per stage: `stage_key`,
  `sort_order`, `responsible_role_key`, `requires_comment`,
  `requires_rejection_reason`, `sla_applies`, `notifications_enabled`,
  `is_final`.
- `workflow_transition_definitions` — one row per `(from_stage_key,
  action, to_stage_key)`. `action` is `approve | reject | submit`.

`stage_key` values are the **same** internal keys the platform already
used before Phase 4 (`employee`, `department_manager`, `auditor`,
`program_manager`, `approved`) — deliberately not renamed to the brief's
suggested `employee_action`/`department_manager_review`/etc., to avoid
touching every existing `WorkflowDecision.stage`/`SlaInstance.stage` enum
value, test, and stored historical record for a cosmetic difference. See
`docs/compliance-engine-migration.md` for that decision.

## `WorkflowDefinitionRepository`

The only class that reads these tables. Cached per program (1 hour TTL) as
**plain arrays**, not Eloquent models — caching the models directly through
a serializing cache store (`database`, used outside automated tests, which
run on the non-serializing `array` driver) failed with
`__PHP_Incomplete_Class` on unserialization, discovered the hard way while
seeding the Phase 4 E2E environment. See
`docs/compliance-engine-known-issues.md`.

- `definition(program, key)` → `['stages' => [...], 'transitions' => [...]]` or null.
- `stage(program, stageKey)` → that stage's config array, or null.
- `nextStage(program, fromStage, action)` → the `to_stage_key`, or null if
  no such transition is defined (an invalid transition, which the caller
  must reject).
- `forgetCache(program)` — call after any admin edit to a definition.

## `WorkflowService` — what actually changed

Every `approve()`/`reject()`/`submit()` call now resolves its next stage
via `WorkflowDefinitionRepository::nextStage()` instead of the old
`NEXT_STAGE`/`STATUS_FOR_STAGE` PHP constants. `STATUS_FOR_STAGE` itself
(the map from stage key to the `evidence_submissions.status` enum string)
was **kept**, deliberately — see the honest limitation below.

Final-approval detection changed from `if ($nextStage === null)` to
`if ($nextStageDefinition['is_final'])` — using the stage definition's own
flag instead of a magic null sentinel, which is both more correct and
what makes an `'approved'` stage a real, inspectable row instead of an
implicit code branch.

## Honest limitation: the status enum is still fixed

`evidence_submissions.status` is a MySQL `enum` with exactly six values,
matching Qiyas's three-reviewer shape (`pending_department_manager`,
`pending_auditor`, `pending_program_manager`, plus `draft`,
`returned_for_revision`, `approved`). The transition **graph** is now
genuinely data-driven — reconfiguring which stage follows which is a
config change (proven by
`test_changing_the_workflow_definition_changes_where_an_approval_moves_the_submission`,
which skips the Auditor stage entirely via configuration and confirms the
resulting status). But a *differently-shaped* future workflow — say, two
reviewer stages instead of three — would still need a migration to extend
this enum. This is real, deferred technical debt, not solved by making the
transition graph configurable, and it is documented as such rather than
implied to be handled. See `docs/compliance-engine-known-issues.md`.

## Verified

- `tests/Feature/Engine/ProgramConfigurationEngineTest.php`:
  `test_workflow_transitions_are_read_from_the_database_not_a_php_constant`
  (confirms a program with no seeded definition has zero transitions —
  proof this is genuinely data-driven, not a fallback constant) and
  `test_changing_the_workflow_definition_changes_where_an_approval_moves_the_submission`.
- The full pre-existing Phase 2/3 suite (97 tests before this phase) passes
  **unchanged** after the refactor — the seeded Qiyas definition
  reproduces the old hardcoded behavior exactly.
- The Playwright full-lifecycle and rejection-journey E2E suites exercise
  every transition through real UI actions against the live engine — see
  `docs/playwright-test-scenarios.md`.
