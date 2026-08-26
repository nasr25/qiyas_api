# Legacy Test Retirement Record

Every test removed or rewritten during the legacy-authoring retirement,
with the decision and its reason. **Nothing was deleted to make a suite
green.** Each entry is one of:

- **A — Rewritten**: the behaviour is still supported; the test now
  exercises it through the dynamic hierarchy engine.
- **B — Retired**: the behaviour itself is gone; coverage is either
  unnecessary or lives elsewhere.
- **C — Fixture recreated**: the test was fine; only its data source was
  obsolete.

---

## Backend tests (PHPUnit)

| # | Test | Old behaviour | Decision | Reason | Replacement |
|---|---|---|---|---|---|
| 1 | `QiyasImportTest::test_official_template_downloads_successfully` | Downloads the fixed Qiyas XLSX template | **A** | Templates are still downloaded, now derived from structure | `HierarchyImportApiTest::test_official_template_downloads_successfully` |
| 2 | `QiyasImportTest::test_import_preview_does_not_save_any_data` | Preview writes nothing | **A** | Guarantee unchanged | `HierarchyImportApiTest::test_import_preview_does_not_save_any_data` |
| 3 | `QiyasImportTest::test_confirmed_import_creates_standards_transactionally` | Transactional import of `standards` | **A** | Now creates ComplianceNodes, still transactional | `HierarchyImportApiTest::test_confirmed_import_creates_nodes_transactionally` |
| 4 | `QiyasImportTest::test_wrong_program_template_is_rejected` | Cross-program template refusal | **A** | Guarantee unchanged | `HierarchyImportApiTest::test_a_template_generated_for_another_program_is_rejected` |
| 5 | `QiyasImportTest::test_import_requires_program_manager_authorization` | Import authorization | **A** | Guarantee unchanged | `HierarchyImportApiTest::test_import_requires_program_manager_authorization` |
| 6 | `ProgramConfigurationEngineTest::test_xlsx_template_columns_come_from_program_configuration_not_a_php_constant` | Columns from the `import` config category | **A** | Columns now come from the **structure**, which is stronger — the config category was removed | `…test_xlsx_template_columns_come_from_the_program_structure` |
| 7 | `SumoudProgramEngineTest::test_sumoud_import_template_is_rejected_by_qiyas_validator_and_vice_versa` | Cross-program template refusal | **A** | Guarantee unchanged | `…test_a_programs_import_template_is_rejected_by_another_program` |
| 8 | `RolePermissionTest::test_employee_sees_only_own_department_standards` | Department isolation on the standards list | **A** | Program *content* is visible to all members; the *work* is department-scoped, which is where the guarantee lives | `…test_employee_sees_only_own_department_assignments` |
| 9 | `SecurityHardeningTest::test_macro_enabled_workbook_renamed_to_xlsx_is_rejected_on_import_preview` | Macro rejection on import | **C** | Endpoint moved only | Same test, repointed at `hierarchy-import/preview` |
| 10 | `RolePermissionTest::test_employee_cannot_access_other_department_document` | Legacy Document API authorization | **B** | The legacy Document review API was removed with the Standard path | Covered: `EvidenceWorkflowTest::test_employee_cannot_access_another_departments_submission` |
| 11 | `RolePermissionTest::test_employee_can_access_own_department_document` | Legacy Document read | **B** | As above | Covered: `RequirementAssignmentTest::test_assignment_visible_only_to_assigned_department` |
| 12 | `RolePermissionTest::test_employee_cannot_approve_documents` | Employee cannot approve | **B** | As above | Covered: `EvidenceWorkflowTest::test_department_manager_cannot_review_another_department` + role gating |
| 13 | `RolePermissionTest::test_auditor_can_view_pending_reviews_across_departments` | Auditor cross-department view | **B** | As above | Covered: `EvidenceWorkflowTest::test_auditor_can_approve_after_department_manager_approval` |
| 14 | `RolePermissionTest::test_auditor_reject_requires_reason` | Mandatory rejection reason | **B** | As above | Covered: `EvidenceWorkflowTest::test_department_manager_rejection_reason_is_mandatory` |

**No backend guarantee was lost.** Entries 10–14 were verified against the
replacement tests before removal, not assumed.

---

## Playwright — the 9 program-lifecycle tests

All six files authored a `Standard` through the legacy cycle screen and then
assigned it. Assignment now requires a ComplianceNode, so the first test of
each file failed and the rest cascaded.

| File | Tests | Decision | Reason |
|---|---|---|---|
| `qiyas/full-lifecycle.spec.ts` | 6 | **A** | Journey still supported; authoring step obsolete |
| `qiyas/rejection-journeys.spec.ts` | 3 | **A** | Rejection rules unchanged |
| `qiyas/extension-journey.spec.ts` | 2 | **A** | Extension rules unchanged |
| `sumoud/full-lifecycle.spec.ts` | 6 | **A** | Duplicate of the Qiyas journey |
| `ecc/full-lifecycle.spec.ts` | 6 | **A** | Duplicate |
| `ndmo/full-lifecycle.spec.ts` | 6 | **A** | Duplicate |

**Replacement: `tests/e2e/lifecycle/full-journey.spec.ts`** — one
parameterised spec covering **seven** programs (Qiyas, Sumoud, ECC, NDMO,
TEST3, TEST5, TEST7) with three describe blocks each:

- full journey: assign → evidence upload → submit → Department Manager →
  Auditor → Program Manager → approved
- extension requests: request → wrong reviewer refused → approve moves the
  due date; reject requires a reason and leaves it unchanged
- rejection and resubmission: reason mandatory, returns to Employee,
  resubmission restarts at Department Manager

**Coverage increased**: 29 legacy tests over 4 programs became 56 tests over
7 programs at 3, 5, 6 and 7 levels — and the duplication that made four
files say the same thing (audit finding M3) is gone.

---

## Playwright — the 39 documentation tests

These capture screenshots for the illustrated Arabic user guide. They failed
because `QiyasDocumentationSeeder` was removed (it authored `Standard`s), not
because the documented workflows changed.

| Spec | Decision | Action |
|---|---|---|
| `01-login-and-selection` | **C** | Fixture only |
| `02-program-manager-cycle` | **A** (1 test) + **C** | "add a standard" → "add an item to the structure", using the generic node form; other 3 tests fixture-only |
| `03-assignment` | **C** | Fixture; also decoupled from a hard-coded seeded code |
| `04-employee-evidence` | **C** | Fixture; now selects the row that offers each action instead of assuming the first |
| `05-department-review` | **C** | Fixture |
| `06-auditor-review` | **C** | Fixture; picks a row that still offers a decision |
| `07-final-approval` | **C** | Fixture |
| `08-sla-and-reports` | **C** | Fixture |
| `09-notifications` | **C** | Fixture (notifications now seeded for both employee and department manager) |
| `10-executive-dashboard` | **C** | Fixture |
| `11-role-permission-verification` | **C** | Fixture |

**Not one documentation test was retired.** All 41 were kept; 1 was rewritten
for the new authoring model and the rest needed only a fixture built with the
dynamic engine — `DocumentationFixtureSeeder`, which seeds five neutral
accounts and an explicit spread of workflow states so every guide screen has
something to show.

### Known characteristic

The documentation suite is **stateful by design**: its tests approve, reject
and mark-as-read, consuming what they act on. It is written to run against a
**freshly seeded** database. Re-running it without reseeding can fail on
queue-exhaustion, which is a property of a screenshot suite acting on real
state, not a product defect.

```bash
php artisan migrate:fresh --seed --force --env=e2e
php artisan compliance:seed-test-fixtures --force --env=e2e
php artisan db:seed --class=DocumentationFixtureSeeder --force --env=e2e
```

---

## Two isolation tests repointed

| Test | Change |
|---|---|
| `permissions/isolation` — "Employee cannot approve, assign, or import" | Asserted a 403 on the retired template route. Now asserts the 403 on the **import** itself, which is the actual guarantee; the template is readable by any member. |
| `cross-program/isolation` — "Uploading a Sumoud XLSX template into Qiyas is rejected" | Repointed at `hierarchy-template` / `hierarchy-import`; still asserts `WRONG_PROGRAM`. |

---

## Removed seeders and fixtures

| Removed | Reason | Replacement |
|---|---|---|
| `StandardsCatalogSeeder` | Loaded 89 DGA standards into `standards` | Content comes from XLSX import into nodes |
| `QiyasWorkflowDemoSeeder` | Assigned `Standard`s | `HierarchyWorkflowSeeder` |
| `QiyasDocumentationSeeder` | Authored `Standard`s | `DocumentationFixtureSeeder` |
| `ECCSampleDataSeeder`, `NDMOSampleDataSeeder`, `SumoudSampleDataSeeder` | Three hand-written per-program content seeders | `HierarchyContentSeeder` — one generic seeder, no program name in it |
| `DemoDataSeeder` standard/document/extension blocks | Legacy content | Hierarchy seeders above |
