# IIS Production Deployment

**Author:** Nasser

**This procedure has never been executed against a real IIS/Windows
Server host** — it is written from the Laravel/IIS/URL-Rewrite
documentation and this repository's own configuration, consolidating
an earlier Phase-3 document (`docs/qiyas-deployment-iis.md`, itself
also never executed against real IIS). Treat every step as reviewed-
but-unverified until it has actually been run once against a real
target server, which should happen before this platform is considered
production-ready (see `docs/testing/production-readiness.md`).

## Prerequisites

- Windows Server with IIS, URL Rewrite module, and PHP FastCGI
  (PHP 8.3 or 8.4 — matches `composer.json`'s `^8.3` constraint).
- Required PHP extensions: `pdo_mysql`, `mbstring`, `openssl`,
  `tokenizer`, `xml`, `ctype`, `json`, `bcmath`, `fileinfo`, `zip`,
  `gd` or `intl`.
- MySQL 8 (or a compatible MariaDB matching the schema — see
  `docs/dependency-inventory.md` for what this platform was actually
  developed/tested against).
- Node.js is required only at **build time** (frontend production
  build), never on the production server itself — see
  `docs/deployment/offline-deployment.md`.

## Backend site

- **Physical path**: `backend/public` (the Laravel front controller
  directory — never the repository root).
- **`web.config`**: a Laravel front-controller URL Rewrite rule,
  `<requestLimits maxAllowedContentLength="209715200">` (200 MB, above
  the application's own file-size validation ceiling so the webserver
  never silently truncates a legitimately-sized upload before the
  application gets to validate it), and `X-Content-Type-Options:
  nosniff` (the application's own `SecurityHeaders` middleware sets
  the full header set at the application layer — see
  `docs/security/security-hardening.md` — this is a webserver-layer
  belt-and-braces addition, not a replacement).
- **`php.ini`**: `upload_max_filesize=25M`, `post_max_size=110M`,
  `max_execution_time=120`, `memory_limit=256M`.
- **App pool identity**: a dedicated, least-privilege service account
  (not a built-in high-privilege identity) — the same account used for
  the Task Scheduler entry and the queue-worker service, per
  `docs/operations/queue-and-scheduler.md`.
- **Environment**: `APP_ENV=production`, `APP_DEBUG=false`,
  `APP_URL` set to the real HTTPS origin, `APP_TIMEZONE`/
  `SlaSetting.timezone` default `Asia/Riyadh` unless overridden.

## Frontend site

A **separate** static site serving `frontend/dist/` (the production
build output — see `docs/deployment/offline-deployment.md`), with its
own SPA-fallback `web.config` (rewrite every non-file request to
`index.html`) and correct static-asset MIME types (in particular:
`.woff`/`.woff2` for the self-hosted fonts — see
`docs/offline-assets.md`). Set `FRONTEND_URL` in the backend's `.env`
so CORS allows the real frontend origin.

## Blocking direct access

The webserver configuration must return **404** for direct requests
to: `/.env`, `/.git/...`, `/storage/...` (framework-internal paths —
evidence/branding downloads go through the application's own
authorized controller routes, never a direct filesystem path),
`/vendor/...`, `/database/...`, `/composer.json`, `/composer.lock`,
`/package.json`, `/package-lock.json`, `/tests/...`, private docs,
prohibited source maps, backups, logs, DB dumps, deployment/queue
scripts, and PowerShell files. Directory browsing must be disabled.
Detailed remote error pages must be disabled (`APP_DEBUG=false`
handles this at the application layer; IIS's own
`<httpErrors errorMode="Custom">` should additionally prevent a raw
IIS/ASP.NET-style error page from leaking a stack trace or path).

## Compression, caching, HTTPS

Enable IIS dynamic/static compression. Cache versioned static assets
(the frontend build's content-hashed filenames, e.g.
`index-Cfzglrj-.js`) aggressively (`Cache-Control: public,
max-age=31536000, immutable` is safe precisely because the filename
changes on every content change). Terminate TLS at IIS with a valid
certificate chain; enforce HTTPS redirection; the application's own
`SecurityHeaders` middleware adds `Strict-Transport-Security` when it
sees an HTTPS request.

## Deployment sequence

```
composer install --no-dev --optimize-autoloader
# (frontend build happens off-server — see offline-deployment.md)
php artisan migrate --force
php artisan config:cache && php artisan route:cache
Restart-WebAppPool <pool-name>
nssm restart QiyasQueueWorker         # or the configured service name
php artisan compliance:verify-migration
```

Then verify: `GET /up` → 200 (public liveness), `GET
/api/v1/admin/health` → 200 with a valid Super Admin JWT (restricted
readiness/diagnostics — see `docs/operations/health-checks.md`), and a
manual login as a real account.

## Maintenance mode

Laravel's built-in `php artisan down`/`php artisan up` is available
for a graceful maintenance window; no dedicated Settings-page
maintenance toggle exists (see
`docs/administration/system-settings.md`).

## Health-check endpoint reachability table

| Path | Expected (production) |
|---|---|
| `/.env` | 404 |
| `/storage/...` (framework paths) | 404 |
| `/vendor/...` | 404 |
| `/composer.json` | 404 |
| `/up` | 200, public |
| `/api/v1/admin/health` | 401/403 without a Super Admin JWT; 200 with one |

## Not yet verified in this environment

Real IIS request-filtering behavior, real PHP FastCGI timeout
tuning under load, and a real HTTPS/TLS certificate chain were not
exercised — this environment has no Windows/IIS host available. See
`docs/testing/production-readiness.md` for how this affects the pilot
readiness classification.
