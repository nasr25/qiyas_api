# Offline Assets

**Author:** Nasser

Every runtime asset the platform needs is locally hosted/bundled — no
public CDN for CSS, JS, fonts, icons, images, charts, date libraries,
editors, or localization data. This document lists each asset,
verified this phase.

| Asset | Package | Version | License | Build inclusion | Local output path |
|---|---|---|---|---|---|
| Latin font (IBM Plex Sans) | `@fontsource/ibm-plex-sans` | ^5.2.8 | OFL-1.1 | `@import` in `src/assets/main.css`, bundled by Vite | `dist/assets/*.woff2` (content-hashed) |
| Arabic font (IBM Plex Sans Arabic) | `@fontsource/ibm-plex-sans-arabic` | ^5.2.9 | OFL-1.1 | Same | Same |
| Icons | `@heroicons/vue` | ^2.2.0 | MIT | Imported as Vue components, tree-shaken into the JS bundle | `dist/assets/*.js` |
| Charts | `chart.js` + `vue-chartjs` | ^4.5.1 / ^5.3.3 | MIT | Bundled by Vite | `dist/assets/charts-*.js` |
| CSS framework | `tailwindcss` + `@tailwindcss/forms` | ^3.4.19 / ^0.5.11 | MIT | Compiled at build time, no runtime CDN | `dist/assets/index-*.css` |
| Unstyled UI primitives | `@headlessui/vue` | ^1.7.23 | MIT | Bundled | `dist/assets/*.js` |
| Date/localization | `vue-i18n` (AR/EN JSON locale files, checked into the repo) | ^9.14.5 | MIT | Bundled | `dist/assets/*.js` |
| HTTP client | `axios` | ^1.16.1 | MIT | Bundled | `dist/assets/vendor-*.js` |

No rich-text/WYSIWYG editor package exists in this platform (email
templates and branding text fields are plain-text inputs — see
`docs/administration/email-templates.md`), so there is no editor-CDN
dependency to eliminate.

## What was found and fixed this phase

1. **Active CDN font import.** `frontend/src/assets/main.css`
   contained a live `@import url('https://fonts.googleapis.com/...')`
   for IBM Plex Sans/IBM Plex Sans Arabic. Fixed by installing the two
   `@fontsource/*` packages above and replacing it with 10 local
   per-weight `@import` statements. Verified via `npx vite build` that
   the built `dist/` output contains locally-hashed `.woff`/`.woff2`
   files and zero remaining `fonts.googleapis`/`fonts.gstatic`
   references in any built CSS.
2. **A latent, never-triggered CDN font dependency in unused
   scaffolding.** The backend repository root carried an entirely
   unused Vite/Node toolchain referencing Bunny Fonts (a public CDN)
   via `laravel-vite-plugin/fonts`'s `bunny()` helper, and
   `composer.json`'s `setup` script would have triggered `npm run
   build` on it if ever run. Deleted entirely — see
   `docs/current-repository-cleanup.md`.

## Verification method

A `git grep` sweep for known CDN hostnames plus a broader, unbounded
`https?://[a-zA-Z0-9.-]+` sweep of `src/` and `index.html` (excluding
`localhost`) was used to distinguish a real network-request URL from a
benign string occurrence — e.g. the SVG XML namespace URI
`http://www.w3.org/2000/svg`, which browsers never fetch, versus an
actual `<link>`/`<script src>`/`@import url()` usage. The frontend CI
workflow now runs an automated version of this check
(`.github/workflows/ci.yml`, "Verify no known public CDN hostname
survives in the build output") against the production build output on
every push.

## Optional integrations (never CDN-dependent)

SMTP (`docs/administration/smtp-settings.md`) and Active Directory
(`docs/security/active-directory.md`) are the platform's only external
integrations. Both are disabled/unconfigured by default, and neither
is a CDN-style browser-loaded asset — they are server-to-server
connections the browser never talks to directly, so they do not affect
offline-first browser operation. Both fail gracefully: login,
workflows, reports, and evidence handling never block on either being
reachable.

## Offline browser test

`tests/e2e/offline/offline.spec.ts` blocks every outbound request that
isn't to `localhost`/`127.0.0.1` at the Playwright network layer and
exercises login, program selection, dashboards, reports, notifications,
and the full Super Admin settings surface (branding/SMTP/email
templates), failing the test if any request is blocked. **Passed with
zero blocked requests** — see `docs/testing/playwright-guide.md`.

## Not verified

A genuinely air-gapped production deployment (no network route to the
public internet at all, at the OS/network level, not just browser-side
request blocking) was not rehearsed — this environment has one
network-connected sandbox. The Playwright offline test blocks requests
at the browser layer, which is a strong signal but not identical to a
true network-level air gap.
