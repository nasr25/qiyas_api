# ECC — Extension Requests

Uses the same generic `ExtensionService`/`ExtensionRequestPolicy` Qiyas/
Sumoud use. Reviewer role read from ECC's own `extensions` configuration
(`reviewer_role: auditor`), independently stored.

Initial behavior (same pattern as Qiyas/Sumoud): Employee/Control Owner
requests, ECC Auditor decides, Department Manager and Program Manager view
only, rejection reason mandatory, original due date preserved, one
pending request per assignment.

## Independence proof

`ECCProgramEngineTest::test_ecc_extension_reviewer_is_program_scoped`
proves a Qiyas-only Auditor is denied `decide` on an ECC extension while
an ECC-scoped Auditor is allowed — the same Gate-based mechanism proven
for Sumoud in Phase 5, unchanged.
