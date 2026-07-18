# NDMO — Program Configuration

Seeded by `database/seeders/NDMOProgramConfigurationSeeder.php`, through
the same `ProgramConfigurationService` every other program's configuration
write goes through — separate `(compliance_program_id, category,
version)` rows.

| Category | Value | Source |
|---|---|---|
| `identity` | code=NDMO, name_ar/en, status=active, sort_order=4 | Migration `2026_07_22_000003_...` |
| `terminology` | Domain/Policy/Standard/Requirement/Subrequirement/Evidence/Assessment Cycle — per the Phase 7 brief, verbatim | Brief |
| `hierarchy` | 5 levels (domain→policy→standard→requirement→subrequirement), `max_depth: 5`. Requirement AND Subrequirement both assessable | Brief's recommended shape |
| `extensions` | requester_role=employee, reviewer_role=auditor | Same initial pattern as the other three programs |
| `evidence` | Organizational default limits | Same default shape as the other three programs |
| `assignment` | department_required=true, employee_assignment_required=false | Same initial pattern |
| `responsibilities` | **New category this phase.** enabled_types=[data_owner, data_steward] | Brief's explicit examples |
| `import` | program_code=NDMO, columns per the brief's suggested list | Brief |
| `features` | evidence/extension/sla/xlsx/notifications enabled; **scoring_enabled=false**; **not_applicable_enabled=false**; **assessment_result_enabled=false** | No approved formulas/business rules exist |

A new `responsibilities` validation schema was added to
`ProgramConfigurationService::validateValue()` this phase — the same
"no unsafe arbitrary configuration" guarantee every other category has:
only whitelisted `type`/`label_ar`/`label_en` fields are accepted, with no
field for granting workflow authority (deliberately absent, not merely
unused — see `responsibilities.md`).

## Independence proof

`NDMOProgramEngineTest::test_ndmo_is_created_and_configuration_is_independent`
reads Qiyas's `terminology.category.en` (`Axis`) after NDMO's own
configuration is seeded and asserts it is unchanged.
