# Playwright — NDMO and Multi-Program Tests (Phase 7)

Builds on the Phase 4-6 Playwright harness — no changes to the isolated-
environment safety mechanism were needed.

## New structure

```
tests/e2e/ndmo/full-lifecycle.spec.ts             (6 tests, serial)
tests/e2e/cross-program/ndmo-isolation.spec.ts    (6 tests)
tests/e2e/cross-program/quad-role.spec.ts         (1 test, four programs)
```

`tests/e2e/ndmo-import/`, `tests/e2e/ndmo-content-version/`, and
`tests/e2e/ndmo-responsibilities/` (as separate directories) were **not**
created as requested. The responsibility scenarios ARE covered (inside
`ndmo/full-lifecycle.spec.ts`, steps 17-30 — real UI assignment of Data
Owner/Data Steward and real UI verification on the Employee's task page)
but as part of the main lifecycle test rather than a standalone
directory, since responsibility assignment is exercised as one step of
the real journey, not an independent flow. Import and content-version
directories were not populated for the same reason documented for ECC:
the underlying import pipeline is not yet hierarchy-aware (see
`docs/programs/ndmo/xlsx-import.md`), so a real, non-fabricated E2E
journey cannot yet be written against working functionality.

## NDMO Full Requirement Lifecycle

Mirrors the Qiyas/Sumoud/ECC full-lifecycle specs through the exact same
reusable views. Steps 7-16 drill through `HierarchyExplorerView.vue`
across FOUR levels (Domain → Policy → Standard → create Requirement) —
one level deeper than ECC's three-level drill — proving the generic
hierarchy explorer needs no per-program adjustment for depth. Steps 17-22
add the new responsibility-assignment step (select Data Owner and Data
Steward from a department-scoped user list, both persisted and later
verified on the Employee's own task page). Uses the seeded active NDMO
test cycle and its seeded Domain/Policy/Standard.

## Cross-Program Isolation (`ndmo-isolation.spec.ts`)

NDMO-only user denied Qiyas/Sumoud/ECC routes and vice versa; program-
selection list correctly excludes NDMO for non-members and includes it
for Super Admin (alongside the other three); a foreign-parent hierarchy-
node creation attempt rejected server-side; report export denied to an
unauthorized user; **a responsibility-assignment attempt by a non-
Program-Manager denied server-side** (new check this phase).

## Multi-Program (Quad) Role Test (`quad-role.spec.ts`)

The brief's explicit four-program scenario: Qiyas Program Manager +
Sumoud Auditor + ECC Employee + NDMO Department Manager, all on one user.
Verifies all four programs are visible, correct role-gated actions are
available/unavailable at every step across all four programs in sequence,
a direct NDMO Program-Manager-only API call is denied (403) despite the
user having write access in Qiyas, and returning to Qiyas restores the
correct role context.

## Regression discipline

The complete pre-existing suite (Qiyas full lifecycle/rejection/
extension, Sumoud full lifecycle, ECC full lifecycle, permissions
isolation, all prior cross-program/role-resolution/multi-role specs,
smoke) was re-run together with the new NDMO/quad-role specs on a
freshly rebuilt isolated E2E database. Final confirmed result: **55 test
cases, 54 passed, 1 legitimately skipped (data-dependent, same skip
documented since Phase 4), 0 failed** — confirmed at reduced (3-way)
local parallelism after one transient timeout was observed and traced to
resource contention at the default 6-way parallelism now that the suite
has grown to 55 cases (the same test passed cleanly, twice, in isolation
and at 3 workers — see `docs/programs/ndmo/known-limitations.md`).
Firefox and WebKit smoke pass.
