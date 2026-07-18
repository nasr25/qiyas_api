# NDMO — Extension Requests

Uses the same generic `ExtensionService`/`ExtensionRequestPolicy` every
other program uses. Reviewer role read from NDMO's own `extensions`
configuration (`reviewer_role: auditor`), independently stored.

Initial behavior (same pattern as the other three programs): Requirement
Owner requests, NDMO Auditor decides, Department Manager and Program
Manager view only, rejection reason mandatory, original due date
preserved, one pending request per assignment.

## Independence proof

`NDMOProgramEngineTest::test_ndmo_extension_reviewer_is_program_scoped`
proves a Qiyas-only Auditor is denied `decide` on an NDMO extension while
an NDMO-scoped Auditor is allowed — the same Gate-based mechanism proven
for Sumoud and ECC, unchanged.
