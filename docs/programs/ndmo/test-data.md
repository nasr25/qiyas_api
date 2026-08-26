# NDMO — Test Data

**No official NDMO domains, policies, standards, requirements,
subrequirements, evidence descriptions, or scoring formula exist in this
repository.** Everything below is development/testing-only content, kept
out of the production seeding path.

## Structural data (production seeder path — `DatabaseSeeder::run()`)

`NDMOWorkflowDefinitionSeeder`, `NDMOProgramConfigurationSeeder`,
`NDMOTestAccountsSeeder` — workflow structure, configuration values, and
test accounts, matching the established convention from the three other
programs.

## Sample content (NOT in `DatabaseSeeder` — run explicitly via
`php artisan system:ndmo-sample-data`, refuses to run when
`APP_ENV=production`)

`NDMOSampleDataSeeder` creates, via `ComplianceNodeService` and
`ResponsibilityService` (the same write paths an approved official import
and an authorized organizational user would use):

- One `ComplianceContentVersion` labeled `DEV-TEST-V1`.
- One active test cycle: "الدورة التجريبية لحوكمة البيانات 2026" / "NDMO
  Test Cycle 2026".
- A five-level hierarchy:
  - Domain: مجال تجريبي لحوكمة البيانات / NDMO Test Domain
  - Policy: سياسة تجريبية / Test Policy
  - Standard: معيار تجريبي / Test Standard
  - Requirement: متطلب تجريبي / Test Requirement, assigned to IT with a
    real assignment (Data Owner + Data Steward attached)
  - Subrequirement: متطلب فرعي تجريبي / Test Subrequirement

## Playwright E2E fixtures

The NDMO Playwright suite creates its own additional unique-coded test
requirements per run (`uniqueStandardCode().replace('E2E','NDMO-E2E')`)
under the seeded Domain/Policy/Standard, never reusing hardcoded IDs,
against the isolated `qiyas_e2e_db` only.
