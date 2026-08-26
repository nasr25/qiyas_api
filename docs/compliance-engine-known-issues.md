# Compliance Engine — Known Issues (Phase 4)

> **Status update — 26 August 2026.** This is the Phase 4 gap list. Several
> items have since been closed by the Dynamic Compliance Engine (XLSX browser
> coverage, hierarchy-grouped metrics, arbitrary hierarchy depth). Items
> marked resolved inline are current; the rest still stand. Current status:
> [`final-dynamic-compliance-engine-report-ar.md`](final-dynamic-compliance-engine-report-ar.md).


The honest gap list this phase's final report points to. Nothing here was
silently dropped — each item was either explicitly out of scope, a genuine
time-budget tradeoff, or infeasible in this environment, and is named as
such rather than implied to be handled.

## Engine consolidation left partial

- **Dashboard/Reporting Engine**: only `WorkflowDashboardController`'s
  Program Manager view was migrated onto `DashboardMetricsService`. Three
  other dashboard controllers and the report controllers still duplicate
  count-query logic in spirit — see `docs/dashboard-reporting-engine.md`
  for the explicit reasoning (seven controllers, each serving a different,
  live, tested audience; full consolidation under this phase's time
  pressure risked breaking working code for cosmetic gain).
- **Assignment rule flags not wired up**: the `assignment` program
  configuration category (`department_required`,
  `employee_assignment_required`, etc.) describes Qiyas's current behavior
  but nothing reads these flags yet — `WorkflowService::assign()`'s method
  signature is still the actual enforcement mechanism. See
  `docs/assignment-engine.md`.
- **Workflow status enum still fixed to Qiyas's shape**:
  `evidence_submissions.status` is a MySQL enum with exactly six values.
  The transition *graph* is genuinely configurable (proven by a real test
  that reconfigures it and observes the effect), but a future program with
  a *differently-shaped* review sequence (a different number of reviewer
  stages) would still need a migration to extend this enum. See
  `docs/workflow-engine.md`.
- **`/reviews/auditor/extension-requests` URL is not program-configurable**
  — the route path itself says "auditor" regardless of which role a
  program configures as the reviewer. The underlying authorization check
  is correctly config-driven; the URL naming is not. See
  `docs/extension-engine.md`.

## Frontend gaps discovered, not all fixed

- **No Employee-selection field** on the assignment-creation form — "assign
  a specific Employee" (explicitly optional per the brief) is only
  reachable via a direct API call, not this UI. See
  `docs/assignment-engine.md`.
- **Perspective/Axis remain free-text fields**, not normalized entities
  with their own IDs — carried forward from Phase 1, not reopened this
  phase (a materially larger schema change than the time available).

## Playwright coverage — what was and wasn't built

Built and passing (19 test cases, 18 passed, 1 legitimate data-dependent
skip): the mandatory full lifecycle, all three rejection levels, both
extension outcomes, five authorization/isolation checks, and a
three-browser smoke test. See `docs/playwright-test-scenarios.md` for the
full breakdown.

**Not built this phase** (all explicitly requested in the brief):

- **SLA time-travel tests** — no fake-clock/time-control test harness was
  added; SLA warning/breach detection is only verified at the PHPUnit
  level (`Carbon::setTestNow()`-style manipulation), not through a real
  browser session watching a countdown change. Building a safe,
  test-only time-control mechanism (explicitly required to be
  production-inaccessible per the brief) was judged lower priority than
  the defect-finding value of the journey tests actually built.
- **XLSX Playwright tests** — ~~entirely untested through the UI this
  phase~~. **Resolved.** `tests/e2e/dynamic-xlsx/template-depth.spec.ts`
  (7 tests) now drives template download, upload, preview, confirm and
  structure-version rejection through the UI. Backend coverage moved from
  `QiyasImportTest.php` to `HierarchyImportApiTest.php` and
  `HierarchyXlsxTest.php` (32 tests).
- **Responsive tests** — `playwright.config.ts` already defines `tablet`
  and `mobile` projects pointed at `tests/e2e/responsive/`, but no spec
  files exist there yet. No responsive-specific defect is claimed to be
  fixed or confirmed absent.
- **Full cross-browser scenario suite** — Firefox and WebKit run only the
  login/navigation smoke test; the full lifecycle/rejection/extension
  suites run on Chromium exclusively. A Firefox- or WebKit-specific defect
  in the actual workflow forms cannot be ruled out from this phase's
  evidence alone.
- **Notification-content Playwright assertions** — the E2E suite confirms
  workflow *state* changes correctly (status transitions, due dates,
  authorization), not the literal content/recipients of every one of the
  ~13 notification events named in the brief's "Playwright Notification
  Tests" section. `NotificationDeduplicationTest.php` (PHPUnit, Phase 2)
  still covers deduplication and template safety at the unit level.
- **Concurrent-tab / duplicate-decision Playwright test** — the underlying
  409-conflict guarantee is unchanged and still covered by
  `test_conflicting_double_decision_returns_409` (PHPUnit), but no
  Playwright test opens two real browser contexts against the same
  submission to observe it end to end.

## Real defects found and fixed this phase (see `docs/playwright-test-scenarios.md` for full detail)

1. `CycleDetailView.vue` permission check omitted the Program Manager role
   — could not create standards. Fixed.
2. `ProgramRequirementController::index()` paginator-nesting bug — broke
   the assignment form's requirement dropdown. Fixed, with a regression
   test.
3. Synchronous notification/mail failures could crash the triggering
   workflow request. Fixed with resilience wrapping everywhere
   notifications are dispatched.
4. Fresh-install seeding (`php artisan migrate --seed` against a truly
   empty database) failed on a NOT NULL constraint never exercised before
   this phase. Fixed — verified by actually building the E2E environment
   from empty.
5. The rejection-reason banner silently disappeared the moment an Employee
   reopened a rejected requirement, because reopening auto-creates a new,
   decision-less evidence version. Fixed.
6. `AuditorReviewController::extensionRequests()` crashed on every call
   (wrong column name in an `orderBy`) — never caught because no prior
   test called this endpoint. Fixed, with a regression test. The larger
   gap this exposed — no frontend page existed for this endpoint at all —
   was also closed by building `AuditorExtensionQueueView.vue`.

## Environment-specific lessons (not application defects, but real, and worth recording)

- Caching Eloquent models with loaded relations through a *serializing*
  cache store (`database`, used in real deployments — the PHPUnit suite
  runs on the non-serializing `array` driver and never exercised this
  path) can fail with `__PHP_Incomplete_Class` on unserialization.
  `WorkflowDefinitionRepository` now caches plain arrays specifically to
  avoid this class of bug — a real, generalizable finding for any future
  code that caches Eloquent models directly.
- A Vite dev server's `import.meta.env.VITE_API_URL` is resolved from the
  project's `.env` file with *higher* precedence than a same-named
  shell-exported variable — attempting to override it via `process.env`
  alone silently fails. `frontend/.env.e2e` + `--mode e2e` is the correct,
  reliable mechanism (see `docs/playwright-e2e-guide.md`). Getting this
  wrong initially caused several early E2E test runs to silently write
  test data into the real development database instead of the isolated
  one — caught and cleaned up before this became a lasting problem, and
  documented here specifically so it is not repeated.

## Release readiness

See the final Phase 4 report for the formal Sumoud-readiness
classification and full blocker list, built from the evidence in this
document plus `docs/playwright-test-scenarios.md`.
