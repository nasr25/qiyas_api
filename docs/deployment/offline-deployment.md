# Offline Deployment

**Author:** Nasser

## Requirement

The production **runtime** must not require npm/Node build execution,
Composer network access, or reach any public package registry. The
deployment artifact delivered to a production/pilot server must be
fully self-contained: built frontend assets, vendored PHP
dependencies, application source, migrations, config templates, and
the operational scripts (queue/scheduler/health-check/deployment/
rollback) — all built and verified **before** the artifact leaves a
build environment that does have network access, never on the
production host itself.

## Build-time vs. runtime network access

| Phase | Network access | What happens |
|---|---|---|
| Build (CI or a build workstation) | Yes, to Packagist/npm registry | `composer install`, `npm ci && npm run build` |
| Artifact packaging | No | Vendor directory, `dist/`, source, migrations, and scripts are archived together |
| Production deployment | **No** | The pre-built artifact is unpacked and run as-is |

This means the production deployment sequence in
`docs/deployment/iis-production.md` — which shows `composer install
--no-dev` running as a deployment step — is written for a build
environment with network access; for a genuinely offline production
host, that step must instead be "unpack the pre-built `vendor/`
directory from the artifact," not a live `composer install`. This
distinction should be made explicit in whichever deployment pipeline
variant is actually used — see `docs/deployment/release-process.md`.

## What was verified this phase

- **Zero runtime CDN dependency in the frontend build.** The
  production build (`npx vite build`) was inspected and confirmed to
  contain no reference to `fonts.googleapis.com`,
  `fonts.gstatic.com`, `cdn.jsdelivr.net`, `unpkg.com`,
  `cdnjs.cloudflare.com`, `ajax.googleapis.com`, `fonts.bunny.net`, or
  `cdn.tailwindcss.com`. This check was automated in the frontend CI
  workflow until the GitHub Actions pipeline was retired; it is now a
  **manual release step** — see `docs/offline-assets.md` for the command.
- **Two real CDN dependencies found and removed this phase**: an
  active `@import url('https://fonts.googleapis.com/...')` in
  `frontend/src/assets/main.css` (replaced with 10 local `@import`
  statements from the newly self-hosted `@fontsource/ibm-plex-sans`
  and `@fontsource/ibm-plex-sans-arabic` packages — OFL-1.1 licensed,
  npm-installable, bundled into the build by Vite); and a latent,
  never-triggered Bunny Fonts CDN reference in an entirely unused
  backend-root Vite scaffold (deleted).
- **`enshrined/svg-sanitize`'s `removeRemoteReferences(true)`**
  additionally strips any external `url()` reference from an uploaded
  SVG logo, so a branding upload cannot itself introduce a runtime CDN
  dependency.
- **The offline Playwright test** (`tests/e2e/offline/offline.spec.ts`)
  blocks every non-localhost network request at the browser level and
  exercises login, program selection, dashboards, and the full Super
  Admin settings surface (branding/SMTP/email templates) — it passed
  with zero blocked requests. See `docs/testing/playwright-guide.md`.

## What was not verified

- A real, fully offline production **build** (i.e. actually running
  `composer install`/`npm ci` with the network disabled to confirm
  they fail predictably, and a documented pre-built-artifact-only
  deployment executed against a network-isolated target host) was not
  performed — this environment has one network-connected sandbox, not
  a separate isolated deployment target to rehearse against.
- Font/icon rendering across every browser/OS combination in a fully
  offline environment was not cross-browser-verified beyond the
  Chromium offline Playwright run.

## No CDN fallback pattern

No code in either repository falls back to a public CDN URL if a
local asset fails to load (checked via the same CDN-hostname scan —
a fallback `<link>`/`@import` would still contain the hostname string
and would have been caught). This is a deliberate absence, not an
untested gap — a fallback-to-CDN pattern is exactly what "full offline
operation" prohibits.

## Optional integrations

The only external integration point in this platform is SMTP (see
`docs/administration/smtp-settings.md`), which is disabled by default
(`is_enabled=false`) and fails gracefully — the platform's login,
workflows, reports, and evidence handling never block on SMTP being
reachable; only outbound email delivery is affected, and delivery
failures are logged (`email_logs`) rather than surfaced as a
user-facing error. Active Directory (`docs/security/active-directory.md`)
is similarly optional — the platform falls back to local authentication
when `LDAP_HOST` is unset.
