# Production Deployment

The single procedure for deploying this platform to a production
environment. Consolidates the prerequisites, the deployment order, and the
verification that has to pass before a deployment is called done.

> **Status.** The steps here are derived from this repository's actual
> configuration and were exercised against a production-mode instance
> (`APP_ENV=production`, `APP_DEBUG=false`, config/route/view caches built)
> on Linux during the production-readiness gate. **They have not been run
> end-to-end against a real Windows/IIS host** — see
> [`iis-production.md`](iis-production.md), which carries the same caveat.
> Treat the IIS-specific steps as reviewed but unverified.

**Never put real credentials in this file, in `.env.example`, or in any
committed file.**

---

## 1. Prerequisites

### Runtime

| Component | Requirement | Notes |
|---|---|---|
| PHP | **8.3+** (`composer.json` requires `^8.3`) | Verified on 8.5.4 |
| MySQL | **8.0+** | Recursive CTEs are required by the hierarchy engine |
| Web server | IIS + URL Rewrite + FastCGI, or nginx/Apache | Document root is `backend/public` |
| Node.js | **build time only** | Never installed on the production host |

### Required PHP extensions

`pdo_mysql` · `mbstring` · `openssl` · `tokenizer` · `xml` · `ctype` ·
`json` · `bcmath` · `fileinfo` · `zip` · `ldap` (only if Active Directory
authentication is used) · `gd` or `intl` (PDF export).

`zip` is not optional: XLSX reading and the macro-detection check both
inspect the workbook's ZIP container directly.

### Writable directories

Both must be writable by the web-server account, and **neither may be
served by the web server**:

```
storage/            (logs, framework caches, evidence files under storage/app/private)
bootstrap/cache/    (config, route and view caches)
```

Evidence files are written to `storage/app/private/evidence/...` with
generated UUID filenames. Only `public/storage` (branding assets) is
web-exposed, via symlink.

---

## 2. Environment variables

Copy `.env.example` and fill it in on the target host. It ships with
**placeholders only** — no key, no secret.

### Mandatory

```
APP_ENV=production
APP_DEBUG=false
APP_URL=https://<api-host>
APP_KEY=                 # php artisan key:generate
JWT_SECRET=              # php artisan jwt:secret

DB_CONNECTION=mysql
DB_HOST= DB_PORT= DB_DATABASE= DB_USERNAME= DB_PASSWORD=

FRONTEND_URL=https://<spa-host>    # the exact CORS origin, no trailing slash
```

`APP_DEBUG=true` in production is forbidden. Besides exposing stack traces,
it re-enables the developer quick-login gate
(`AuthController::quickLoginEnabled()` is `local || debug`).

### Security tuning (optional; defaults shown)

```
LOGIN_RATE_LIMIT_PER_MINUTE=10     # per username+IP
REPORTS_RATE_LIMIT_PER_MINUTE=60   # per user: reports, exports, template generation
UPLOADS_RATE_LIMIT_PER_MINUTE=30   # per user: evidence and XLSX uploads
```

Read through `config/security.php`, not `env()` at the call site, so they
survive `config:cache`.

### First Super Admin

```
SUPERADMIN_INITIAL_PASSWORD=       # blank -> a random one is generated and printed once
SUPERADMIN_EMAIL=
```

Either way the account is created with a **forced password change**, which
`JwtMiddleware` enforces on every API request — not only in the SPA.

### Must stay false

```
ALLOW_TEST_SEEDERS_IN_PRODUCTION=false
```

Demo and test seeders refuse to run when `APP_ENV=production`. This variable
is the deliberate, awkward escape hatch for a sanctioned test environment.
**It must never be true on a real deployment.**

### Mail and LDAP

Set `MAIL_*` only if not managing SMTP through the admin UI, which stores
the password **encrypted at rest** (`smtp_settings.password_encrypted`) and
is the preferred path. `LDAP_*` only when Active Directory is in use.

---

## 3. Backend deployment

```bash
# 1. Dependencies — production only, no dev packages, optimised autoloader
composer install --no-dev --optimize-autoloader --no-interaction

# 2. Keys (first deployment only — rotating these invalidates all sessions)
php artisan key:generate --force
php artisan jwt:secret --force

# 3. Schema
php artisan migrate --force

# 4. Baseline data — production-safe seeders only. NEVER `db:seed` without
#    --class in production: the default DatabaseSeeder chain includes demo
#    accounts, and while those seeders now refuse to run, the run aborts
#    part-way rather than completing.
php artisan db:seed --force --class=RolesAndPermissionsSeeder
php artisan db:seed --force --class=DefaultSettingsSeeder
php artisan db:seed --force --class=EmailTemplatesSeeder
php artisan db:seed --force --class=SuperAdminSeeder   # prints the initial password once

# 5. Caches — build these on the target host, after .env is final
php artisan config:cache
php artisan route:cache
php artisan view:cache

# 6. Branding asset symlink
php artisan storage:link
```

**Cache the routes on the target host.** The dev-only `quick-login` and
`dev-users` routes are registered conditionally on the environment, so a
route cache built under `local` and shipped to production would carry them.

---

## 4. Frontend deployment

Built on a build machine; the production host needs no Node.js.

```bash
cd qiyas_frontend
npm ci
VITE_API_URL=https://<api-host>/api/v1 npm run build
# ship dist/ to the SPA site root
```

The bundle is fully self-contained: all 90 font files are local
(`@fontsource/*`), and there are **no CDN, Google Fonts, analytics or
external API references**. Verify after any dependency change:

```bash
grep -rhoE "https?://[a-zA-Z0-9._/-]+" dist/ | sed -E 's#(https?://[^/]+).*#\1#' | sort -u
```

Only `http://www.w3.org` (SVG namespaces), `https://vuejs.org` (an error
link) and your own configured API host should appear.

---

## 5. Queue worker and scheduler

Both are **required**. Without them, notifications are never delivered and
SLA breaches and overdue requirements are never detected — nothing in the
UI triggers that work.

### Queue worker

`QUEUE_CONNECTION=database`. Run as a supervised, auto-restarting service:

```bash
php artisan queue:work database --tries=3 --backoff=60 --max-time=3600
```

- **Windows**: a Task Scheduler task at startup, or NSSM as a service.
- **Linux**: a systemd unit with `Restart=always`.

Failed jobs land in `failed_jobs`. Inspect and recover with:

```bash
php artisan queue:failed
php artisan queue:retry <id>        # or: --all
```

Notifications are deduplicated by an idempotency key, so a retried or
double-run job does not send a second email for the same event.

### Scheduler

One entry point, every minute:

```bash
php artisan schedule:run
```

It drives exactly one recurring command — `compliance:process-sla`, every
30 minutes, `withoutOverlapping()` — which detects SLA warnings, SLA
breaches **and** overdue requirements in a single pass and writes a
heartbeat that the readiness check reads.

> Two commands (`qiyas:mark-overdue`, `qiyas:send-reminders`) were scheduled
> here until the legacy authoring path was retired, which deleted them. The
> scheduler kept invoking names that no longer resolved and failed twice a
> day. They are gone; `compliance:process-sla` covers their behaviour.

---

## 6. Health verification

| Endpoint | Auth | Purpose |
|---|---|---|
| `GET /up` | none | **Liveness.** `{"status":"ok"}`. Registered with no middleware group, so it stays `200` even when the database is down — that distinction is the point. |
| `GET /api/v1/admin/health` | Super Admin | **Readiness.** Database, cache, queue (pending / failed-24h), storage, scheduler heartbeat. `503` when degraded. |

Do not expose the readiness endpoint publicly; it reports queue depth and
scheduler state.

---

## 7. Post-deployment smoke test

```bash
cd qiyas_frontend
E2E_BASE_URL=https://<spa-host> \
E2E_API_URL=https://<api-host> \
SMOKE_USERNAME=<account> SMOKE_PASSWORD=<password> \
npx playwright test tests/e2e/production-smoke --project=chromium
```

Read-only by design: login, program selector, hierarchy, assignments,
dashboard, reports, XLSX template generation, logout. **It creates,
modifies and deletes nothing.** See
[`../testing/production-smoke.md`](../testing/production-smoke.md).

---

## 8. Rollback

See [`rollback.md`](rollback.md) and
[`../dynamic-hierarchy-rollback.md`](../dynamic-hierarchy-rollback.md) —
the latter records an actually-executed rollback with verified preservation
counts.

```bash
php artisan down                       # maintenance mode
mysqldump --routines --triggers <db> > backup.sql
php artisan migrate:rollback --step=<n>
php artisan config:cache && php artisan route:cache
php artisan up
```

**Take the backup before rolling back, always.** Rolling back a database
that already holds post-cutover assignments leaves `requirement_id`
nullable — the schema reverts and the application runs, but the original
`NOT NULL` invariant is not restored. A true revert of such a database is a
restore from backup.

---

## 9. Backup

The application keeps no undocumented local state. To restore a deployment
you need:

| What | Where | Notes |
|---|---|---|
| Database | MySQL schema | `mysqldump --routines --triggers` |
| Evidence files | `storage/app/private/` | The bytes behind every `evidence_files` row |
| Branding assets | `storage/app/public/branding/` | Logos and favicons |
| Environment file | `.env` on the host | **Holds secrets — back it up as a secret** |

Everything else — caches, compiled views, logs, sessions — is regenerable.
Database rows and stored files must be backed up **together**: a database
restored to a different point in time than the evidence store leaves
`evidence_files` rows whose bytes are missing (handled as a `404`, but the
evidence is gone).

Infrastructure-level backup scheduling, retention and offsite copies are
assumed to be externally managed and are out of scope here.
