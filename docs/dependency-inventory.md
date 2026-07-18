# Dependency Inventory

**Author:** Nasser

Full, exact dependency graphs are `backend/composer.lock` and
`frontend/package-lock.json` — the source of truth. This document is a
human-readable summary of the direct dependencies, their purpose, and
their license, generated from `composer licenses` / `npx
license-checker` at the time of writing. Re-run those commands before
a release to get current figures — see
`docs/deployment/release-process.md`.

## Backend (composer.json — direct `require`)

| Package | Version | License | Purpose |
|---|---|---|---|
| `laravel/framework` | ^13.8 | MIT | Application framework |
| `laravel/sanctum` | ^4.3 | MIT | (Available; JWT via tymon/jwt-auth is the auth mechanism actually used) |
| `laravel/tinker` | ^3.0 | MIT | REPL for operations/debugging |
| `tymon/jwt-auth` | ^2.3 | MIT | JWT authentication |
| `spatie/laravel-permission` | ^8.0 | MIT | Platform-wide role/permission model |
| `maatwebsite/excel` | ^3.1 | MIT | XLSX import/export (standards catalog, evidence lists) |
| `barryvdh/laravel-dompdf` | ^3.1 | MIT | PDF report generation |
| `enshrined/svg-sanitize` | ^0.22 | GPL-2.0-or-later | SVG sanitization for branding uploads — pinned above CVE-2025-55166 (fixed in 0.22.0) |

Backend total (direct + transitive, `--no-dev`): **98 packages**, all
MIT/BSD-3-Clause/LGPL-2.1-or-later/GPL-2.0-or-later per `composer
licenses --no-dev`. `composer audit`: **0 advisories** as of the
Phase 8 review.

Symfony Mailer (`symfony/mailer`) is bundled transitively with Laravel
and is the transport used by `SmtpSettingsService` — no separate mail
package was added.

## Frontend (package.json — direct `dependencies`)

| Package | Version | License | Purpose |
|---|---|---|---|
| `vue` | ^3.5.32 | MIT | UI framework |
| `vue-router` | ^5.0.4 | MIT | Routing |
| `pinia` | ^3.0.4 | MIT | State management |
| `vue-i18n` | ^9.14.5 | MIT | AR/EN localization |
| `axios` | ^1.16.1 | MIT | HTTP client |
| `tailwindcss` | ^3.4.19 | MIT | Utility CSS |
| `@tailwindcss/forms` | ^0.5.11 | MIT | Form-control styling reset |
| `@headlessui/vue` | ^1.7.23 | MIT | Unstyled accessible UI primitives |
| `@heroicons/vue` | ^2.2.0 | MIT | Icon set |
| `chart.js` / `vue-chartjs` | ^4.5.1 / ^5.3.3 | MIT | Dashboard charts |
| `autoprefixer` | ^10.5.0 | MIT | CSS vendor prefixing (build-time) |
| `@fontsource/ibm-plex-sans` | ^5.2.8 | OFL-1.1 | Self-hosted Latin font (Phase 8 — replaces a Google Fonts CDN import) |
| `@fontsource/ibm-plex-sans-arabic` | ^5.2.9 | OFL-1.1 | Self-hosted Arabic font (Phase 8) |

Frontend dev-only: `@playwright/test` (^1.61.1, Apache-2.0), `vite`
(^8.0.8, MIT), `@vitejs/plugin-vue` (^6.0.6, MIT) — never shipped in
the production build.

Frontend total (production deps, per `npx license-checker
--production`): **196 packages** — 179 MIT, 7 ISC, 4 Apache-2.0, 2
OFL-1.1 (the two `@fontsource/*` font packages), 2 MPL-2.0, 2
BSD-3-Clause, 1 CC-BY-4.0, 1 BSD-2-Clause, and 1 "UNLICENSED" entry
that is the frontend's **own** root package (`frontend@0.0.0` has no
`license` field declared in `package.json`) — not a third-party
dependency. Declaring a license type is a business/legal decision
outside this review's authority and was intentionally not set
unilaterally, matching the same "no unapproved legal copyright claim"
constraint applied to `composer.json`. `npm audit`: **0 vulnerabilities**
as of the Phase 8 review.

## Prohibited-dependency scan (Phase 8)

Both manifests/lockfiles were scanned for AI SDK/service package names
(OpenAI, Anthropic, Claude, ChatGPT, Copilot, Gemini, LangChain,
Hugging Face, Cohere, TensorFlow/PyTorch bindings, etc.). **Zero
matches.** The one lockfile hit for the literal string "claude" is
`laravel/agent-detector`'s own keyword metadata (a real, independently
published package whose stated purpose is *detecting* AI-agent
environments) — reviewed and confirmed not an AI dependency itself;
see `docs/current-repository-cleanup.md`.

## Offline-readiness dependency notes

- `@fontsource/*` packages ship font files that Vite bundles into the
  production build's `dist/assets/` — no runtime fetch to
  `fonts.googleapis.com`/`fonts.gstatic.com`. See
  `docs/offline-assets.md`.
- No CDN-loaded JS/CSS package (no `cdn.jsdelivr.net`, `unpkg.com`,
  `cdnjs.cloudflare.com` reference) exists in either manifest or the
  production build output — verified by
  `scripts/scan-prohibited-references.sh`'s companion CDN-hostname
  check in the frontend CI workflow.
