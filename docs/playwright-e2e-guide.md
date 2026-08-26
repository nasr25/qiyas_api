# Playwright E2E Guide

Location: `frontend/tests/e2e/`. No Playwright or other E2E framework
existed before Phase 4 — installed fresh
(`@playwright/test`, browsers for Chromium/Firefox/WebKit).

## Directory layout

```
frontend/tests/e2e/
  helpers/       env.ts (safety preflight), auth.ts (login helpers),
                 api.ts (setup/verification-only API access),
                 global-setup.ts (backend health preflight)
  data/          fixtures.ts (unique-ID generators, seeded department names)
  qiyas/         full-lifecycle.spec.ts, rejection-journeys.spec.ts,
                 extension-journey.spec.ts, smoke.spec.ts
  permissions/   isolation.spec.ts
  auth/          (reserved — no dedicated auth-only spec file yet)
  fixtures/      (reserved — no binary file fixtures needed yet; evidence
                 files are generated in-memory as Buffers, see below)
  security/      (reserved — see known-issues for what's not yet covered)
  responsive/    (reserved — playwright.config.ts already defines tablet/
                 mobile projects pointed at this directory; no spec files
                 exist here yet, see known-issues)
```

## Required isolated environment — how it's actually run

**Every command below targets a dedicated `qiyas_e2e_db` MySQL database and
a backend/frontend pair on non-default ports — never the shared dev
database.**

```bash
# One-time setup
mysql -u root -e "CREATE DATABASE qiyas_e2e_db CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
cd backend
DB_DATABASE=qiyas_e2e_db php artisan migrate --force
DB_DATABASE=qiyas_e2e_db php artisan db:seed --force

# Backend — sync queue for deterministic notification timing, log mailer
# so no real SMTP is attempted (see docs/notification-engine.md for why
# this specifically mattered), a generous login rate limit (E2E drives
# Quick Login far more per minute than a human ever would — see
# docs/compliance-engine-known-issues.md)
DB_DATABASE=qiyas_e2e_db QUEUE_CONNECTION=sync MAIL_MAILER=log \
  LOGIN_RATE_LIMIT_PER_MINUTE=1000 php artisan serve --port=8001

# Frontend — --mode e2e loads frontend/.env.e2e, which points axios's
# baseURL directly at the isolated backend. This specific mechanism matters
# — see the note below.
cd frontend
VITE_DEV_PORT=5175 VITE_PROXY_TARGET=http://localhost:8001 npx vite --mode e2e

# Run
E2E_BASE_URL=http://localhost:5175 E2E_API_URL=http://localhost:8001 \
  E2E_DB_NAME_HINT=qiyas_e2e_db npx playwright test
```

### Why `--mode e2e` and `frontend/.env.e2e`, not just an env var

`src/services/api.js`'s axios client sets `baseURL` from
`import.meta.env.VITE_API_URL` — an **absolute** URL, resolved once at
dev-server start. Simply exporting `VITE_API_URL` in the shell before
`npx vite` does **not** override it: Vite's dotenv loading gives the
project's `.env` file precedence over `process.env` for this key, so the
app silently kept pointing at the regular dev backend (port 8000) even
with the shell variable set. This was discovered by tracing exactly where
several "successful" (HTTP 201) test creates were actually landing — see
`docs/compliance-engine-known-issues.md` for the full story and why it
matters as a cautionary note for anyone extending this setup. The fix:
`frontend/.env.e2e`, loaded via Vite's own `--mode e2e` file-precedence
mechanism, which reliably wins.

## Safety preflight

`tests/e2e/helpers/env.ts` runs at module-load time, before any test:
refuses to run if `APP_ENV`/`NODE_ENV` is `production`, if the base/API URL
contains a production-looking marker or isn't a recognized local host, or
if `E2E_DB_NAME_HINT` doesn't contain "e2e" or "test". `global-setup.ts`
additionally fails the whole run immediately if the backend's `/up` health
check isn't reachable, rather than letting every test time out
individually with a confusing connection error.

## Login strategy

`helpers/auth.ts` uses Quick Login (`getByTestId('quick-login-{username}')`)
for UI logins — fast and deterministic, reachable only because the E2E
backend runs with `APP_ENV=local`/`APP_DEBUG=true` (the same gate that
disables it in production, reverified by
`test_quick_login_is_disabled_outside_local_or_debug` in the backend
suite). `helpers/api.ts` uses the equivalent API endpoint directly for
test setup/verification calls that never touch the UI.

## Selectors

Every interactive element the E2E suite touches has a stable
`data-testid` attribute, added directly to the Vue components as part of
this phase (not derived from translated text or CSS classes) — see the
full list embedded in each `.vue` file touched; `getByTestId()` is used
exclusively, never text-content or CSS-class selectors, for anything the
suite clicks or fills.

## Evidence file fixtures

Generated in-memory per test as a `Buffer` (`%PDF-1.4 ...` content,
`application/pdf` MIME) rather than checked-in binary files — sufficient
to exercise the real upload/MIME-validation/storage path without adding
binary fixtures to the repository. No XLSX fixture files exist yet — see
`docs/compliance-engine-known-issues.md`.

## Reports

`playwright.config.ts` configures the built-in HTML reporter
(`playwright-report/`, not committed) plus the list reporter for CI/console
output, `trace: 'retain-on-failure'`, `screenshot: 'only-on-failure'`,
`video: 'retain-on-failure'` — every failure this phase produced a
screenshot, video, and trace, several of which were used directly to
diagnose the real defects listed in `docs/compliance-engine-known-issues.md`.
