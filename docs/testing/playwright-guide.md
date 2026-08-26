# Playwright Guide

**Author:** Nasser

This consolidates `docs/playwright-e2e-guide.md` (the original Phase 4
guide, still the authoritative reference for directory layout, the
safety preflight, and the isolated-environment startup sequence) with
the four suites added in Phase 8.

## Isolated environment (unchanged from Phase 4 — see the original guide for full detail)

```bash
# One-time setup
mysql -u root -e "CREATE DATABASE qiyas_e2e_db CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
cd backend
DB_DATABASE=qiyas_e2e_db php artisan migrate --force
DB_DATABASE=qiyas_e2e_db php artisan db:seed --force

# Backend
DB_DATABASE=qiyas_e2e_db QUEUE_CONNECTION=sync MAIL_MAILER=log \
  LOGIN_RATE_LIMIT_PER_MINUTE=1000 php artisan serve --port=8001

# Frontend
cd frontend
VITE_DEV_PORT=5175 VITE_PROXY_TARGET=http://localhost:8001 npx vite --mode e2e --port 5175

# Run
E2E_BASE_URL=http://localhost:5175 E2E_API_URL=http://localhost:8001 \
  E2E_DB_NAME_HINT=qiyas_e2e_db npx playwright test
```

Every test file imports `E2E_CONFIG` from `tests/e2e/helpers/env.ts`
(never `process.env` directly) — it refuses to run against anything
that looks like production before any test executes.

## Phase 8 suites (new)

| File | Coverage | Tests |
|---|---|---|
| `tests/e2e/admin/branding.spec.ts` | Upload → preview → activate → restore, invalid-file/unsafe-SVG/XXE rejection, safe-SVG acceptance, live header-logo propagation, audit trail, AR/RTL + dark-mode render, authorization (auditor/employee/programManager all blocked) | 11 |
| `tests/e2e/admin/smtp-settings.spec.ts` | Password never populated from the API, blank-password-preserves-existing, test-connection success/failure/unreachable-host against a real fake SMTP server, unencrypted-rejected-without-internal-relay-flag, audit trail without secret exposure, authorization | 9 |
| `tests/e2e/admin/email-templates.spec.ts` | List/edit/save/reload, enable/disable persistence, per-locale preview, unsupported-variable rejection (API 422), script-tag-in-body never executes, authorization | 8 |
| `tests/e2e/offline/offline.spec.ts` | Blocks every non-localhost request; exercises login, program selection, dashboard, requirements, reports, notifications, and the full Super Admin settings surface (branding/SMTP/email templates); fails on any blocked request | 1 |

**All 29 tests pass** — verified individually, in file-groups, and
together with the pre-existing suites (`qiyas/smoke.spec.ts`,
`permissions/isolation.spec.ts`) run in parallel, confirming no
interference or regression.

### Fake SMTP server (`tests/e2e/helpers/fake-smtp-server.ts`)

A minimal, deterministic SMTP protocol server double (Node's built-in
`net` module only — no external dependency), speaking plaintext SMTP
(EHLO/AUTH LOGIN/MAIL FROM/RCPT TO/DATA) with a configurable valid
username/password pair. Used instead of mocking the frontend's HTTP
calls, so the SMTP suite exercises the real backend → real Symfony
Mailer transport → real socket connection path end to end.

### In-memory binary fixtures (`tests/e2e/data/files.ts`)

A genuinely valid, decodable PNG (hand-built PNG chunks + zlib
deflate), an unsafe SVG (embedded `<script>` + `onload=`), an XXE SVG,
a safe SVG, and a non-image file — all generated as in-memory
`Buffer`s, matching the existing pattern (evidence PDFs are similarly
generated in-memory, never checked in as binary fixtures).

### New stable `data-testid` hooks added this phase

`nav-logo-image`, `theme-toggle` (`AppLayout.vue`);
`settings-tab-{branding,smtp,email-templates,...}`,
`branding-{asset,upload,preview,activate,restore,history,error}-*`,
`smtp-*`, `email-template-*` (`SettingsView.vue`).

## Test-design lessons from this phase (real bugs found and fixed in the test code, not the app)

- **Locale-dependent text assertions are fragile.** An initial
  assertion checked for the English word "configured"; the app
  defaults to Arabic, so the literal string never appeared. Fixed by
  asserting the absence of the Arabic negative marker "غير" instead of
  a positive locale-specific string.
- **A save action must be awaited before reloading.** Clicking "Save"
  triggers an async API call; reloading immediately afterward (without
  `page.waitForResponse()`) raced the request and read stale state.
  Fixed by explicitly awaiting the `PUT` response before reloading in
  every save→reload test.
- **A shared, non-reset E2E database makes absolute-count assertions
  fragile across repeated manual runs.** A "supersedes" test that
  counted "exactly 1 superseded badge" broke after the same spec was
  run multiple times against the same persistent E2E database (history
  accumulated across runs). Fixed by reading exact before/after state
  via the API for specific version ids, rather than counting UI badges
  — correct regardless of how much prior history exists.
- **Over-asserting on a non-secret value.** An initial SMTP test
  asserted the *username* must never appear in a connection-failure
  message. The username is not a secret (the Super Admin just typed
  it) — only the password is. Relaxed to check only for the password's
  absence, matching what the backend's `sanitizeError()` actually
  guarantees.

## Cross-browser scope (unchanged policy)

Firefox and WebKit run `smoke.spec.ts` only, per the original guide's
rationale; the four new Phase 8 suites currently run on Chromium only.
Extending them to Firefox/WebKit was not done this phase — a
reasonable next step, not a blocker for pilot readiness given the
smoke suite already covers baseline cross-browser rendering.
