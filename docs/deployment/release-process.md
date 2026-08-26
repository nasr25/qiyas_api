# Release Process

**Author:** Nasser

## Release checks

> **These are manual.** They ran as GitHub Actions stages on every push and
> pull request until that pipeline was retired. The commands are unchanged —
> what is gone is the guarantee that anyone ran them. Run them before
> tagging a release.

1. Prohibited-reference scan (`scripts/scan-prohibited-references.sh`)
   — exits non-zero on any unreviewed generation-tool reference in a
   tracked file. Scans the current working tree only, never git history.
2. Backend: `composer install`, `composer audit`, migrate against a
   fresh CI database, `php artisan test`.
3. Frontend: `npm ci`, `npm audit --audit-level=high`, `npm run
   build`, the CDN-hostname offline check.

This is the **current, actually-committed** CI pipeline. It is a
subset of a fuller 44-step pipeline (dependency-integrity verification
→ prohibited-reference scan → commit-message quality check →
prohibited-AI-library scan → backend/frontend tests → static analysis
→ lint → security scan → dependency-vulnerability scan → production
build → offline-asset verification → public-URL scan → the full
Playwright suite set, including all four program lifecycles,
cross-program isolation, multi-role, Super-Admin-Branding, SMTP,
Email-Template, and Offline tests → a k6 smoke test → deployment-
artifact creation with checksums and a release manifest → DEV
deployment with health checks → TEST deployment with health checks →
a UAT approval gate → a production approval gate → production
deployment → post-deployment migrations → queue-worker reload →
scheduler verification → health checks → functional smoke tests →
rollback-on-failure) — the remaining stages (Playwright suites beyond
what CI runs today, the UAT/production approval gates, and the
DEV/TEST/production deployment automation itself) are **documented
here as the target design, not yet wired into an executable pipeline**,
because they require environments (a DEV server, a TEST server,
approved production infrastructure) this session does not have access
to. What was actually executed is recorded in
`docs/testing/production-readiness.md`.

## Release manifest

`scripts/generate-release-manifest.sh <version> [artifact-path]`
produces a JSON manifest containing: release version, Git commit hash,
build timestamp, **Author: Nasser**, the backend dependency
(`composer.lock`) hash, the full database migration list, an artifact
checksum (SHA-256, when an artifact path is given), and placeholders
for configuration changes, required manual steps, queue-restart
requirement, scheduler changes, rollback compatibility, and known
issues — filled in manually per release, since those are judgment
calls a script cannot make safely. Run and verified in this phase
(see `docs/testing/production-readiness.md` for the real output).

## Immutable, versioned artifacts

Production deployment must use a pre-built, checksummed, versioned
artifact — never a live `composer install`/`npm run build` on the
production server, and never a build sourced from the public internet
on the production host itself. See
`docs/deployment/offline-deployment.md`.

## Before every deployment

Per `docs/backup/backup-guide.md` and `docs/deployment/rollback.md`:
take a database backup, take a configuration backup, record the
active branding version, record the effective SMTP configuration
status (never the secret itself), record the current release version,
and verify the rollback artifact is available.

## Commit and documentation conventions for this phase

Every commit made during this phase uses a professional, neutral
message, contains zero references to an automated development
assistant, and carries no related trailer — this explicitly supersedes
whatever default commit-trailer convention any tooling in this session
might otherwise apply. See `docs/current-repository-cleanup.md`.

## What is not yet built

An automated DEV/TEST deployment step, a UAT sign-off gate wired into
CI, and a production deployment/rollback automation script are not yet
implemented — they require target environments that do not exist in
this session. `docs/deployment/rollback.md` documents the rollback
**procedure**; it has not been executed as an automated pipeline step.
