# Security Policy

**Author:** Nasser

## Reporting a vulnerability

Report suspected security issues to the platform's designated Super
Admin / technical point of contact through an approved internal
channel. Do not open a public issue for a suspected vulnerability, and
do not attempt to demonstrate a finding against a production
environment or real user data.

## Supported versions

The platform is deployed as a single current release per environment
(DEV / TEST / production). Security fixes are applied to the active
`feature/multi-program-compliance-platform` branch and released
through the standard deployment pipeline — see
[`docs/deployment/release-process.md`](docs/deployment/release-process.md).
There is no separate long-term-support branch.

## Security posture summary

Full detail lives under `docs/security/`; this is an index.

| Area | Document |
|---|---|
| Overall hardening review, OWASP-category findings | [`docs/security/security-hardening.md`](docs/security/security-hardening.md) |
| Active Directory / local authentication | [`docs/security/active-directory.md`](docs/security/active-directory.md) |
| File upload/download, evidence, branding assets | [`docs/security/file-security.md`](docs/security/file-security.md) |
| Secrets storage and handling (SMTP password, `APP_KEY`) | [`docs/security/secrets-management.md`](docs/security/secrets-management.md) |
| SMTP-specific secret handling and test-connection safety | [`docs/security/smtp-security.md`](docs/security/smtp-security.md) |

## Deterministic tooling only

Every security check in this repository — the CI prohibited-reference
scan (`scripts/scan-prohibited-references.sh`), `composer audit`,
`npm audit`, and the Playwright suites — is deterministic. No AI-based
security scanner is used anywhere in the pipeline.

## Scope note

The reviews referenced above are engineering-level security reviews
performed as part of platform development. They are not a substitute
for a formal, independent security assessment or penetration test.
Where a control has not been exercised against real production-class
infrastructure (a real Active Directory domain controller, a real
SMTP relay, a real IIS/Windows Server host), that is stated explicitly
in the relevant document rather than assumed.
