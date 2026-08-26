# Qiyas — Production Readiness / Release Checklist

Check off each item with evidence (a command output, a test name, a
screenshot reference), not just a mental confirmation. Items marked
**(not verifiable in dev)** require a real staging/production-like
environment (Windows Server, real AD, real SMTP) that was not available
during the Phase 3 audit — see `docs/qiyas-known-issues.md`.

## Application

- [x] Debug disabled in the target environment — `APP_DEBUG=false` gates
      Quick Login off (`AuthController::quickLoginEnabled()`), verified by
      `test_quick_login_is_disabled_outside_local_or_debug`.
- [ ] **(not verifiable in dev)** `APP_ENV=production` actually set on the
      target server.
- [ ] **(not verifiable in dev)** HTTPS enabled and enforced (HTTP→HTTPS
      redirect at IIS) — see `docs/qiyas-deployment-iis.md`.
- [x] Secure response headers present — `SecurityHeaders` middleware,
      verified by `test_security_headers_are_present_on_api_responses`.
- [ ] **(not verifiable in dev)** `APP_URL` set to the real production
      domain.
- [x] Timezone handling reviewed — `SlaSetting.timezone` per-program
      (default `Asia/Riyadh`), business-day calculations timezone-aware
      (`SlaService::calculateDueAt()`).
- [x] Arabic and English verified — full bilingual coverage confirmed via
      live browser walkthrough in Phase 2 (see `docs/qiyas-workflow.md` §6)
      and re-confirmed structurally in Phase 3 (no new UI strings added
      outside i18n files).

## Database

- [ ] **(not verifiable in dev — no production data yet)** A real backup
      completed before first production migration — see
      `docs/qiyas-backup-and-recovery.md`.
- [x] Migrations reviewed for portability (SQLite-compatible, no raw
      MySQL-only SQL) — confirmed by the full test suite running against
      SQLite (97/97 passing).
- [x] Indexes verified against actual query patterns — see
      `docs/qiyas-performance-review.md`.
- [x] Integrity verification passes — `php artisan compliance:verify-migration`
      and `php artisan compliance:verify-qiyas` both pass cleanly against
      the current dev database.
- [x] Rollback plan documented — `docs/qiyas-backup-and-recovery.md`
      §"Rollback plan."

## Storage

- [ ] **(not verifiable in dev)** Evidence path confirmed unreachable from
      the public web root on the actual IIS deployment (`/storage/...`
      returns 404) — see `docs/qiyas-deployment-iis.md`'s explicit
      verification table.
- [ ] **(not verifiable in dev)** File-system permissions validated for
      the actual IIS application-pool/service account.
- [ ] **(not verifiable in dev)** Backup configured and scheduled on the
      real server.
- [x] Upload limits configured and enforced —
      `EvidenceUploadValidator` (configurable via Settings), verified by
      `test_unsafe_file_extension_is_rejected` and Phase 3's
      `test_disguised_executable_upload_is_rejected_by_real_mime_detection`.

## Authentication

- [ ] **(not verifiable in dev — no AD server available)** AD connection
      verified against the real domain controller.
- [x] Local Super Admin verified — seeded and login-tested throughout
      Phase 1-3 development and testing.
- [ ] **(not verifiable in dev)** Disabled-AD-account behavior tested
      against a real directory.
- [x] Local disabled-account behavior tested —
      `AuthService::attempt()` checks `is_active` and returns null;
      covered indirectly by existing auth tests.
- [x] Session/token expiration configured — `config('jwt.ttl')`, returned
      to the client as `expires_in`.
- [x] Login brute-force protection — Phase 3 fix, verified by
      `test_login_is_rate_limited_after_repeated_attempts`.

## Authorization

- [x] Program isolation passed — `EnsureProgramAccess` middleware +
      per-controller re-scoping, extensively tested across Phase 2 (e.g.
      `test_program_manager_cannot_assign_in_another_program`,
      `test_auditor_cannot_decide_extension_in_unauthorized_program`).
- [x] Department isolation passed — `test_department_manager_cannot_review_another_department`,
      `test_employee_cannot_access_another_departments_submission`,
      `test_assignment_visible_only_to_assigned_department`.
- [x] File authorization passed — evidence download gated by
      `EvidenceSubmissionPolicy::downloadFile()` → `view()`, scoped to
      program+department.
- [x] Role matrix passed — see `docs/qiyas-role-permissions.md`, every row
      backed by a named Policy method.

## Workflow

- [x] Full approval path passed — `test_program_manager_final_approval_completes_the_requirement`
      and Phase 3's `test_scenario1_full_approval_updates_metrics_notifies_correctly_and_leaves_a_complete_audit_trail`.
- [x] All rejection paths passed — Department Manager, Auditor, and
      Program Manager rejection each has a dedicated test confirming direct
      return to Employee, plus Phase 3's immutability/full-new-cycle tests
      (`test_scenario3_...`, `test_scenario4_...`).
- [x] Resubmission passed — always restarts at Department Manager,
      verified regardless of which stage rejected the prior version.
- [x] Extension approval and rejection passed — original due date
      preserved on approval, due date unchanged on rejection (Phase 3
      `test_scenario6_...`), Department Manager cannot decide
      (`test_department_manager_cannot_decide_an_extension`).
- [x] Concurrency conflict handling passed —
      `test_conflicting_double_decision_returns_409`.

## SLA

- [x] Scheduler command exists and is idempotent —
      `test_scheduled_command_detects_breach_and_does_not_duplicate_on_second_run`.
- [ ] **(not verifiable in dev)** Scheduler actually running on the real
      server (Windows Task Scheduler) — verify via
      `docs/qiyas-operational-runbook.md`'s scheduler-verification step
      after deployment.
- [ ] **(not verifiable in dev)** Queue worker actually running as a
      Windows Service on the real server.
- [x] Warnings verified — `test_sla_completes_when_stage_ends_and_next_stage_opens`.
- [x] Breaches verified, employee vs. reviewer delay correctly attributed —
      `test_reviewer_delay_is_not_attributed_to_employee`, Phase 3's
      `test_scenario7_employee_submission_delay_is_attributed_to_the_employee_not_a_reviewer`.
- [x] No duplicate alerts — notification idempotency keys + unique DB
      constraint, verified by `test_dispatching_the_same_event_twice_only_logs_once`
      and the scheduler-run-twice test above.

## Notifications

- [ ] **(not verifiable in dev — no real SMTP server configured)** SMTP
      verified against a real mail server.
- [x] Templates verified — 16 seeded bilingual templates
      (`EmailTemplatesSeeder`), safe variable rendering verified by
      `test_template_variables_render_without_executing_arbitrary_content`.
- [x] Arabic and English email content verified — every seeded template
      has both `subject_ar`/`subject_en` and `body_ar`/`body_en`.
- [x] Disabled-template behavior verified —
      `test_disabled_template_does_not_send_email_but_still_creates_in_app_notification`.
- [x] Notification isolation verified — Phase 3
      `test_scenario13_reading_or_deleting_one_recipients_notification_does_not_affect_the_others`.
- [ ] **(not verifiable in dev)** Failure retry verified against a real
      transient SMTP failure.

## Import

- [x] Template export passed — `test_official_template_downloads_successfully`.
- [x] Valid import passed — `test_confirmed_import_creates_standards_transactionally`.
- [x] Invalid import (wrong program, macro-enabled) rejected —
      `test_wrong_program_template_is_rejected`, Phase 3's
      `test_macro_enabled_workbook_renamed_to_xlsx_is_rejected_on_import_preview`.
- [x] Transaction rollback / no-partial-import passed —
      `test_import_preview_does_not_save_any_data` (preview never writes)
      plus `HierarchyImportService::confirm()`'s single-transaction design
      (code-reviewed, matches the "all or nothing" requirement).

## Operations

- [x] Logs available and locations documented —
      `docs/qiyas-operational-runbook.md`.
- [x] Health checks available — public liveness (`/up`) + protected
      readiness (`GET /api/v1/admin/health`), Phase 3 addition, verified by
      `test_readiness_health_check_reports_each_component_and_is_super_admin_only`.
- [x] Queue monitoring approach defined — via the readiness endpoint's
      `checks.queue` and `php artisan queue:failed`.
- [x] Failed-job recovery defined — `docs/qiyas-operational-runbook.md`
      §"Failed-job handling."
- [ ] **(not verifiable in dev)** Backup restore actually tested once.
- [ ] Support/escalation contacts documented — placeholders only, must be
      filled in per organization (`docs/qiyas-operational-runbook.md`
      §"Escalation").

## Dependencies

- [x] `composer audit` — 0 advisories (was 3 medium before Phase 3 fix).
- [x] `npm audit` — 0 vulnerabilities (was 1 high before Phase 3 fix).
- [x] `./vendor/bin/pint --test` — clean (55 pre-existing style issues
      auto-fixed in Phase 3, all Phase 2/3-authored files were already
      clean).

## Test suite

- [x] Backend: **97/97 passing** (`php artisan test`).
- [x] Frontend build: clean (`npm run build`).
- [ ] Frontend automated tests: **none exist** — see
      `docs/qiyas-known-issues.md`. This is the single item on this
      checklist most likely to block a strict "production ready"
      classification on its own.

## Overall

See `docs/qiyas-production-readiness.md` for the release-readiness
classification and full blocker list based on this checklist.
