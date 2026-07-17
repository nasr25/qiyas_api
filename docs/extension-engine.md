# Extension Engine

## What changed in Phase 4

The reviewer role (who may decide an extension request) is now program
configuration (category `extensions`, key `reviewer_role`), read by both:

- `ExtensionRequestPolicy::decide()` — the actual authorization gate,
  previously a hardcoded `hasProgramRole($program, 'auditor')` literal.
- `ExtensionService::reviewersAndManager()` — the notification-recipient
  resolution, previously hardcoded the same way.

`AuditorReviewController::extensionRequests()`'s listing-access check and
`decideExtension()`'s authorization check were also updated: the listing
check now reads the configured role (`isConfiguredExtensionReviewer()`),
and the decide action now actually invokes `Gate::forUser(...)->denies('decide', $model)`
— previously, the Policy's `decide()` ability existed but was **never
called anywhere** (confirmed by grepping for `->can('decide'` and
`Gate::...->allows('decide'` across the codebase — zero matches before
this phase), so the real enforcement was entirely the controller's own
separate hardcoded check. The Policy is now the actual, single source of
truth for this decision.

The `/reviews/auditor/extension-requests` URL path itself remains fixed to
the word "auditor" — this is Qiyas's own route naming, and a future
program with a differently-configured reviewer role would still hit a
URL that says "auditor" even if a Department Manager is the one actually
authorized to act on it. Documented as a real, un-fixed limitation rather
than implied to be solved — see `docs/compliance-engine-known-issues.md`.

## For Qiyas specifically (unchanged business rule)

Employee requests, Auditor decides, Department Manager views only,
rejection reason mandatory, only one pending request per assignment,
original due date never touched. All unchanged — the *mechanism*
enforcing "Auditor decides" moved from code to configuration; the *rule*
itself did not change, per the brief's explicit instruction.

## Discovered and fixed via Phase 4 E2E testing

1. `AuditorReviewController::extensionRequests()` ordered by a
   nonexistent column (`requested_at` — the real column is
   `requested_date`), causing every call to this endpoint to fail with a
   500 error. No prior automated test called this listing endpoint
   directly (only the approve/reject actions were tested), so this was
   never caught until the real Auditor extension-queue UI was driven
   end-to-end. **Fixed**, with a regression test
   (`tests/Feature/Workflow/AuditorExtensionQueueTest.php`).
2. **No frontend page existed at all** for this program-scoped extension
   queue — the backend routes and frontend service methods
   (`reviewQueueService.extensionRequests/approveExtension/rejectExtension`)
   were already correct and complete from Phase 2, but no `.vue` view or
   router entry consumed them; the Auditor's only reachable extension
   screen was the legacy platform-level `/auditor/extensions` page (a
   different, Document-based flow). Built
   `AuditorExtensionQueueView.vue` + route
   `program-extension-queue` (`/programs/{program}/extension-requests`)
   this phase specifically to make the brief's required Extension Journey
   testable through real UI actions at all.

## Verified

`tests/Feature/Engine/ProgramConfigurationEngineTest.php`'s
`test_extension_reviewer_role_is_configurable_and_the_extension_engine_honors_it`
reconfigures the reviewer role from `auditor` to `department-manager` at
runtime and confirms the `Gate` check flips both ways. Two full
Playwright journeys (`tests/e2e/qiyas/extension-journey.spec.ts`) exercise
request → Department-Manager-view-only → Auditor-approve, and
request → Auditor-reject-with-mandatory-reason, entirely through real UI
interaction, both passing.
