# Sumoud — Extension Requests

Uses the same generic `ExtensionService`/`ExtensionRequestPolicy` Qiyas
uses. The reviewer role is read from Sumoud's own `extensions` program
configuration (`reviewer_role: auditor`), not a hardcoded literal — the
exact Phase 4 fix that made this configurable in the first place is reused
unmodified.

Initial Sumoud behavior (same pattern as Qiyas):

- Employee requests, Auditor decides, Department Manager and Program
  Manager can view only.
- Rejection reason mandatory.
- Original due date preserved; effective due date changes only on
  approval.
- Only one active pending request per assignment.

## Independence proof

`SumoudProgramEngineTest::test_sumoud_auditor_can_decide_extensions_but_a_qiyas_only_auditor_cannot`
proves a Qiyas-only Auditor (`Gate::forUser($this->auditor)`) is denied
`decide` on a Sumoud extension, while a Sumoud-scoped Auditor is allowed —
role resolution is genuinely per-program, not by role name alone.

`SumoudProgramEngineTest::test_sumoud_extension_approval_does_not_change_qiyas_deadlines`
approves a Sumoud extension and asserts a separately-created Qiyas
assignment's `effective_due_date` is untouched.

Also covered by the Playwright cross-program role test (User A cannot
act on a Sumoud endpoint requiring a Sumoud role they don't hold — backend
returns 403, not just a hidden UI button).
