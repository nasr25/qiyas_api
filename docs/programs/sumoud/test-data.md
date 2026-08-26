# Sumoud — Test Data

**No official Sumoud domains, controls, requirements, evidence
descriptions, scoring formulas, or regulatory text exist in this
repository.** Everything below is development/testing-only content, kept
deliberately out of the production seeding path.

## Structural data (production seeder path — `DatabaseSeeder::run()`)

- `SumoudWorkflowDefinitionSeeder` — workflow structure, not content.
- `SumoudProgramConfigurationSeeder` — configuration values, not content.
- `SumoudTestAccountsSeeder` — test accounts, same convention as the
  existing `TestUsersSeeder` (which is *also* already in the production
  seeder path — this matches established project convention, not a new
  exception).

## Sample content (NOT in `DatabaseSeeder` — run explicitly via
`php artisan system:sumoud-sample-data`, refuses to run when
`APP_ENV=production`)

`SumoudSampleDataSeeder` creates:

- One active test cycle: "الدورة التجريبية لصمود 2026" / "Sumoud Test
  Cycle 2026".
- Two requirements, explicitly named:
  - Domain: منظور تجريبي لصمود / Sumoud Test Domain
  - Category: محور تجريبي لصمود / Sumoud Test Category
  - Requirements: متطلب تجريبي لصمود 1/2 / Sumoud Test Requirement 1/2
- Assigned to the shared Information Technology / Human Resources
  departments.

## Playwright E2E fixtures

The Sumoud Playwright suite creates its own additional unique-coded test
requirements per run (`uniqueStandardCode().replace('E2E','SMD-E2E')`),
never reusing hardcoded IDs, against the isolated `qiyas_e2e_db` only.
