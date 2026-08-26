# Three-Program Isolation (Qiyas / Sumoud / ECC)

Extends `docs/cross-program-isolation.md` (Phase 5, Qiyas/Sumoud) to a
third program. The isolation mechanisms are unchanged and generic — this
document records what was specifically re-verified for three programs
simultaneously, not a new mechanism.

## Data-layer isolation

Unchanged: FK-derived program scoping (`ComplianceNode` derives
`compliance_program_id` from `cycle_id`) makes cross-program hierarchy
contamination structurally impossible, for both the two-level
(Qiyas/Sumoud) and four-level (ECC, via `ComplianceNode`) shapes.
`ComplianceNodeService` adds the same guarantee at the node level
(`assertSameProgram()`), tested explicitly against both a Qiyas parent and
a Sumoud cycle.

## Request-layer isolation

Unchanged: `EnsureProgramAccess` resolves and checks `{program}` for
every program-scoped route, including the new `/programs/{program}/
hierarchy` and `/content-versions` endpoints — no new middleware was
needed.

## Verified for three programs together

- `compliance:verify-cross-program` now checks every pairwise combination
  of active programs generically (QIYAS/SUMOUD, QIYAS/ECC, SUMOUD/ECC) —
  not hardcoded to two, so a future fourth program is automatically
  covered without a code change.
- Backend: `ECCProgramEngineTest.php` (15 tests) proves ECC-only,
  Qiyas-only, Sumoud-only, and tri-program-role scenarios.
- Playwright: `tests/e2e/cross-program/ecc-isolation.spec.ts` (5 tests)
  and `multi-role.spec.ts` (1 test, exercising all three programs on one
  user in one browser session).
- The full 42-test Chromium suite (Qiyas + Sumoud + ECC + all
  cross-program specs) runs together, on one isolated database, with zero
  cross-test contamination — confirmed by running it as a single suite,
  not per-program in isolation.

## Role-context caching risk — reviewed, not newly discovered

The Phase 5 frontend role-resolution fix (`authStore.hasProgramRole()`,
`canAccessInProgram()`) already re-resolves role context per route
navigation, with no session-level caching of "the current role." The
Phase 6 multi-role Playwright test is the first test to actually switch
between three DIFFERENT roles across three DIFFERENT programs in one
session and confirm the role context is correct at every step, including
after returning to the first program — no regression found.

## Known gap

Cache-key composition for dashboard/report queries was not audited line
by line this phase (the brief's "Cache and Session Isolation" section) —
`ProgramConfigurationService`'s cache key already includes program ID
(reviewed, correct); dashboard/report controllers were spot-checked
(program correctly resolved per request, no observed cross-program cache
key), but a systematic audit of every cache key touching program-scoped
data was not performed. No concrete contamination was found in the
testing done, but this is not the same as a completed audit — see the
final Phase 6 report's remaining-issues section.
