# Sumoud — Program Configuration

Seeded by `database/seeders/SumoudProgramConfigurationSeeder.php`, using the
same `ProgramConfigurationService` every Qiyas configuration write goes
through — separate `(compliance_program_id, category, version)` rows, never
shared or mutable across programs.

| Category | Value | Source |
|---|---|---|
| `identity` | code=SUMOUD, name_ar=صمود, name_en=Sumoud, status=active, is_active=true, sort_order=2 | Migration `2026_07_20_000001_...` (mirrors QIYAS's own bootstrap row) |
| `terminology` | Domain/Category/Requirement/Evidence Document/Program Cycle — generic defaults exactly as specified in the Phase 5 brief | Brief, verbatim |
| `extensions` | requester_role=employee, reviewer_role=auditor, rejection_reason_required=true, allow_multiple_pending=false | Same initial pattern as Qiyas, independent row |
| `evidence` | Same default extensions/size/count limits as Qiyas's defaults | Organizational default, not an approved Sumoud file policy |
| `assignment` | department_required=true, employee_assignment_required=false, reassignment_reason_required=true | Same initial pattern as Qiyas |
| `import` | program_code=SUMOUD, columns=Domain/Category/Requirement Code+bilingual names/Description/Objective/Evidence Requirements/Weight/Default Due Date | Brief's suggested visible columns, generic machine keys |
| `features` | evidence/extension/sla/xlsx import+export/employee-assignment/notifications enabled; **scoring_enabled = false** | No approved Sumoud scoring formula exists — see `known-limitations.md` |

`sla` is not a `program_configurations` category — SLA settings live in
their own per-program `sla_settings` table (`SlaSetting` model, one row per
program, already generic since Phase 1/2). Sumoud's row is created lazily
on first read/write via `SlaService::settingsFor()`, independent from
Qiyas's row. See `sla.md`.

## Independence proof

`SumoudProgramEngineTest::test_sumoud_configuration_is_independent_of_qiyas_and_qiyas_is_unchanged`
reconfigures Sumoud's `extensions.reviewer_role` at runtime and asserts
Qiyas's own `extensions` row is unaffected — a real behavioral test, not an
assumption.
