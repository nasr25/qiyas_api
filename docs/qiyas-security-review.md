# Qiyas — Phase 3 Security Review

Scope: the Qiyas program's authentication, authorization, workflow,
file-handling, notification, and import code, reviewed against OWASP Top 10
categories and the specific attack scenarios named in the Phase 3 brief. See
`docs/qiyas-known-issues.md` for what could not be exercised in this
environment (real AD, real load, real IIS).

Method: direct source review of every file in the request path for each
area (not just the newest Phase 2/3 files), plus targeted automated tests
that reproduce the attack and assert the fix — findings below are marked
**CONFIRMED** only where a test or a direct reproduction backs the claim.

## Findings and fixes

| # | Severity | Area | Finding | Fix | Verification |
|---|---|---|---|---|---|
| 1 | High | Authentication | No rate limiting on `POST /auth/login` or `POST /auth/quick-login` — unlimited password-guessing attempts against any username. | Named `login` rate limiter (Laravel `RateLimiter`), keyed by `strtolower(username)+ip`, 10 requests/minute, applied via `throttle:login` middleware. | `tests/Feature/Security/SecurityHardeningTest::test_login_is_rate_limited_after_repeated_attempts` (11th attempt returns 429) and `test_login_rate_limit_is_scoped_per_username_not_global` (a different username from the same IP is unaffected). |
| 2 | High | File upload (Broken Access Control / Insecure Design) | `EvidenceUploadValidator`'s dangerous-MIME blocklist did not include the MIME type real Windows PE executables report (`application/x-dosexec` / `application/vnd.microsoft.portable-executable`, confirmed to differ by platform's libmagic version). A file named `invoice.pdf` containing a real executable would pass the extension allowlist, the extension blocklist, and the old MIME blocklist. | Blocklist expanded to include both PE MIME variants, plus ELF/Mach-O binaries and markup types (`text/html`, `image/svg+xml`) as defense-in-depth against a future allowlist change. | `test_disguised_executable_upload_is_rejected_by_real_mime_detection` — builds a real, structurally valid minimal PE (MZ/PE) header via `Illuminate\Http\UploadedFile` (not a test-fake, which does not perform real content detection — see note below), uploads it as `report.pdf`, asserts 422. |
| 3 | Medium | XLSX import (Software and Data Integrity Failures) | The ".xlsm files are rejected" behavior was enforced only by checking the client-supplied filename string in the controller. A `.xlsm` renamed to `.xlsx` would pass through untouched — the actual macro-execution risk is low (PhpSpreadsheet never executes VBA, it only reads cell values), but the stated guarantee was not actually true. | `HierarchyImportValidator::validate()` now inspects the real ZIP contents for `xl/vbaProject.bin` (the definitive OOXML macro marker) before any sheet is parsed, and runs on **both** preview and confirm, closing a gap where confirm had no macro check at all. | `test_macro_enabled_workbook_renamed_to_xlsx_is_rejected_on_import_preview` — builds a real `.xlsx`-named ZIP containing `xl/vbaProject.bin`, asserts the specific `MACRO_ENABLED_REJECTED` error code. |
| 4 | Medium | Authentication (Identification and Authentication Failures) | `LdapService::authenticate()` had no guard of its own against an empty password — the classic "unauthenticated LDAP bind" vulnerability, where some directory configurations treat `ldap_bind($conn, $dn, '')` as an anonymous bind and report success. Mitigated only by `LoginRequest`'s `required` rule one layer up, not by the service itself. | Explicit `trim($password) === ''` guard added as the first line of `authenticate()`, unconditional and independent of `isConfigured()` or any caller. | Reviewed directly; not independently unit-testable against a real LDAP server in this environment (no AD available — see known-issues doc), but the guard is unconditional code, not configuration-dependent, so it cannot be bypassed by a caller that skips `LoginRequest`. |
| 5 | Medium | Security Misconfiguration | No security response headers were set anywhere in the stack: no `X-Content-Type-Options`, no `X-Frame-Options`, no `Referrer-Policy`, no `Content-Security-Policy`, no `Permissions-Policy`, no `Strict-Transport-Security`. | New global `App\Http\Middleware\SecurityHeaders`, appended to every request. Since this is a pure JSON API (the real UI is the separate Vue SPA), the CSP is `default-src 'none'; frame-ancestors 'none'` — deliberately strict rather than loosened to avoid breaking anything, since a JSON API has no inline scripts/styles to allow for. | `test_security_headers_are_present_on_api_responses`. |
| 6 | Low | Security Misconfiguration (CORS) | `config/cors.php` always allowed the `http://localhost:*` origin pattern regardless of environment. Not independently exploitable (a browser sets `Origin` from the real page origin; it cannot be spoofed by a malicious page's content), but a production config should not carry a development convenience by default. | Pattern is now empty when `APP_ENV=production`. | Manual code review; config-level change, no test needed (Laravel doesn't unit-test its own CORS config resolution meaningfully in this codebase's existing patterns). |
| 7 | Low (supply chain) | Vulnerable dependencies | `composer audit` reported 3 medium advisories (`guzzlehttp/guzzle` cookie-domain and proxy-downgrade issues, `guzzlehttp/psr7` CRLF injection). `npm audit` reported 1 high advisory (`form-data` CRLF injection). | `composer update guzzlehttp/guzzle guzzlehttp/psr7 --with-all-dependencies` (7.10.6→7.15.0, 2.11.0→2.13.0); `npm audit fix` (form-data patched). Both are patch/minor bumps within existing constraints, not major-version upgrades. | `composer audit` → "No security vulnerability advisories found." `npm audit` → "found 0 vulnerabilities." Full backend test suite (97/97) and frontend build re-verified green after both updates. |

## Areas reviewed with no confirmed finding

- **Workflow state machine** (`WorkflowService`): every transition is
  gated by an explicit `status === expectedStatus` precondition check inside
  a `DB::transaction()` + `lockForUpdate()`, and the "stage" parameter is a
  hardcoded return value per controller subclass
  (`DepartmentManagerReviewController::stage()` etc.), never derived from
  request input — so an Auditor calling the Department Manager's endpoint,
  or any attempt at `pending_department_manager → approved`, `approved →
  draft`, etc., fails with a `WorkflowConflictException` (409), not a silent
  status change. Reviewed directly against every transition named in the
  brief's "invalid transitions" list; each is structurally impossible, not
  merely discouraged by UI.
- **Authorization / policies** (`RequirementAssignmentPolicy`,
  `EvidenceSubmissionPolicy`, `ExtensionRequestPolicy`, `SlaSettingPolicy`):
  every method checked against the full role matrix in the brief —
  Department Manager cannot `decide()` an extension request (explicit
  `ExtensionRequestPolicy::decide()` excludes them, verified by
  `test_department_manager_cannot_decide_an_extension`); cross-department
  and cross-program access consistently resolves to 404/403 in existing
  Phase 2 tests, not a data leak.
- **Mass assignment**: every Phase 1/2 model uses an explicit `$fillable`
  allowlist (not `$guarded = []`); no Phase 2 controller passes
  `$request->all()` into a `create()`/`update()` call — every write uses
  `$request->validate()`'s narrowed array or the domain service's explicit
  named parameters.
- **Notification isolation**: `NotificationController` scopes every query
  through `$request->user()->notifications()`, so `findOrFail($id)` on
  another user's notification 404s rather than leaking or mutating it.
  Verified by `test_scenario13_reading_or_deleting_one_recipients_notification_does_not_affect_the_others`.
- **Program/department isolation (IDOR)**: `EnsureProgramAccess` middleware
  resolves the program by code and checks access before the request reaches
  any controller, returning 404 (not 403) for both a nonexistent program and
  an unauthorized one, deliberately avoiding program-code enumeration. Every
  Phase 2 controller additionally re-filters nested resources by both the
  route-resolved program and the record's own `compliance_program_id`.
- **Template injection**: `EmailTemplateRenderer` substitutes `{{var}}`
  tokens via `preg_replace_callback` against a fixed variable map — no
  `eval`, no Blade compilation of admin-editable content. Verified by
  `test_template_variables_render_without_executing_arbitrary_content`
  (a `<script>` payload in a variable value is stripped, not executed).
- **Formula injection on export**: `ImportErrorReportExport` prefixes any
  cell value starting with `=`, `+`, `-`, `@` with a leading apostrophe.
- **Stored/DOM XSS**: the frontend uses exactly one `v-html` binding
  (`AppLayout.vue`'s nav icon), and it renders a hardcoded emoji literal, not
  any user- or server-supplied string — grepped for every `v-html` usage in
  `frontend/src/` and confirmed there is no second instance.

## Not independently verified in this environment

- SQL injection: no raw/unparameterized query concatenation with user input
  was found anywhere in the Phase 1/2/3 codebase (all queries go through
  Eloquent/query builder parameter binding), but no dedicated SQLi fuzz test
  was run.
- SSRF: not applicable — this codebase makes no outbound HTTP requests to
  user-supplied URLs.
- Real AD bind behavior (see `qiyas-known-issues.md`).
- Rate-limit bypass via distributed IPs (the fix limits per IP+username, not
  a global CAPTCHA/behavioral layer — a distributed brute force spreading
  requests across many source IPs would not be caught by this alone; that is
  a reasonable production trade-off for an internal government platform
  behind normal network controls, not a gap unique to this review).

## Summary

7 confirmed findings (2 High, 3 Medium, 2 Low/supply-chain), all fixed and
verified either by a new automated test or direct, unambiguous code review
where a test wasn't meaningful to write. No Critical finding was identified.
No finding was left open — see the table above for the "before" state of
each; none remain unresolved. This does **not** by itself constitute a
full penetration test or a certified security assessment — see
`docs/qiyas-production-readiness.md` for how this factors into the release
decision.
