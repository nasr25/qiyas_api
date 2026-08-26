# Cross-Program Isolation

How the platform guarantees a Qiyas record and a Sumoud record can never
mix, and how this was verified for Phase 5.

## Data-layer isolation

Every program-scoped table carries `compliance_program_id`. The critical
mechanism proving isolation is not "trust the frontend" but **FK-derived,
model-boot-time scoping**. `Standard::creating()` did this originally; the
`Standard` model has since been retired and **`ComplianceNode` carries the
same guarantee**, deriving `compliance_program_id` from its cycle rather than
trusting the request. It remains structurally impossible to create a node of
one program under another program's cycle: the row becomes owned by the
cycle's program regardless of who created it or what program context the
request believed it was in.

Node identity is `Program + Structure Version + Hierarchy Level + Code`, with
no fuzzy matching anywhere, so cross-program collision is not possible by
construction either.

## Request-layer isolation

`EnsureProgramAccess` middleware (unchanged since Phase 1) resolves
`{program}` from the URL, checks the program exists/is active, and checks
`$user->hasProgramAccess($program)` before the controller runs. A
nonexistent AND an unauthorized program both return **404, never 403** —
deliberately preventing program-code enumeration.

Every program-scoped controller re-derives child records
(`cycle`/`requirement`/`assignment`/`submission`) with an explicit
`->where('compliance_program_id', $program->id)` (or the equivalent FK
chain), returning 404 for any ID belonging to a different program — never
trusting a client-supplied ID alone.

## Verified isolation points (Phase 5)

- Cycles: `ProgramCycleController` scopes every read/write.
- Hierarchy: FK-derived (see above).
- Assignments/Evidence/Reviews/Extensions/SLA/Dashboards/Reports/Import:
  already program-scoped via `/programs/{program}/...` routes since
  Phase 2/4 — zero code change needed.
- Departments: shared, global, never duplicated per program.
- Import: hidden metadata sheet's `program_code` checked against the
  *current* program on every validate call (`WRONG_PROGRAM` error code).

## Real gaps found and fixed this phase

See `docs/programs/sumoud/known-limitations.md` items 1-3: the legacy
unscoped `/cycles` routes/service (both product code and — separately —
three pre-existing Qiyas Playwright test helpers) were the actual places
isolation was not yet enforced/assumed, precisely because only one program
existed before Sumoud. All were fixed and re-verified.

## Automated verification

- `php artisan compliance:verify-program {code}` — per-program internal
  consistency + basic cross-program relationship checks.
- `php artisan compliance:verify-cross-program` — pairwise contamination
  detector across every active program (standards/assignments/submissions
  linked to a foreign cycle/requirement/assignment; orphaned program
  memberships). Both are read-only.
- Backend: `SumoudProgramEngineTest.php` (19 tests).
- Playwright: `tests/e2e/cross-program/isolation.spec.ts` (7 tests, HTTP-
  level 403/404 checks, not just UI visibility).
