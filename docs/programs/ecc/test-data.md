# ECC — Test Data

**No official ECC domains, subdomains, controls, subcontrols,
implementation guidance, evidence requirements, or scoring formula exist
in this repository.** Everything below is development/testing-only
content, kept out of the production seeding path.

## Structural data (production seeder path — `DatabaseSeeder::run()`)

`ECCWorkflowDefinitionSeeder`, `ECCProgramConfigurationSeeder`,
`ECCTestAccountsSeeder` — workflow structure, configuration values, and
test accounts, matching the exact same convention already established for
`TestUsersSeeder` and Sumoud's equivalents (already in the production
seeder path).

## Sample content (NOT in `DatabaseSeeder` — run explicitly via
`php artisan system:ecc-sample-data`, refuses to run when
`APP_ENV=production`)

`ECCSampleDataSeeder` creates, via `ComplianceNodeService` (the same write
path an approved official import would eventually use):

- One `ComplianceContentVersion` labeled `DEV-TEST-V1`, explicitly
  described as "Development/testing sample content (not an official ECC
  source)".
- One active test cycle: "الدورة التجريبية للأمن السيبراني 2026" / "ECC
  Test Cycle 2026", tied to that content version.
- A four-level hierarchy:
  - Domain: مجال تجريبي للأمن السيبراني / ECC Test Domain (`ECC-D1`)
  - Subdomain: مجال فرعي تجريبي / ECC Test Subdomain (`ECC-D1-S1`)
  - Control 1: ضابط تجريبي 1 / ECC Test Control 1 (`ECC-D1-S1-C1`), assigned to IT
  - Subcontrol: ضابط فرعي تجريبي / ECC Test Subcontrol (`ECC-D1-S1-C1-SC1`)
  - Control 2: ضابط تجريبي 2 / ECC Test Control 2 (`ECC-D1-S1-C2`), assigned to HR

## Playwright E2E fixtures

The ECC Playwright suite creates its own additional unique-coded test
controls per run (`uniqueStandardCode().replace('E2E','ECC-E2E')`) under
the seeded Domain/Subdomain, never reusing hardcoded IDs, against the
isolated `qiyas_e2e_db` only.
