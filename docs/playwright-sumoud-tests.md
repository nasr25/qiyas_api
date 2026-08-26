# Playwright — Sumoud and Cross-Program Tests (Phase 5)

Builds on the Phase 4 Playwright harness (`playwright.config.ts`,
`tests/e2e/helpers/`) — no changes to the isolated-environment safety
mechanism were needed; it was already program-agnostic.

## New structure

```
tests/e2e/sumoud/full-lifecycle.spec.ts       (6 tests, serial)
tests/e2e/cross-program/role-resolution.spec.ts (2 tests)
tests/e2e/cross-program/isolation.spec.ts       (7 tests)
```

## Sumoud Full Requirement Lifecycle

Mirrors `tests/e2e/qiyas/full-lifecycle.spec.ts` exactly — same reusable
views (`CycleDetailView`, `RequirementAssignmentsView`,
`MyRequirementDetailView`, `ReviewDetailView`), same UI-driven core
journey, API-assisted setup/verification only. Uses the seeded active
Sumoud test cycle rather than creating a new one (explicitly allowed by
the brief). Covers: login → program visibility → hierarchy creation →
assignment → Employee submission (immutability after submit) → Department
Manager approval (department-scoped) → Auditor approval → Program Manager
final approval → dashboard/history/report verification → confirms the
created requirement never appears in Qiyas's own requirement list.

## Cross-Program Role Resolution

Both named scenarios (User A, User B) from the brief, verified through
real UI navigation AND a direct backend 403 check on a manually-attempted
Sumoud Program-Manager-only endpoint.

## Cross-Program Isolation

Deliberate mixed-context attempts: Sumoud-only session against Qiyas
routes (and vice versa) at the HTTP level (404, not 403 — matches
`EnsureProgramAccess`'s enumeration-prevention design), program-selection
list correctly per-user, a Sumoud cycle ID rejected through the Qiyas
program route, and a real Sumoud-generated XLSX template rejected by the
Qiyas import validator (`WRONG_PROGRAM`).

## Real defects found via these tests (see
`docs/programs/sumoud/known-limitations.md` for full detail)

Every one of the 7 engine/frontend defects listed there was discovered
specifically because these tests exercised a genuinely different program
end to end through real UI actions — not assumed from code review alone.

## Regression discipline

The complete pre-existing Qiyas suite (`qiyas/full-lifecycle.spec.ts`,
`rejection-journeys.spec.ts`, `extension-journey.spec.ts`,
`permissions/isolation.spec.ts`, `smoke.spec.ts`) was re-run together with
the new Sumoud/cross-program specs multiple times, on a freshly rebuilt
isolated E2E database, both single-worker and default-parallel — final
confirmed result: **30 test cases, 29 passed, 1 legitimately skipped
(data-dependent, same skip documented in Phase 4), 0 failed** on Chromium;
Firefox and WebKit smoke pass.
