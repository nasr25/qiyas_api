# Post-Deployment Smoke Test

A short, **read-only** suite that answers one question: did this deployment
come up correctly?

```bash
cd qiyas_frontend
SMOKE_BASE_URL=https://<spa-host> \
SMOKE_API_URL=https://<api-host> \
SMOKE_USERNAME=<account> \
SMOKE_PASSWORD=<password> \
SMOKE_PROGRAM=QIYAS \
npx playwright test --config=playwright.production-smoke.config.ts
```

## Why it has its own config

The main `playwright.config.ts` runs a global setup that imports
`tests/e2e/helpers/env.ts`, whose preflight **refuses to run against a
production URL**. That refusal is correct — the main suite creates,
approves and rejects real records — so the smoke suite gets its own config
rather than a flag that would weaken the main guard.

The main projects also carry `testIgnore: /production-smoke/`, so a normal
regression run never picks this suite up, and its tests are not counted in
the regression totals.

## The read-only contract

This is what makes running it against production acceptable:

- every request is a **read**; nothing is created, modified or deleted
- no structure is drafted or activated, no cycle touched
- no evidence uploaded, no workflow advanced, no notification or email sent
- the XLSX check downloads a **generated** template, which persists nothing

Do not add a step that writes. If a future check needs to write, it belongs
in the main suite against the isolated E2E environment.

## What it covers

| Check | Asserts |
|---|---|
| Liveness | `GET /up` is `200` and exactly `{"status":"ok"}` — no HTML, no CDN |
| Dev login not exposed | `quick-login` is `404` (route absent) or `403`; `dev-users` is `404` or an empty list. A `200` carrying accounts fails the deployment |
| Unauthenticated refusal | `GET /auth/me` is `401` with no stack trace, vendor path or SQLSTATE |
| Login + program selector | Sign-in succeeds, the program list renders, the program opens |
| Core screens | Dashboard, hierarchy, assignments and reports all load |
| XLSX generation | Template returns a real OOXML container (`PK` magic bytes), not an error page |
| Sign-out | Returns to `/login` and clears the stored token |

## Reading a failure

| Failure | Likely cause |
|---|---|
| Liveness fails | Web server or PHP-FPM is not serving; check the site binding |
| Liveness passes, everything else `500` | Application is up, **database is not** — this split is exactly why `/up` runs with no middleware |
| Dev-login check fails | `APP_ENV` is not `production`, or `APP_DEBUG=true`, or the route cache was built in another environment |
| Screens load but are empty | Deployment is fine; the database has no content in this account's scope |
| XLSX returns non-`PK` | An error page is being returned — check `storage/logs` and the readiness endpoint |

## Verification status

**Executed: 7 / 7 passed (2026-08-26).**

The run targeted a genuine production-mode stack assembled for the
production-readiness gate, not the development environment:

| | |
|---|---|
| `APP_ENV` | `production` |
| `APP_DEBUG` | `false` |
| Caches | `config:cache` + `route:cache` + `view:cache` all built |
| Frontend | `npm run build` output, served as static files |
| CORS | `FRONTEND_URL` set to the exact SPA origin |
| Database | isolated `qiyas_prodcheck_db` |

That run is also what proves the dev-login check does its job: the same
suite **fails** against a non-production environment, where `quick-login`
is registered and `dev-users` returns accounts. It is a deployment
assertion, not a formality.

It has **not** been run against a real production deployment, because none
exists yet.
