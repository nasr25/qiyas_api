# Claude Development Instructions

## Safety rules

- Never commit or push directly to `main`.
- Never merge a pull request.
- Never disable or bypass required checks.
- Never commit secrets, credentials, production data, or local environment files.
- Do not change authentication, authorization, database schema, public APIs, or deployment configuration without documenting the impact.

## Required workflow for every task

1. Fetch and update the latest `main` branch.
2. Create a dedicated branch using one of:
   - `feature/<short-name>`
   - `fix/<short-name>`
   - `refactor/<short-name>`
   - `docs/<short-name>`
   - `chore/<short-name>`
3. Analyze existing architecture and conventions before editing.
4. Implement the smallest safe change that satisfies the request.
5. Add or update automated tests for behavioral changes.
6. Run formatting, tests, security checks, and the frontend build.
7. Review the complete diff for unrelated or accidental changes.
8. Commit using a clear Conventional Commit message.
9. Push the branch and open a pull request targeting `main`.
10. Complete every section of the pull request template.
11. Wait for Codex and GitHub Actions results.
12. Address findings on the same branch, push again, and repeat until all required checks pass.

## Local verification commands

```bash
composer validate --strict
composer install --no-interaction --prefer-dist
vendor/bin/pint --test
php artisan test
npm install --ignore-scripts
npm run build
composer audit --locked --no-interaction
npm audit --omit=dev --audit-level=high
```

## Pull request requirements

Every pull request must explain:

- Purpose and scope.
- Files and components affected.
- Tests executed and their results.
- Security impact.
- Database and migration impact.
- API and backward-compatibility impact.
- Deployment prerequisites.
- Rollback procedure.

## Engineering expectations

- Enforce authorization server-side for every protected action.
- Validate all untrusted input.
- Use transactions for multi-step writes that must remain consistent.
- Avoid N+1 queries and unbounded result sets.
- Add indexes only when justified by query patterns.
- Do not expose stack traces or sensitive details to clients.
- Preserve backward compatibility unless a breaking change is explicitly approved.
- Prefer maintainable, testable code over clever shortcuts.
