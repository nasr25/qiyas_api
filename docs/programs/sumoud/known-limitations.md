# Sumoud — Known Limitations (Phase 5)

Honest gap list this phase's final report points to.

## Real engine defects found and fixed this phase

1. **`CycleService::create()` silently defaulted to QIYAS** when no program
   was passed, and the only write endpoints for cycle lifecycle
   (create/update/activate/close/archive) lived on the legacy,
   non-program-scoped `/cycles` routes. A Sumoud Program Manager could
   never create a Sumoud cycle through the API. Fixed by adding
   program-scoped write actions to `ProgramCycleController`
   (`/programs/{program}/cycles/...`), reusing the same `CycleService`
   with an explicitly resolved program — never the fallback.
2. **`CyclesView.vue` called the legacy unscoped `cyclesService`**
   (`GET/POST /cycles`), which returns/creates against **every** program's
   cycles with no filtering — invisible while only Qiyas existed, a
   real cross-program data leak and mis-attribution risk once Sumoud
   exists. Fixed by rewriting `cyclesService` to be program-scoped and
   updating `CyclesView.vue`/`CycleDetailView.vue` to pass the current
   program code.
3. **Two hardcoded `RouterLink :to="/cycles/${id}"` targets** in
   `CyclesView.vue` always resolved through the legacy route's
   QIYAS-only redirect, regardless of which program's list they were
   rendered from — clicking "open cycle" from the Sumoud cycles page
   navigated to a **Qiyas** cycle. Fixed to use the named,
   program-scoped route with the current `programCode`.
4. **Hierarchy write routes (`/cycles/{cycle}/standards`,
   `/standards/{standard}/requirements`) were gated by platform-wide
   spatie permissions** (`standards.create`, etc.), not by program
   membership — a Sumoud Program Manager has no matching spatie
   permission at all, only a `program_user_roles` grant, and was
   unconditionally denied. Fixed by moving authorization into the
   controllers (`authorizeManage()`), accepting either the existing
   spatie permission (zero behavior change for Qiyas) or an active
   program-manager role in the record's own program.
5. **`GET /departments` required the `departments.view` spatie
   permission**, blocking the assignment form's department dropdown for
   any user without a matching platform-wide role — even though
   Departments are explicitly shared, global, non-sensitive reference
   data. Fixed the same way: permission OR any active program
   membership.
6. **The frontend router guard and nav-visibility check
   (`AppLayout.vue`) authorized purely on platform-wide spatie role
   names** (`auth.hasRole()`), with zero visibility into program-scoped
   roles at all — `UserResource` didn't even expose them. A Sumoud
   Program Manager/Auditor/Department Manager (who legitimately holds
   no spatie role) was silently redirected away from every
   program-scoped action page and saw no matching nav items, despite
   the backend already correctly authorizing them. Fixed by exposing
   `program_roles` on `UserResource`/`/auth/me`, adding
   `authStore.hasProgramRole()`, and a shared `canAccessInProgram()`
   helper used by both the router guard and the nav `canSee()` check —
   translating the pre-existing spatie-style role names in `meta.roles`
   to their generic program-role-key equivalent as a fallback path,
   never removing the original spatie-role path (zero behavior change
   for existing Qiyas users).
7. **The pre-existing Qiyas Playwright test helpers
   (`full-lifecycle.spec.ts`, `rejection-journeys.spec.ts`,
   `extension-journey.spec.ts`) read the legacy unscoped `GET
   /api/v1/cycles`** for their own setup/verification, picking
   `.find(c => c.status === 'active')` — safe when only Qiyas had an
   active cycle, but picks the wrong program's cycle now that Sumoud
   also has one, silently creating test standards under the wrong
   program. Fixed by switching these three files to the program-scoped
   `/programs/QIYAS/cycles?status=active` endpoint — a test-only fix,
   confirmed by re-running the full Qiyas Playwright regression clean
   afterward.

None of the above are Sumoud-specific hacks — every fix is a genuine,
generic engine correction that benefits Qiyas too (e.g. Qiyas's own
`CyclesView.vue` now correctly uses the program-scoped, isolated cycle
endpoints rather than the legacy global one).

## Deferred, not fixed (documented, not hidden)

- **`StandardsView.vue`/`StandardDetailView.vue`** (the legacy
  Document-based flow, superseded by the Phase 2 workflow engine for
  Qiyas itself) still check `authStore.isCoordinator`/`isEmployee`
  directly rather than through `canAccessInProgram()`. Not fixed: this
  legacy flow is not part of the generic engine's mandatory lifecycle
  (evidence submission uses `MyRequirementDetailView`, not this path)
  and was already superseded before Phase 5.
- **`/reviews/auditor/extension-requests` URL is not program-configurable**
  — carried forward unchanged from Phase 4's documented gap.
- **Perspective/Axis remain free-text fields**, not normalized Domain/
  Category entities — carried forward unchanged from Phase 1/4. Sumoud
  uses the identical free-text shape Qiyas uses; this was a deliberate,
  documented decision to reuse the existing hierarchy exactly, not
  reopen a materially larger schema change.
- **SLA time-travel Playwright tests, full Sumoud XLSX Playwright
  journey, Sumoud responsive tests, full cross-browser (Firefox/WebKit)
  scenario coverage beyond smoke, and literal notification-content
  assertions** were not built this phase — the same categories of gap
  already left open for Qiyas itself in Phase 4, not newly introduced by
  Sumoud, and not required to prove genuine second-program engine reuse.
- **`compliance:verify-program`/`compliance:verify-cross-program`
  duplicate some check logic already in `compliance:verify-qiyas`**
  rather than fully unifying it — a deliberate, documented tradeoff to
  avoid regression risk to an existing, relied-upon command.
- **`App\Exports\Qiyas\*` namespace/class names** remain Qiyas-branded
  despite being genuinely generic implementations (confirmed by reading
  the source — they take any program code/id). A cosmetic naming debt,
  not a functional one; renaming was judged not worth the diff size and
  regression risk this phase.

## Release readiness

See the Phase 5 final report for the formal ECC-readiness classification
and full blocker list.
