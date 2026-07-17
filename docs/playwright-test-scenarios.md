# Playwright Test Scenarios — Results

Final confirmed run (Chromium full suite + Firefox/WebKit smoke), all
against the isolated `qiyas_e2e_db` environment described in
`docs/playwright-e2e-guide.md`:

**19 test cases total — 18 passed, 1 skipped (data-dependent, not a
failure), 0 failed.**

## `qiyas/full-lifecycle.spec.ts` — the mandatory full journey (6/6 passing)

Split into 6 sequential `test()` blocks (Playwright's `describe.serial`)
covering the brief's 60 numbered steps:

| Test | Steps covered | Result |
|---|---|---|
| 1 | Program Manager creates a Standard (Perspective/Axis/name/weight) via real UI, verified in the correct cycle via API read | ✅ |
| 2 | Program Manager assigns it to Department A with instructions, verified via UI row + API read | ✅ |
| 3 | Employee A opens it, uploads evidence, reloads to confirm persistence, submits, confirms edit controls disappear | ✅ |
| 4 | Department Manager A reviews and approves, queue empties | ✅ |
| 5 | Auditor sees the Department Manager's decision, approves | ✅ |
| 6 | Program Manager sees the Auditor's decision, gives final approval; dashboard/history/report endpoints verified via API | ✅ |

**Deviation from the literal brief, documented not hidden**: step 13
("optionally assign Employee A") is exercised as department-only
assignment — the assignment-creation UI has no Employee-selection field at
all (a real, confirmed gap, see `docs/assignment-engine.md`). No "Save
Draft" button exists either; evidence upload auto-persists immediately
(confirmed by the reload-and-verify step), which the test treats as
satisfying the brief's draft-persistence requirement.

## `qiyas/rejection-journeys.spec.ts` — all three levels (3/3 passing)

Test A (Department Manager rejects, resubmission restarts at Department
Manager), Test B (Auditor rejects, confirmed to **not** reappear in the
Department Manager's queue, confirmed to return to the Employee with the
reason visible), Test C (Program Manager rejects, full new review cycle on
resubmission). Every test verifies the rejection reason is actually
visible to the Employee through the real UI — this is exactly the check
that caught the rejection-reason-banner defect (see
`docs/compliance-engine-known-issues.md`).

## `qiyas/extension-journey.spec.ts` (2/2 passing)

Test 1: Employee requests, Department Manager's own API-level attempt to
list/decide is confirmed 403, Auditor approves — original due date
unchanged, effective due date updated, verified via API read of the real
assignment record. Test 2: Auditor rejection — confirm button verified
disabled until a reason is entered (the mandatory-reason UI constraint),
effective due date confirmed unchanged after rejection.

## `permissions/isolation.spec.ts` (4/5 passing, 1 skipped)

Employee A → Department B assignment (403/404, no data leaked in the
response body); Employee → assign/import (403 at the API, and the nav
items themselves absent from the DOM for that role — both checked, not
just one); Executive Viewer → write action (403); Qiyas-only user →
nonexistent/unauthorized program code (404, indistinguishable from a
truly nonexistent program). The Department-Manager-cross-department test
is conditionally skipped when no Department B pending submission happens
to exist in the current seed state at test time — a legitimate data
dependency, not a defect; see known-issues for the recommendation to make
this deterministic with dedicated setup instead of relying on seed data.

## `qiyas/smoke.spec.ts` — cross-browser (3/3 passing: Chromium, Firefox, WebKit)

Login, program selection, and core navigation render correctly on all
three engines. The full scenario suite (lifecycle/rejection/extension/
permissions) runs on Chromium only, per `playwright.config.ts`'s
`testMatch` restriction on the firefox/webkit projects — this was a
deliberate scope decision given the time already invested finding and
fixing the six real defects below, not an oversight; see
`docs/compliance-engine-known-issues.md` for what a full cross-browser
scenario run would still need to confirm.

## Real defects found and fixed via this suite (not designed in advance)

1. `CycleDetailView.vue` gated standard-creation to `isSuperAdmin ||
   isCoordinator`, omitting `isQiyasAdmin` — the Program Manager literally
   could not create a Standard through the UI. **Fixed.**
2. `ProgramRequirementController::index()` returned a nested paginator
   object instead of a flat array, silently breaking the assignment form's
   requirement dropdown. **Fixed**, with a regression test.
3. A synchronous SMTP connection failure (inherent to the
   `QUEUE_CONNECTION=sync` E2E configuration) crashed the entire
   assignment-creation request with a 500 error. **Fixed** — notification
   dispatch is now resilient to delivery failures everywhere it happens.
4. `DemoDataSeeder`/`StandardsCatalogSeeder` never set the NOT-NULL
   `compliance_program_id` column on cycles/standards/documents they
   create — `php artisan migrate --seed` against a genuinely fresh
   database (never actually exercised before, since the real dev database
   accumulated its state incrementally across four phases) failed
   immediately. **Fixed** — a fresh install now works end to end, verified
   directly by successfully building the entire E2E environment from an
   empty database.
5. Reopening a rejected requirement immediately auto-creates a new draft
   evidence version (existing, correct Phase 2 behavior) — but the
   rejection-reason banner's visibility condition and the reason text
   itself were both derived from the **new, empty** version's own
   decisions, so the Employee could never actually see why their
   submission was returned. **Fixed** — both now derive from the
   assignment-level timeline, which correctly spans every version.
6. `AuditorReviewController::extensionRequests()` ordered by a nonexistent
   column (`requested_at` instead of `requested_date`) — every call
   failed with a 500. No prior test called this endpoint directly.
   **Fixed**, with a regression test. A related, larger gap this exposed:
   **no frontend page existed at all** for the Phase 2 program-scoped
   extension queue; built one this phase specifically to make this journey
   testable.
7. The Phase 3 login rate limiter (10/minute) correctly throttled the E2E
   suite's own repeated Quick Login calls for the same handful of test
   accounts. Not a defect — made the limit configurable via
   `LOGIN_RATE_LIMIT_PER_MINUTE` (default unchanged at 10) rather than
   weakening the production default.
