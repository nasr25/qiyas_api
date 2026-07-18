# Four-Program Isolation (Qiyas / Sumoud / ECC / NDMO)

Extends `docs/three-program-isolation.md` (Phase 6) to a fourth program.
The isolation mechanisms are unchanged and generic — this document
records what was specifically re-verified for four programs
simultaneously.

## Data-layer isolation

Unchanged: FK-derived program scoping and `ComplianceNodeService::
assertSameProgram()` make cross-program contamination structurally
impossible, now proven against a THIRD distinct pairing (NDMO vs. ECC,
not just NDMO vs. Qiyas) — see `NDMOProgramEngineTest::
test_ndmo_node_cannot_use_an_ecc_parent_or_a_qiyas_cycle`.

## Request-layer isolation

Unchanged: `EnsureProgramAccess` resolves and checks `{program}` for
every program-scoped route, including the new `/programs/{program}/
responsibilities` and `/departments/{department}/users` endpoints — no
new middleware was needed.

## Verified for four programs together

- `compliance:verify-cross-program` checks every pairwise combination of
  active programs generically — now 6 pairs (QIYAS/SUMOUD, QIYAS/ECC,
  QIYAS/NDMO, SUMOUD/ECC, SUMOUD/NDMO, ECC/NDMO), all clean, with zero
  code changes needed to expand from 3 pairs (Phase 6) to 6.
- Backend: `NDMOProgramEngineTest.php` (15 tests) proves NDMO-only,
  cross-program parent/cycle rejection against TWO different foreign
  programs, and quad-program-role scenarios.
- Playwright: `tests/e2e/cross-program/ndmo-isolation.spec.ts` (6 tests)
  and `quad-role.spec.ts` (1 test, exercising all four programs on one
  user in one browser session).
- The full 55-test Chromium suite (all four programs + all cross-program
  specs) runs together, on one isolated database, with zero cross-test
  contamination.

## Responsibility-label isolation (new dimension this phase)

A responsibility assignment is scoped to its `compliance_program_id`
(copied from the assignment at creation) — `compliance:verify-program`'s
new "Unauthorized responsibility-to-assignment mappings (cross-program)"
check confirms zero such rows exist. More importantly, a responsibility
label carries no cross-program (or even same-program) authorization
weight at all — see `docs/programs/ndmo/responsibilities.md`.

## Known gap (unchanged from Phase 6)

A systematic, line-by-line audit of every cache key touching program-
scoped data was not performed this phase either — spot checks (program
configuration cache keys, which already include program ID) found no
issue, but this remains a documented gap, now carried across four
programs' worth of surface area.
