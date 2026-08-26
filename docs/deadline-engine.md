# Deadline Engine

No structural changes in Phase 4 — `RequirementAssignment`'s four-field
due-date model (`original_due_date`, `effective_due_date`, `completed_at`,
plus the derived `days_overdue`/`extension_count`) was already generic and
already correctly distinguished from workflow SLA breach (see
`docs/qiyas-security-review.md` / `docs/qiyas-production-readiness.md`,
Phase 3, for the original verification of this separation).

## Reverified in Phase 4 via real E2E evidence, not just unit assertions

- `tests/e2e/qiyas/extension-journey.spec.ts` — approves a real extension
  through the Auditor's UI, then reads the assignment back via API and
  asserts `effective_due_date` moved to the requested date while the
  original stays implicitly unchanged (never touched by the approval
  code path). The rejection test in the same file asserts
  `effective_due_date` is **byte-identical** to its pre-request value
  after an Auditor rejection.
- The responsible-party/employee-vs-reviewer-delay distinction itself was
  not re-litigated this phase (already verified in Phase 3's
  `test_scenario7_employee_submission_delay_is_attributed_to_the_employee_not_a_reviewer`
  and `test_reviewer_delay_is_not_attributed_to_employee`); Phase 4 did not
  change any of that logic.

## Not exercised this phase

Overdue-as-a-separate-condition was verified via API in Phase 3
(`test_scenario9_overdue_is_a_calculated_condition_independent_of_workflow_status`)
but not re-verified through a real UI dashboard view in Phase 4's
Playwright suite — the E2E suite this phase focused on the workflow
transition journeys and authorization, not a dedicated dashboard-values
scenario. See `docs/compliance-engine-known-issues.md`.
