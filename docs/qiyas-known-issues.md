# Qiyas — Known Issues and Deferred Items (Phase 3)

This is the authoritative, honest list of what was **not** fully verified or
not implemented as of the Phase 3 audit, referenced by every other Phase 3
document instead of repeating itself. Nothing here was silently dropped —
each item was either out of scope, infeasible to verify in this environment,
or a deliberate scope decision, and is called out explicitly rather than
implied to be done.

## Verified vs. not verified — environment constraints

This audit ran entirely in a local macOS development environment (PHP 8.4,
SQLite for the automated test suite, MySQL 8 via XAMPP for manual/demo
verification, `php artisan serve` + Vite dev server). The following could
**not** be exercised for real and are explicitly unverified, not "assumed
fine":

- **Windows Server / IIS deployment.** No Windows Server or IIS instance was
  available to deploy to. `docs/qiyas-deployment-iis.md` and
  `docs/windows-scheduler-and-queues.md` are written from Laravel's
  documented Windows/IIS requirements and this platform's actual
  configuration, not from a live deployment. The URL Rewrite rules, PHP
  handler mapping, and file-permission steps have not been executed against
  a real IIS site.
- **Active Directory authentication.** No AD/LDAP server was available.
  `LdapService` was code-reviewed (bind logic, escaping, empty-password
  guard added in Phase 3) but never authenticated against a real domain
  controller. `docs/qiyas-operational-runbook.md`'s AD troubleshooting
  section is written from the code and common AD error codes, not from a
  reproduced failure.
- **Load / concurrency at realistic scale.** No test simulated 500 employees,
  thousands of standards, or genuinely concurrent reviewers hitting the API
  at once. The concurrency *correctness* guarantee (row locking, 409 on
  conflicting decisions) is verified by an automated test
  (`test_conflicting_double_decision_returns_409`), but concurrency
  *performance* under real load is not measured. See
  `docs/qiyas-performance-review.md` for what was checked instead (query
  patterns, N+1 review, pagination).
- **Real SMTP delivery.** Email sending was verified via `Notification::fake()`
  in tests and the existing `EmailLogController`/`test-send` code path was
  read, not exercised against a real mail server in this session.
- **Frontend automated tests.** No Vitest/Jest/Playwright test runner exists
  in the frontend project (`frontend/package.json` has no test script, no
  ESLint config). This was true before Phase 3 and remains true after it —
  see "Frontend test infrastructure" below.
- **Restore-from-backup drill.** The backup *procedure* is documented
  (`docs/qiyas-backup-and-recovery.md`), but no backup was actually taken and
  restored in this environment to prove the procedure works end to end.

## Confirmed and fixed in Phase 3

See `docs/qiyas-security-review.md` §"Findings and fixes" for the full list
with severity and verification. Summary:

1. **[High] No rate limiting on `/auth/login` / `/auth/quick-login`** — fixed
   with a named `login` rate limiter (10/min per username+IP). Verified by
   `test_login_is_rate_limited_after_repeated_attempts`.
2. **[High] Disguised-executable upload bypass** — the evidence-upload
   dangerous-MIME blocklist did not include the MIME strings real PE
   executables report (`application/x-dosexec`,
   `application/vnd.microsoft.portable-executable`), so a `.exe` renamed to
   `.pdf` could pass both the extension and old MIME checks. Fixed by
   expanding the blocklist. Verified by
   `test_disguised_executable_upload_is_rejected_by_real_mime_detection`
   using a real minimal PE (MZ/PE) header, not a mocked MIME string.
3. **[Medium] XLSX macro-enabled workbook check was filename-only** — a
   `.xlsm` renamed to `.xlsx` would pass the extension check untouched.
   Fixed by inspecting the actual ZIP contents for `xl/vbaProject.bin`
   inside `HierarchyImportValidator::validate()`, which runs on both preview
   *and* confirm. Verified by
   `test_macro_enabled_workbook_renamed_to_xlsx_is_rejected_on_import_preview`.
4. **[Medium] LDAP empty-password defense-in-depth gap** — `LdapService`
   relied solely on the login form's `required` validation to prevent an
   empty-password "unauthenticated bind." Fixed with an explicit guard
   inside the service itself, checked first and unconditionally.
5. **[Medium] No security-response headers at all** — `X-Content-Type-Options`,
   `X-Frame-Options`, `Referrer-Policy`, `Permissions-Policy`, and a strict
   `Content-Security-Policy` were entirely absent. Fixed with a global
   `SecurityHeaders` middleware. Verified by
   `test_security_headers_are_present_on_api_responses`.
6. **[Low] CORS localhost regex was environment-independent** — fixed to be
   gated off when `APP_ENV=production`.
7. **[Low] Two outdated dependencies with published CVEs** —
   `guzzlehttp/guzzle` (cookie/proxy-downgrade advisories) and
   `guzzlehttp/psr7` (CRLF injection) — updated to patched versions;
   `composer audit` now reports zero advisories. `form-data` (frontend,
   CRLF injection) updated via `npm audit fix`; `npm audit` now reports zero
   vulnerabilities.
8. **[Improvement] 55 pre-existing files failed Laravel Pint style checks**
   (all Phase 0/1 code — the Phase 2 code added in the prior session was
   already clean). Auto-fixed with `./vendor/bin/pint`; re-ran the full test
   suite after to confirm no behavioral change.

## Deliberately not implemented (matches the Phase 3 "do not implement" list)

- No Sumoud/ECC/NDMO content.
- No additional approval layers, committees, or RACI.
- No cross-framework evidence library or mapping engine.
- No SMS/WhatsApp/Teams integration.
- No AI-generated recommendations.

## Carried forward from Phase 1/2 (still open)

- **Domain/Category normalization**: `Standard.perspective`/`axis` remain
  free-text columns rather than a normalized lookup table — a Phase 1
  decision, not reopened in Phase 3.
- **XLSX import "update mode"**: only create/update-by-standard-number is
  implemented; a distinct explicit update flow with pre-change value preview
  is deferred (`docs/qiyas-xlsx-import.md`).
- **~47 of the 70 backend test scenarios** originally requested in Phase 2
  were implemented; Phase 3 added 9 more targeted tests (rate limiting,
  disguised-executable upload, macro-workbook detection, LDAP guard,
  security headers, health check, data-integrity command × 2, plus 7
  end-to-end business-scenario tests) for **97 total backend tests**, all
  passing. This is still short of "every one of the 70 originally named
  scenarios has its own dedicated test" — several are covered indirectly
  (e.g., through the state-machine's centralized precondition checks rather
  than one test per named scenario).
- **Frontend test infrastructure**: still does not exist. No ESLint, no unit
  test runner, no Playwright. This is the single largest gap blocking a
  "production-ready" classification on its own terms — see
  `docs/qiyas-production-readiness.md`.
- **Configurable holiday calendar**: SLA business-day calculation supports a
  configurable weekly working-days pattern but not date-specific holidays.
- **SLA pause/resume of a single active instance's clock**: `SlaSetting` has
  pause-related configuration fields, but mid-instance pause/resume of an
  already-running clock is not fully wired.
- **Not every one of the ~21 named notification events** has a distinct
  wired trigger; 16 are implemented and seeded (see
  `docs/email-notifications.md`).

## What would need to happen before "production ready" (not "conditionally ready")

1. A real Windows Server/IIS deployment rehearsal following
   `docs/qiyas-deployment-iis.md`, with the operational checklist in
   `docs/qiyas-release-checklist.md` actually executed and checked off.
2. A real Active Directory connection test against the target domain.
3. At least a minimal frontend test suite (Playwright golden-path E2E is the
   single highest-value addition — see
   `docs/qiyas-production-readiness.md` §"Recommendation for next phase").
4. A backup taken and successfully restored once, following
   `docs/qiyas-backup-and-recovery.md`.
5. A realistic-scale data/load rehearsal (or at minimum, `EXPLAIN` review of
   the heaviest dashboard/report queries under a seeded dataset in the
   thousands of rows, which Phase 3 did not have time to run beyond the
   ~100-row demo dataset already in the dev database).
