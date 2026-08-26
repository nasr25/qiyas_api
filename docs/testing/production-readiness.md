# Production Readiness

**Author:** Nasser

This consolidates evidence from every Phase 8 document into one
assessment. It supersedes the narrower Phase-3
`docs/qiyas-production-readiness.md` (which only covered the
single-program, pre-branding/SMTP/offline-hardening state). See the
final Arabic readiness report for the formal classification; this
document is the English evidence backing it.

## Automated test evidence

| Suite | Result |
|---|---|
| Backend PHPUnit | **171/171 passing** |
| Frontend Playwright (Chromium) — Phase 8 suites | **29/29 passing** (branding 11, SMTP 9, email templates 8, offline 1) |
| Frontend Playwright — pre-existing suites, spot-checked for regression | 5 passing, 1 pre-existing legitimate skip, 0 failed |
| `composer audit` | 0 advisories |
| `npm audit` | 0 vulnerabilities |
| Prohibited-reference CI scan | Passes in both repositories |
| k6 smoke load test | 10 VUs/30s, 0% failure, p95=113.85ms |
| Backup/restore drill | Executed for real; all row counts and functional checks matched |

## What is genuinely solid

- Program/department/role isolation across four programs, re-verified
  at every program-count milestone (`docs/three-program-isolation.md`,
  `docs/four-program-isolation.md`).
- Branding and SMTP: versioned/encrypted, audited, authorization-
  tested, offline-verified.
- Security headers, the prohibited-reference CI gate, and dependency
  audits are clean.
- Backup and restore actually work, verified end-to-end including
  encrypted-secret round-tripping.
- Zero AI functionality, zero public CDN dependency, verified by
  automated checks now wired into CI.

## Confirmed gaps (not blocking a controlled pilot unless marked otherwise)

| Gap | Severity | Blocks pilot? | Document |
|---|---|---|---|
| AD account-status validation not implemented (disabled/expired AD account can still authenticate) | High | **Yes, if AD is used for real users in the pilot** — not a blocker if the pilot uses only local accounts | `docs/security/active-directory.md` |
| No structured event-logging layer for SIEM correlation; SIEM integration not implemented | Medium | No | `docs/operations/monitoring.md`, `docs/operations/siem-integration.md` |
| Email templates have no version history/restore | Medium | No | `docs/administration/email-templates.md` |
| IIS deployment procedure never executed against real IIS | High | **Yes** — must be rehearsed against a real target before go-live | `docs/deployment/iis-production.md` |
| Queue-worker SMTP-config refresh is manual (`queue:restart`), not automatic on save | Low | No | `docs/security/smtp-security.md` |
| Load testing only at smoke scale (10 VUs); no claim of 50/100/300/500/1000-concurrent-user capacity | Medium | Depends on pilot's expected concurrency — see `docs/testing/load-testing.md` | Same |
| Full end-to-end rollback never rehearsed as a single procedure (its individual pieces were) | Medium | No, but should be rehearsed before production | `docs/deployment/rollback.md` |
| SIEM/structured logging, disaster-recovery scenarios are documented but not live-drilled | Medium | No | `docs/disaster-recovery.md` |
| Per-program (Sumoud/ECC/NDMO) UAT scenario sets not separately authored | Low | No — Qiyas scenarios exist as the template | `docs/testing/uat-plan.md` |
| No malware scanning on uploads | Medium | No | `docs/security/file-security.md` |
| `Setting` group generic settings are not versioned (only branding/SMTP are) | Low | No | `docs/administration/system-settings.md` |

No **Critical** severity issue is open.

## Explicit classification

**Conditionally ready for pilot deployment** — not "ready for
production," and never claimed as such based on automated tests alone.

Production readiness (as distinct from pilot readiness) additionally
requires, and none of the following has occurred in this session:
business UAT sign-off (see `docs/testing/uat-plan.md` — a real human
must run the scenarios), cybersecurity approval (this document's
review is engineering-level, not an independent security assessment),
infrastructure approval (no real IIS/Windows Server was available),
backup/recovery approval (the drill was real but small-scale — see
`docs/backup/restore-guide.md`'s scale caveat), operational support
approval, change-management approval, and production deployment
approval.

## Conditions for pilot deployment

1. The AD account-status gap must be fixed, **or** the pilot must use
   local accounts only.
2. The IIS deployment procedure must be executed once, for real,
   against the actual target server before go-live — not assumed
   correct from documentation review.
3. A UAT round with real business stakeholders must run and record
   Pass/Fail against `docs/uat/qiyas-uat-en.md`/`-ar.md` and
   `docs/testing/uat-scenarios.md`.
4. The rollback procedure should be rehearsed at least once end-to-end
   before the pilot goes live, not just verified in its component
   pieces.

None of these conditions were possible to fully close within this
session's environment (no Windows/IIS host, no real business
stakeholders to run UAT, no separate staging environment to rehearse a
full rollback against) — they are recorded as the explicit path to
"Ready for pilot deployment" rather than left implicit.
