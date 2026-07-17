# Qiyas — Production Readiness Assessment (Phase 3)

## Release classification: **Conditionally Ready**

Not "Ready for production." Not "Not ready." The core workflow, security
posture, and data integrity are solid and verified by real, reproducible
tests — but several items required by the brief could not be verified in
this environment at all (real IIS, real AD, real load), and one gap (no
frontend test coverage) is a genuine, unresolved deliverable, not just an
unverifiable one. See "Blockers" below for exactly what has to happen
before this becomes "Ready for production."

## Why not "Ready for UAT" (i.e., why higher than the minimum bar)

UAT-readiness requires the workflow to actually work end to end, in both
languages, with correct authorization. All of that is true and verified:
82 Phase 1/2 tests plus 15 new Phase 3 tests (97 total) pass, covering the
full assign → submit → 3-stage review → approve/reject → resubmit cycle,
extension requests, SLA attribution, department/program isolation, and
XLSX import. A live browser walkthrough (Phase 2) additionally confirmed
the UI itself works, not just the API. This platform is genuinely ready
for a business user to sit down and test it.

## Why not "Ready for production" (i.e., why not higher than "Conditionally Ready")

1. **No frontend automated test coverage exists** (no Vitest, no
   Playwright, no ESLint). The brief explicitly asked for Playwright
   end-to-end coverage of the golden path and role-based UI behavior. This
   was not built in Phase 3 — see "Recommendation for next phase" below for
   why, and what the fastest path to closing it looks like.
2. **No real Windows Server/IIS/AD/SMTP environment was available** to
   deploy to and rehearse against. Every claim about IIS configuration,
   AD authentication, and SMTP delivery in this documentation set is
   written from code review and Laravel's documented requirements, not
   from a completed deployment — explicitly and repeatedly flagged as such
   throughout `docs/qiyas-deployment-iis.md`,
   `docs/windows-scheduler-and-queues.md`, and
   `docs/qiyas-known-issues.md`.
3. **No load/scale rehearsal** at the brief's target scale (500 employees,
   thousands of assignments) — see `docs/qiyas-performance-review.md` for
   what was checked instead (query/index review) and the one concrete gap
   found (unbounded report endpoints).
4. **No backup/restore drill actually performed** — the procedure is
   documented (`docs/qiyas-backup-and-recovery.md`) but not exercised.

None of these four are Critical or High **security** findings — all
confirmed security/correctness issues found during the audit were fixed and
verified (see `docs/qiyas-security-review.md`). They are operational
verification gaps specific to environments this session did not have
access to, plus one real, acknowledged product gap (frontend tests).

## Acceptance criteria from the brief — status

| Criterion | Status | Evidence |
|---|---|---|
| Complete workflow works end to end | ✅ Verified | `test_scenario1_full_approval_...`, live browser walkthrough (Phase 2) |
| Every rejection returns directly to Employee | ✅ Verified | `test_department_manager_rejection_returns_directly_to_employee`, `test_auditor_rejection_returns_directly_to_employee_not_department_manager`, `test_program_manager_rejection_returns_directly_to_employee` |
| Resubmission starts again from Department Manager | ✅ Verified | `test_resubmission_after_auditor_rejection_restarts_at_department_manager_not_auditor`, Phase 3 `test_scenario3_...`/`test_scenario4_...` |
| Extension requests decided only by the Auditor | ✅ Verified | `test_department_manager_cannot_decide_an_extension` |
| Original due dates preserved | ✅ Verified | `test_approved_extension_preserves_original_due_date`, Phase 3 `test_scenario6_...` (rejection leaves it unchanged too) |
| SLA calculated separately per stage | ✅ Verified | `test_sla_instance_opens_for_employee_stage_on_assignment`, `test_sla_completes_when_stage_ends_and_next_stage_opens` |
| Employee delay and reviewer delay reported separately | ✅ Verified | `test_reviewer_delay_is_not_attributed_to_employee`, Phase 3 `test_scenario7_...` |
| Department isolation enforced by backend | ✅ Verified | Multiple tests, see release checklist |
| Program isolation enforced by backend | ✅ Verified | Multiple tests, see release checklist |
| Email templates bilingual and Super-Admin-managed | ✅ Verified | 16 seeded bilingual templates, admin-only routes |
| XLSX imports use platform-generated template only | ✅ Verified, strengthened in Phase 3 | `test_wrong_program_template_is_rejected` + macro-content check (was filename-only before Phase 3) |
| Invalid imports don't partially save | ✅ Verified | `test_import_preview_does_not_save_any_data`, transactional `confirm()` |
| Evidence files versioned and securely authorized | ✅ Verified | Unique `(assignment, version_number)` constraint, `EvidenceSubmissionPolicy` |
| Arabic/English interfaces work | ✅ Verified | Live browser walkthrough, i18n coverage review |
| Automated tests pass | ✅ 97/97 | `php artisan test` |
| Existing Qiyas data remains intact | ✅ Verified | `compliance:verify-migration` + `compliance:verify-qiyas`, both clean |

Every criterion the brief said must hold before claiming completion does
hold, and each is backed by a named, currently-passing test — not a claim
without evidence.

## Recommendation for next phase

In priority order:

1. **A minimal Playwright golden-path suite** (assign → submit → 3-stage
   approve → verify approved; one rejection-and-resubmit path; login as
   each of the 6 roles and verify nav/button visibility matches the role
   matrix). This is the highest-value single addition — it's the one gap
   in this report that is a real product gap, not an environment
   limitation, and it directly covers the "Playwright... recommended for
   this phase" instruction that wasn't completed. Scoping it as "golden
   path only" rather than the full scenario list keeps it achievable in a
   focused follow-up rather than open-ended.
2. **A real Windows Server/IIS/AD deployment rehearsal**, working through
   `docs/qiyas-deployment-iis.md` and `docs/qiyas-operational-runbook.md`
   literally, checking off `docs/qiyas-release-checklist.md`'s
   not-verifiable-in-dev items with real evidence.
3. **A backup/restore drill**, following
   `docs/qiyas-backup-and-recovery.md`'s restore validation checklist at
   least once against a non-production copy of the data.
4. **Pagination for the three unbounded report endpoints** identified in
   `docs/qiyas-performance-review.md`, coordinated with the frontend report
   views that consume them.

## Stop condition

Per the brief: **this phase does not begin Sumoud, ECC, or NDMO work**, and
did not. All changes in this phase are Qiyas-scoped hardening, verification,
and documentation.
