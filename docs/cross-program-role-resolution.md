# Cross-Program Role Resolution

How a single user can hold different roles in different programs, and how
both the backend and frontend resolve "what can this user do, in this
program, right now."

## Backend (unchanged since Phase 1 — already generic)

`program_user_roles` (`ProgramUserRole` model): one row per
`(user_id, compliance_program_id, role_key, department_id?)`. `User` model
methods:

- `hasProgramAccess($program)` — any active row (or platform Super Admin).
- `hasProgramRole($program, $roleKey)` — a specific active role in that
  program (or Super Admin).
- `programRoleKeys($program)` — all active role keys in that program.

Every program-scoped controller authorizes through these, never through a
platform-wide spatie role alone — this was already correct before Phase 5
in every controller built during Phase 2/4 (`RequirementAssignmentController`,
`SlaSettingController`, `QiyasImportController`, ...).

## The real gap Phase 5 found and closed: the frontend had none of this

Before this phase, `UserResource` exposed only platform-wide spatie
`roles`, and the Vue router guard / `AppLayout.vue` nav-visibility check
authorized purely against that array (`authStore.hasRole()`). A user with
**only** a program-scoped grant (no matching spatie role — the normal case
for any Sumoud-only account) was invisible to this check entirely: silently
redirected off every gated program route and shown no matching nav items,
even though the backend already authorized them correctly.

Fixed by:

1. `UserResource` now includes `program_roles`: a map of
   `{ "SUMOUD": ["auditor"], "QIYAS": ["program-manager"] }`, loaded via
   `->load('programRoles.program')` on login/quick-login/me.
2. `authStore.hasProgramRole(programCode, roleKey)` reads that map.
3. `src/utils/roleAccess.js`'s `canAccessInProgram(auth, programCode, roles)`
   — checks the existing platform-wide role list first (zero behavior
   change for current Qiyas users), then falls back to the program-scoped
   equivalent via a small translation table (`qiyas-admin` →
   `program-manager`, `coordinator` → `department-manager`, `auditor` →
   `auditor`, `employee` → `employee`) resolved against the CURRENT route's
   `programCode`.
4. Both the router's `beforeEach` guard and `AppLayout.vue`'s `canSee()`
   now call this one shared helper.

## Verified scenarios (all four named in the Phase 5 brief)

| User | Qiyas | Sumoud | Verified by |
|---|---|---|---|
| A | Program Manager | Auditor | `cross-program/role-resolution.spec.ts` — assignments visible/available in Qiyas, unavailable in Sumoud; reviewer queue reachable in Sumoud; backend 403s a manual Sumoud PM-only POST |
| B | Employee | Department Manager | Same spec — assignments unreachable in Qiyas, department-manager review queue reachable in Sumoud |
| C | Auditor | *(none)* | `auditor_2`, an existing untouched account — `cross-program/isolation.spec.ts` confirms 404 on any Sumoud route |
| D | Employee | Employee (same dept) | `SumoudProgramEngineTest::test_dual_program_user_can_access_both_with_different_roles` (backend) |

The application never caches one role and reuses it in another program:
every check re-resolves against the CURRENT route's `programCode`, and the
backend re-derives from `program_user_roles` on every request (no session-
level role caching at all).
