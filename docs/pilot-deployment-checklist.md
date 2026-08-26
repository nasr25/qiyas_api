# Pilot Deployment Checklist

**Author:** Nasser

Full evidence and rationale for every line item is in
`docs/testing/production-readiness.md`. This is the actionable
checklist; that document is the analysis behind it.

## Done, verified this phase

- [x] Prohibited-reference cleanup complete in both repositories'
      current tracked files (Git history untouched)
- [x] Author metadata (Nasser) applied to platform-owned files
- [x] No prohibited AI dependency in either dependency manifest
- [x] No public CDN dependency in the frontend production build
      (automated CI check)
- [x] Offline Playwright test passes with zero blocked requests
- [x] Versioned, validated/sanitized Super Admin branding management
- [x] Encrypted-at-rest SMTP configuration; password never exposed by
      the API, logs, or audit entries
- [x] Email template administration UI (edit/preview/test-send)
- [x] Security headers (CSP, COOP/CORP, HSTS, no-store default)
- [x] Deterministic CI quality gate (prohibited-reference scan +
      dependency audits + test suite)
- [x] Backup and restore drilled for real, including encrypted-secret
      round-trip verification
- [x] k6 smoke load test executed with real, recorded results
- [x] Release manifest generator built and tested
- [x] 171/171 backend tests, 29/29 new Playwright tests, all passing
- [x] `composer audit`/`npm audit` — 0 advisories

## Must be done before pilot go-live (not closeable in this session's environment)

- [ ] Fix AD account-status validation, **or** restrict the pilot to
      local accounts only (`docs/security/active-directory.md`)
- [ ] Execute the IIS deployment procedure once for real against the
      actual target server (`docs/deployment/iis-production.md`)
- [ ] Run a real UAT round with actual business stakeholders and
      record Pass/Fail (`docs/testing/uat-plan.md`,
      `docs/uat/qiyas-uat-en.md`/`-ar.md`,
      `docs/testing/uat-scenarios.md`)
- [ ] Rehearse the rollback procedure end-to-end at least once
      (`docs/deployment/rollback.md`)
- [ ] Set up the Windows Task Scheduler entry and the NSSM queue-
      worker service for real
      (`docs/operations/queue-and-scheduler.md`)
- [ ] Configure a real backup schedule (the scripts exist and were
      tested; no cron/Task Scheduler entry runs them automatically)
- [ ] Cybersecurity approval (independent of this engineering-level
      review)
- [ ] Infrastructure approval
- [ ] Backup/recovery approval (based on the real drill's evidence,
      at production scale if the pilot's expected data volume differs
      materially from the drill's small dataset)
- [ ] Operational support approval
- [ ] Change-management approval
- [ ] Production/pilot deployment approval

## Should be done before a full production rollout (not blocking a controlled pilot)

- [ ] Structured event-logging layer + a real SIEM integration
      (`docs/operations/monitoring.md`, `docs/operations/siem-
      integration.md`)
- [ ] Email template version history/restore
- [ ] Malware scanning on uploads
- [ ] Load testing beyond smoke scale, against approved production-
      representative infrastructure, with explicit stakeholder
      approval for any tier above what was already run
      (`docs/testing/load-testing.md`)
- [ ] Per-program UAT scenario sets for Sumoud/ECC/NDMO

## Classification

**Conditionally ready for pilot deployment.** See
`docs/testing/production-readiness.md` for full evidence and the
final Arabic report for the formal classification with severity/
owner/target-date tracking per open item.
