# Qiyas — Windows Server / IIS Deployment

**Not executed against a real IIS instance in this environment** — no
Windows Server was available in this sandboxed macOS session. This is
written from Laravel's documented IIS requirements, this repository's
actual `composer.json`/`.env.example`, and the platform's own file layout,
not from a completed deployment. Treat it as a first deployment's
checklist to verify against, not a guarantee. See
`docs/windows-scheduler-and-queues.md` for the scheduler/queue-worker
portion specifically.

## Prerequisites

- Windows Server (2019+ recommended) with IIS installed.
- **URL Rewrite Module** for IIS (required — Laravel's front controller
  routing depends on it, same as `.htaccess` on Apache).
- PHP **8.3 or 8.4** (matches `composer.json`'s `"php": "^8.3"`) via
  IIS's FastCGI handler (PHP Manager for IIS simplifies this).
- Required PHP extensions: `pdo_mysql`, `mbstring`, `openssl`, `tokenizer`,
  `xml`, `ctype`, `json`, `bcmath`, `fileinfo` (used directly by the
  evidence-upload MIME check — see `docs/qiyas-security-review.md`), `zip`
  (used directly by the XLSX macro-detection check —
  `QiyasImportValidator::isMacroEnabledWorkbook()`), `gd` or `intl` if PDF
  export features are used (`barryvdh/laravel-dompdf` is a dependency).
- MySQL 8 (can be on the same host or a separate DB server reachable from
  IIS).
- Node.js only needed at **build time** (`npm run build` produces static
  `dist/` files) — not required on the production server itself.

## Site layout

Laravel's public entry point is `backend/public/index.php` — the IIS site's
**physical path must point at `backend/public`**, not the repository root.
Everything outside `public/` (`app/`, `config/`, `storage/`, `vendor/`,
`.env`, `database/`, `tests/`, `docs/`) must **not** be reachable via any
URL:

```
C:\inetpub\wwwroot\qiyas-api\          ← repository root (NOT the IIS site root)
  public\                              ← IIS site physical path points HERE
  app\ config\ storage\ vendor\ .env   ← outside the webroot, never served
```

If IIS is (mis)configured to point at the repository root instead of
`public/`, `.env` and the entire `storage/` directory (including private
evidence files) would become directly downloadable — verify this explicitly
during setup, e.g. by confirming `https://your-host/.env` and
`https://your-host/storage/app/private/...` both return 404, not the file
contents.

## `web.config` (URL Rewrite)

Place at `public/web.config` (Laravel ships a default one; confirm it
exists and matches this shape):

```xml
<?xml version="1.0" encoding="UTF-8"?>
<configuration>
  <system.webServer>
    <rewrite>
      <rules>
        <rule name="Laravel Front Controller" stopProcessing="true">
          <match url="^(.*)$" />
          <conditions logicalGrouping="MatchAll">
            <add input="{REQUEST_FILENAME}" matchType="IsFile" negate="true" />
            <add input="{REQUEST_FILENAME}" matchType="IsDirectory" negate="true" />
          </conditions>
          <action type="Rewrite" url="index.php" />
        </rule>
      </rules>
    </rewrite>
    <security>
      <requestFiltering>
        <!-- Match PHP's upload_max_filesize / post_max_size below, and the
             platform's own evidence-upload size limit (Setting group
             'evidence_upload', default 20MB/file, 100MB/submission total) -->
        <requestLimits maxAllowedContentLength="209715200" /> <!-- 200MB -->
      </requestFiltering>
    </security>
    <httpProtocol>
      <customHeaders>
        <!-- Belt-and-suspenders alongside the app's own SecurityHeaders
             middleware (see docs/qiyas-security-review.md) — IIS-level
             headers apply even to any static asset IIS serves directly. -->
        <add name="X-Content-Type-Options" value="nosniff" />
      </customHeaders>
    </httpProtocol>
  </system.webServer>
</configuration>
```

## PHP upload/request size configuration

`php.ini` must allow at least the platform's own configured evidence-upload
limits (Super Admin-configurable, default 20 MB/file, 100 MB/submission
total — see `docs/qiyas-role-permissions.md`) and the XLSX import limit
(10 MB, hardcoded in `QiyasImportController::preview()`):

```ini
upload_max_filesize = 25M
post_max_size = 110M
max_execution_time = 120
memory_limit = 256M
```

## Frontend (Vue SPA)

The frontend is a **separate static build**, not served by Laravel. Build
it (`npm run build` in `frontend/`) and host `frontend/dist/` as its own IIS
site (or a separate static-hosting path), with its **own** `web.config`
providing SPA fallback routing (every unmatched path serves `index.html` so
Vue Router's client-side routes work on a hard refresh):

```xml
<rule name="SPA Fallback" stopProcessing="true">
  <match url=".*" />
  <conditions logicalGrouping="MatchAll">
    <add input="{REQUEST_FILENAME}" matchType="IsFile" negate="true" />
  </conditions>
  <action type="Rewrite" url="/index.html" />
</rule>
```

Set `FRONTEND_URL` in the backend's `.env` to this site's actual production
URL (required for the CORS allowlist — see `config/cors.php`, which was
tightened in Phase 3 to drop the dev-only localhost pattern in production;
see `docs/qiyas-security-review.md` finding #6).

## Application pool / environment

- Run the application pool under a dedicated service account (not
  `ApplicationPoolIdentity` with default permissions if the account also
  needs to reach a network file share for `storage/`) — the same account
  should be used for the Task Scheduler and NSSM queue-worker service (see
  `docs/qiyas-operational-runbook.md`) so file permissions on `storage/`
  are consistent across all three processes.
- `.env` must never be committed to source control or placed under
  `public/`. Protect it with NTFS permissions restricted to the
  application-pool/service account.
- Set `APP_ENV=production` and `APP_DEBUG=false` — this is also what gates
  Quick Login off (`AuthController::quickLoginEnabled()`) and drops the
  CORS localhost pattern (Phase 3 fix, `config/cors.php`).
- Set `APP_URL` to the actual production API URL (used in generated links,
  e.g. any `action_url` email template variable).
- Set `APP_TIMEZONE` (and `SlaSetting.timezone`, which defaults to
  `Asia/Riyadh` per-program) consistently — SLA business-day calculations
  are timezone-aware per `SlaService::calculateDueAt()`.

## HTTPS / TLS

- Terminate TLS at IIS with a valid certificate (or at a load balancer in
  front of it) — the `SecurityHeaders` middleware only sends
  `Strict-Transport-Security` when the request is already detected as
  secure (`$request->secure()`), so HSTS has no effect until HTTPS is
  actually enforced at the server/network level.
- Add an IIS-level HTTP→HTTPS redirect rule (not shown above — standard
  IIS Rewrite redirect rule) so a client hitting `http://` is upgraded
  before any request body (including a login password) is ever sent in
  cleartext.

## Deployment / restart sequence

1. Pull the new code to a release directory (or use a blue/green release
   folder swap — out of scope to prescribe here, depends on your existing
   ops tooling).
2. `composer install --no-dev --optimize-autoloader`
3. `npm run build` (frontend, separately).
4. `php artisan migrate --force` — review `docs/qiyas-data-integrity.md`'s
   integrity command before and after.
5. `php artisan config:cache && php artisan route:cache`
6. Restart the application pool (`Restart-WebAppPool`) so any cached
   config/route files and OPcache are picked up.
7. Restart the queue worker service (`nssm restart QiyasQueueWorker`) —
   PHP workers cache the booted application in memory and will not see new
   code otherwise (see `docs/windows-scheduler-and-queues.md`).
8. Run `php artisan compliance:verify-migration` and `php artisan
   compliance:verify-qiyas` — both read-only, safe to run any time,
   confirm no integrity issue was introduced.
9. Hit the protected readiness endpoint (`GET /api/v1/admin/health`) to
   confirm database/cache/queue/storage/scheduler are all reporting `ok`.

## What is publicly reachable — verify explicitly after first deployment

| Path | Expected |
|---|---|
| `/.env` | 404 |
| `/storage/...` (any path) | 404 (private disk is outside `public/`) |
| `/vendor/...` | 404 |
| `/database/...` | 404 |
| `/composer.json` | 404 |
| `/up` | 200 (intentional public liveness probe) |
| `/api/v1/admin/health` | 401/403 without a Super Admin JWT |
