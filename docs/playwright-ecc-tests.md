# Playwright — ECC and Multi-Program Tests (Phase 6)

Builds on the Phase 4/5 Playwright harness — no changes to the isolated-
environment safety mechanism were needed.

## New structure

```
tests/e2e/ecc/full-lifecycle.spec.ts              (6 tests, serial)
tests/e2e/cross-program/ecc-isolation.spec.ts     (5 tests)
tests/e2e/cross-program/multi-role.spec.ts        (1 test, tri-program)
```

`tests/e2e/ecc-import/` and `tests/e2e/ecc-content-version/` (requested
directories for import and content-version scenarios) were **not**
populated this phase — the underlying import pipeline is not yet
hierarchy-aware (see `docs/programs/ecc/xlsx-import.md`), so a real,
non-fabricated import/content-version E2E journey cannot yet be written
against working functionality. Building the directories with empty or
placeholder specs was judged worse than omitting them and documenting the
gap honestly.

## ECC Full Control Lifecycle

Mirrors the Qiyas/Sumoud full-lifecycle specs through the exact same
reusable views. The genuinely new part: steps 7-14 drill through the
`HierarchyExplorerView.vue` (Domain → Subdomain → create Control) instead
of a flat "create standard" form, proving the four-level
`ComplianceNode` hierarchy is real and navigable through the UI, not only
through PHPUnit. Uses the seeded active ECC test cycle and its seeded
Domain/Subdomain (explicitly allowed by the brief) rather than creating a
cycle from scratch.

## Cross-Program Isolation (`ecc-isolation.spec.ts`)

ECC-only user denied Qiyas/Sumoud routes and vice versa (404s, matching
`EnsureProgramAccess`'s enumeration-prevention design); program-selection
list correctly per-user and for Super Admin (all three programs); an ECC
hierarchy node creation attempt with a foreign/nonexistent parent
rejected server-side; ECC report export denied to an unauthorized user.

## Multi-Role User Test (`multi-role.spec.ts`)

The brief's explicit tri-program scenario: one user with Qiyas Program
Manager + Sumoud Auditor + ECC Employee. Verifies all three programs are
visible, the correct role-gated actions are available/unavailable in each
after switching, a direct ECC Program-Manager-only API call is denied
(403) despite the user having write access in Qiyas, and returning to
Qiyas restores the correct (not cached-stale) role context.

## Regression discipline

The complete pre-existing suite (Qiyas full lifecycle/rejection/extension,
Sumoud full lifecycle, permissions isolation, cross-program role-
resolution/isolation from Phase 5, smoke) was re-run together with the
new ECC/multi-role specs on a freshly rebuilt isolated E2E database —
final confirmed result: **42 test cases, 41 passed, 1 legitimately
skipped (data-dependent, same skip documented since Phase 4), 0 failed**
on Chromium; Firefox and WebKit smoke pass.
