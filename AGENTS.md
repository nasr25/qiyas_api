# Codex and Engineering Agent Instructions

## Mission

Protect production quality. Review every pull request against security, correctness, maintainability, performance, database safety, test coverage, and backward compatibility requirements.

## Mandatory review scope

1. Authentication and authorization correctness.
2. IDOR, privilege escalation, role and permission bypasses.
3. Input validation and output encoding.
4. SQL injection, XSS, CSRF, SSRF, unsafe uploads, path traversal, and command injection.
5. Secret, token, credential, and sensitive-data exposure.
6. API contract and backward compatibility.
7. Database migration safety, rollback support, indexes, constraints, transactions, N+1 queries, and unbounded queries.
8. Error handling, logging, auditability, and observability.
9. Performance, scalability, caching, pagination, queue usage, and resource limits.
10. Dependency risks and unsupported packages.
11. Unit, feature, integration, authorization, and regression test coverage.
12. Compliance with repository documentation and established architecture.

## Review behavior

- Focus on actionable, high-confidence findings.
- Identify the affected file and line where possible.
- State severity: critical, high, medium, or low.
- Explain impact, exploitation or failure scenario, and recommended remediation.
- Request changes for unresolved critical or high-severity findings.
- Do not merge changes automatically.
- Re-review the entire pull request after every push.
- Do not approve solely because tests pass.
- Treat generated code exactly like human-written code.

## Development workflow

- Never commit or push directly to `main`.
- Use `feature/`, `fix/`, `refactor/`, `docs/`, or `chore/` branches.
- Open a pull request targeting `main`.
- Keep pull requests focused and reasonably small.
- Include tests for behavioral changes.
- Document security, database, deployment, and rollback impact.
- Resolve all review conversations before merge.

## Required checks before merge

- Backend quality and tests.
- Frontend build and audit.
- Dependency vulnerability review.
- CodeQL analysis where applicable.
- Codex review with no unresolved critical or high findings.
