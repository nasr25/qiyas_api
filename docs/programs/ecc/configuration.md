# ECC — Program Configuration

Seeded by `database/seeders/ECCProgramConfigurationSeeder.php`, through the
same `ProgramConfigurationService` every Qiyas/Sumoud configuration write
goes through — separate `(compliance_program_id, category, version)` rows.

| Category | Value | Source |
|---|---|---|
| `identity` | code=ECC, name_ar/en, status=active, sort_order=3 | Migration `2026_07_21_000004_...` |
| `terminology` | Main Domain/Subdomain/Control/Evidence Document/Assessment Cycle — per the Phase 6 brief, verbatim | Brief |
| `hierarchy` | **New category this phase** — 4 levels (domain→subdomain→control→subcontrol), `max_depth: 4`. See `hierarchy.md` | Brief's recommended shape |
| `extensions` | requester_role=employee, reviewer_role=auditor | Same initial pattern as Qiyas/Sumoud, independent row |
| `evidence` | Organizational default limits, not an official ECC file policy | Same default shape as Qiyas/Sumoud |
| `assignment` | department_required=true, employee_assignment_required=false | Same initial pattern |
| `import` | program_code=ECC, columns=Main Domain/Subdomain/Control Code+bilingual names/Description/Guidance/Evidence Requirements/Weight/Default Due Date | Brief's suggested columns |
| `features` | evidence/extension/sla/xlsx/notifications enabled; **scoring_enabled=false**; **not_applicable_enabled=false** | No approved formula/business rule exists — see `scoring-limitations.md`, `known-limitations.md` |

`sla` is not a `program_configurations` category — SLA settings live in the
already-generic per-program `sla_settings` table, created lazily on first
access (`SlaService::settingsFor()`), independent from Qiyas's and
Sumoud's rows. See `sla.md`.

## Independence proof

`ECCProgramEngineTest::test_ecc_is_created_and_configuration_is_independent`
reads Qiyas's `terminology.domain.en` (`Perspective`) after ECC's own
configuration is seeded and asserts it is unchanged.
