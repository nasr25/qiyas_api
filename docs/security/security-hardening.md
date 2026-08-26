# Security Hardening Review

**Author:** Nasser

This is a consolidated, evidence-based security review across the
platform's history (Phase 3's dedicated review plus Phase 8's
additional hardening pass). It is an engineering-level review, not a
formal penetration test or independent certification. All tooling used
is deterministic — no AI security scanner was used anywhere.

## Phase 3 review findings (all fixed, `docs/qiyas-security-review.md`)

| Severity | Finding | Fix |
|---|---|---|
| High | No rate limiting on `/auth/login`/`/auth/quick-login` | `throttle:login` — 10 req/min keyed by `lower(username)+ip` |
| High | Disguised-executable upload (MIME blocklist missing PE/ELF/Mach-O variants; `.exe` renamed `.pdf` passed) | Expanded blocklist (PE/ELF/Mach-O, `text/html`, `image/svg+xml`) |
| Medium | `.xlsm` renamed `.xlsx` bypassed macro detection (filename-only check) | `HierarchyImportValidator` inspects the ZIP for `xl/vbaProject.bin` on both preview and confirm |
| Medium | `LdapService::authenticate()` had no own empty-password guard | Explicit `trim($password) === ''` guard added |
| Medium | No security headers | `SecurityHeaders` middleware added (see below) |
| Low | CORS allowed `localhost:*` regardless of environment | Empty allow-list in production |
| Low | `composer audit`/`npm audit` had medium/high advisories | Patched — 0 advisories after |

Reviewed with no finding: workflow state-machine integrity,
authorization/policy coverage, mass assignment, notification
cross-tenant isolation, program/department isolation (IDOR), email
template injection, XLSX export formula injection, XSS (`v-html` used
only once, in `AppLayout.vue`, with a hardcoded, non-user-controlled
emoji — no user input reaches it). **Not verified**: exhaustive SQLi
fuzzing, SSRF (not applicable — no server-side outbound-URL-fetching
feature exists), a real Active Directory bind, or distributed
rate-limit bypass at scale.

## Phase 8 additional review

### Security headers (current, `app/Http/Middleware/SecurityHeaders.php`)

Applied globally to every response:

| Header | Value |
|---|---|
| `Content-Security-Policy` | `default-src 'none'; frame-ancestors 'none'` |
| `X-Content-Type-Options` | `nosniff` |
| `X-Frame-Options` | `DENY` |
| `Referrer-Policy` | `strict-origin-when-cross-origin` |
| `Permissions-Policy` | `camera=(), microphone=(), geolocation=(), payment=()` |
| `Cross-Origin-Opener-Policy` | `same-origin` |
| `Cross-Origin-Resource-Policy` | `same-origin` |
| `Cache-Control` | `no-store, private` (unless the response explicitly opts into `public`) |
| `Strict-Transport-Security` | `max-age=31536000; includeSubDomains` (only when the request is HTTPS) |

This is a JSON API (no server-rendered HTML), so `default-src 'none'`
is safe and does not need script/style/font allowances — those apply
to the separately hosted SPA, whose own CSP is the frontend's IIS site
configuration responsibility (see `docs/deployment/iis-production.md`).
Verified live via `curl -I` against the dev server; all 171 backend
tests pass with the middleware active.

### Two new defects found and fixed this phase

1. **Latent plaintext SMTP password storage.** Before this phase,
   `AppServiceProvider::applyMailSettings()` read an SMTP password from
   the generic, completely unencrypted `Setting` key-value store.
   Confirmed via `php artisan tinker` that zero rows had ever actually
   held `group='smtp'` data in this environment (no retroactive
   remediation needed), but the mechanism itself was a latent
   plaintext-secret-storage risk. Replaced with the encrypted
   `SmtpSettingsService` — see `docs/security/smtp-security.md`.
2. **A dead offline-first violation in unused scaffolding.** The
   backend repository root carried an entirely unused Vite/Node
   scaffold (`vite.config.js`, `package.json`, `resources/js`,
   `resources/css`) referencing Bunny Fonts, a public CDN, via
   `laravel-vite-plugin/fonts`'s `bunny()` helper — and
   `composer.json`'s `setup` script would have actually triggered
   `npm run build` on it if ever run. Deleted entirely (confirmed
   unused — the real frontend is the separate Vue repository).

### New security surfaces reviewed and hardened

- **Branding upload** (`BrandingService`): extension allow-list, real
  `finfo`-derived MIME (never client Content-Type), file-signature/
  decode verification, max size/pixel-count limits, and — for SVG —
  defense-in-depth (pre-sanitizer XXE-entity regex reject, the
  reviewed `enshrined/svg-sanitize` library pinned above CVE-2025-55166,
  and a post-sanitizer belt-and-braces script/event-handler regex
  check). See `docs/security/file-security.md`.
- **SMTP secrets**: encrypted at rest, never returned by the API,
  never logged, redacted from audit entries. See
  `docs/security/smtp-security.md`.
- **Email template editing**: unknown `{{variable}}` names rejected
  (`EmailTemplateController::validateVariables()`), and the rendered
  body is delivered through Laravel's default Markdown notification
  mail template, which HTML-escapes `->line()` content — confirmed no
  custom, unescaped notification Blade view is published in this repo,
  so a script tag typed into a template body cannot execute as live
  HTML in the sent email.
- **Prohibited-reference/dependency CI gate**: see
  `docs/current-repository-cleanup.md`.

## OWASP-category summary

| Category | Status |
|---|---|
| Broken access control | Program/department/role isolation tested across 4 programs; Playwright authorization tests confirm 403 for every new Super-Admin-only surface (branding/SMTP/email templates) |
| Cryptographic failures | SMTP password encrypted via `Crypt::` (`APP_KEY`-derived); JWT signed; no plaintext secret storage remaining |
| Injection | Parameterized queries throughout (Eloquent); LDAP search values escaped; no raw SQL string concatenation found |
| Insecure design | Append-only audit/decision trails; versioned settings/branding with no destructive overwrite |
| Security misconfiguration | Headers hardened this phase; `.env`/`.git`/`vendor` blocked at the webserver — see `docs/deployment/iis-production.md` |
| Vulnerable/outdated components | `composer audit`/`npm audit` — 0 advisories as of this review |
| Auth failures | Rate-limited login, quick-login production-gated, JWT expiry |
| Data integrity failures | Migrations reviewed for FK/unique constraints; see `docs/qiyas-data-integrity.md` |
| Logging/monitoring failures | Audit log + structured logging — see `docs/operations/monitoring.md`; gap: no external SIEM shipping configured, documented in `docs/operations/siem-integration.md` |
| SSRF | Not applicable — no server-side outbound-URL-fetch feature exists |

## Explicitly not done

No AI-based security scanner was used at any point. No exhaustive
third-party penetration test was performed. A real Active Directory
bind, a real production SMTP relay, and a real IIS/Windows Server host
were not available in this environment — see
`docs/security/active-directory.md` and
`docs/testing/production-readiness.md` for what that means for the
pilot-readiness classification.
