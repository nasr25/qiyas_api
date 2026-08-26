# Multi-Program Compliance Platform Architecture

> **Superseded in part.** This document described the Phase 1 multi-program
> layer, when hierarchy was still two free-text columns on `standards` and
> `Standard` was the assignable entity. Both are gone. The program /
> membership / isolation model below remains accurate; the hierarchy,
> authoring and taxonomy sections are corrected inline and marked
> **[superseded]**. The authoritative description of the current engine is
> [`dynamic-compliance-structure.md`](dynamic-compliance-structure.md).

Status: Phase 1 complete. Only the QIYAS program is active and fully
functional. This document describes the architecture introduced to support
multiple compliance programs (Sumoud, ECC, NDMO, ...) without another major
database or authorization redesign.

## 1. Platform identity vs. program identity

The application is now a **Government Compliance Management Platform**
(منصة إدارة الامتثال والحوكمة الحكومية), not "the Qiyas system". Qiyas is
the first **Compliance Program** hosted on the platform.

- Global branding (`settings` table, group `branding`) carries the platform
  name.
- Each `ComplianceProgram` row carries its own name, description, colors,
  icon, and terminology — Qiyas's own branding lives there, not in global
  settings.

## 2. Core entity: ComplianceProgram

`compliance_programs` (`app/Models/ComplianceProgram.php`):

| Field | Purpose |
|---|---|
| `code` | Stable identifier used in URLs and API routes (e.g. `QIYAS`) |
| `name_ar` / `name_en`, `description_ar` / `description_en` | Bilingual identity |
| `logo`, `icon`, `primary_color`, `secondary_color` | Branding |
| `status` (`draft`/`active`/`inactive`), `is_active` | Lifecycle + visibility |
| `sort_order` | Program Selection card ordering |
| `settings` (json) | Program-specific configuration, currently holds `terminology` (see §5) |
| `created_by` / `updated_by` | Audit trail |

Seeded once, in the schema migration itself (not a seeder — see
`qiyas-migration-plan.md` for why), so the platform is never in a state
where zero programs exist.

## 3. Generic hierarchy — what was actually done vs. deferred

The target hierarchy from the brief is:

```
Compliance Program → Program Cycle → Domain → Category → Requirement
                                    → Requirement Assignment → Evidence Submission
```

**What changed (low risk, additive):**

- `assessment_cycles` now functions as **ProgramCycle**: gained
  `compliance_program_id` (required), `name_ar`/`name_en`, `is_current`,
  `settings`, `closed_by`. The table and model name (`AssessmentCycle`)
  were **not** renamed.
- `compliance_nodes`, `requirement_assignments`, `evidence_submissions`, `extension_requests`, `comments`, `audit_logs`
  (`standards` and `documents` are retained but empty, with no writers)
  all gained a `compliance_program_id` column, auto-stamped from their
  parent on creation (see model `booted()` hooks), so every domain query can
  filter by program without walking the FK chain.

**Deferred technical debt (documented, not silently dropped):**

- **[superseded] Domain / Category are not normalized tables.** They now
  are: every level of every program is a `hierarchy_level_definitions` row,
  and every node a `compliance_nodes` row. The free-text
  `standards.perspective` / `standards.axis` columns no longer back any
  read or write path.
  Splitting them into first-class `domains` / `categories` tables would
  touch the 89-row DGA standards importer (`StandardsCatalogSeeder`,
  `database/seeders/data/qiyas_standards.json`), the Excel
  import/template/export flows, and every report query — judged too risky
  for Phase 1's "no rewrite, incremental refactor" mandate.
  `/api/v1/programs/{program}/domains` and `.../categories` exist and
  [superseded — these endpoints were removed] returned distinct
  `perspective`/`axis` values with counts, so
  the API contract is already in its final shape; the underlying storage
  is the deferred piece.
- **[superseded] Standard is not renamed to Requirement.** `Standard` is
  retired as an authoring model. "Requirement" now means an assessable
  `ComplianceNode`, and what it is *called* comes from the program's own
  level definition — Criterion for Qiyas, Control for ECC, Requirement for
  NDMO. The new
  `/api/v1/programs/{program}/requirements` route is a generic-named
  read-only view over the same table — this is the "abstraction layer"
  the brief explicitly allows in place of a risky rename.
- Full CRUD/assignment API for creating and configuring *new* programs
  (Sumoud, ECC, NDMO) is **not built**. Phase 1 provisions QIYAS via a
  migration + seeders/artisan commands only. Adding the Super Admin
  program-management UI/API is Phase 2 work, done when the next real
  program is introduced.

## 4. Program access and role model

Two authorization layers coexist deliberately:

1. **Platform-level roles** — unchanged, global `spatie/laravel-permission`
   roles (`super-admin`, `executive`, plus the legacy `qiyas-admin`,
   `auditor`, `coordinator`, `employee` used by every existing route).
   These continue to gate the legacy flat API exactly as before.
2. **Program-level roles** — new `program_user_roles` table
   (`app/Models/ProgramUserRole.php`). One row grants a user a `role_key`
   (`program-manager` / `auditor` / `department-manager` / `employee`)
   inside one `ComplianceProgram`, optionally scoped to one `Department`.
   This is the layer the new `/api/v1/programs/*` routes and the Program
   Selection page read.

`User::hasProgramAccess(ComplianceProgram $program)`
(`app/Models/User.php`) is the single source of truth:

- `super-admin` → always true (full platform access).
- `executive` → true for any active program (read-only executive scope).
- everyone else → true only if an **active** `program_user_roles` row
  exists for that user + program.

Why two layers instead of migrating everything to `program_user_roles`
immediately: it lets every existing route, controller, test, and
permission check keep working untouched (zero regression risk), while the
new program layer is additive. Collapsing them into one system is
reasonable Phase 2 cleanup once a second program actually exists and the
duplication cost is felt in practice.

## 5. Program-specific terminology

Stored in `compliance_programs.settings->terminology`, shape:

```json
{
  "domain":      { "ar": "المنظور", "en": "Perspective" },
  "category":    { "ar": "المحور", "en": "Axis" },
  "requirement": { "ar": "المعيار", "en": "Standard" },
  "evidence":    { "ar": "مستند الإثبات", "en": "Evidence Document" },
  "cycle":       { "ar": "دورة القياس", "en": "Qiyas Cycle" }
}
```

Exposed via `ComplianceProgramResource.terminology`. Backend entities stay
generic (`Standard`, not `QiyasStandard`); only the label the UI shows is
program-specific. Frontend wiring of these labels into the Requirements/
Cycles views (beyond the Program Selection card) is left for Phase 2 —
today only the API contract exists.

## 6. Routing

New API surface (`routes/api.php`, `program.access` middleware):

```
GET /api/v1/programs
GET /api/v1/programs/{program}
GET /api/v1/programs/{program}/dashboard
GET /api/v1/programs/{program}/cycles[/{cycle}]
GET /api/v1/programs/{program}/domains
GET /api/v1/programs/{program}/categories
GET /api/v1/programs/{program}/requirements[/{requirement}]
GET /api/v1/programs/{program}/reports/{by-department|by-standard|by-status|cycle-summary}
GET /api/v1/executive-dashboard
```

`{program}` is matched by **code** (e.g. `QIYAS`), resolved and
access-checked by `EnsureProgramAccess` before any controller runs — see
§7. **[superseded]** The flat routes `/standards` and `/documents` were
removed with the legacy authoring path; compliance content is authored
exclusively through `/programs/{program}/hierarchy` at whatever depth the
program's structure defines.

Frontend: `/programs` (selection) and `/programs/:programCode/...`
(dashboard, cycles, requirements, reports, my-requirements, and
`settings/structure`) are the supported paths. **[superseded]** The
`documents`, `auditor` and `my-standards` frontend views listed here were
removed with the legacy path. The legacy flat frontend paths (`/dashboard`,
`/cycles`, ...) are kept as router redirects into the QIYAS program, so
nothing bookmarked or linked externally breaks. The nested views reuse the
exact same Vue components and still call the legacy flat API endpoints —
wiring them to the new `/api/v1/programs/{program}/...` endpoints is
deferred (functionally identical today since Qiyas is the only program).

## 7. Security model

`app/Http/Middleware/EnsureProgramAccess.php`, aliased `program.access`:

1. Resolve `{program}` by `code`.
2. Not found → **404** (not 403 — never confirm/deny existence to an
   unauthorized caller).
3. Not active AND caller is not `super-admin` → **404**.
4. Caller has no access (`User::hasProgramAccess()`) → **404**.
5. Otherwise, the resolved `ComplianceProgram` is attached to the request
   (`$request->attributes->get('compliance_program')`) for controllers to
   read.

Nested resources (`ProgramCycleController`, `ProgramRequirementController`,
`ProgramReportController`) additionally verify the requested record's
`compliance_program_id` matches the resolved program before returning it —
closing the cross-program IDOR vector where a user authorized for QIYAS
guesses another program's cycle/standard/report id. See
`tests/Feature/ComplianceProgramAccessTest.php` for the enforced matrix.

## 8. Audit logging

`audit_logs` gained a nullable `compliance_program_id` column.
`AuditService::log()` accepts an optional `complianceProgramId` argument
(falls back to reading it off the passed model if present).
`CycleService` was updated to pass it on every cycle lifecycle action. New
program-related events are not yet emitted for
create/activate/deactivate/assign — see `roles-and-scopes.md` for what
exists today and what Phase 2 must add once program CRUD exists.

## 9. Known deferred items (Phase 2)

- Domain/Category normalization (see §3).
- Full rename of `Standard` → `Requirement` at the storage layer.
- Program CRUD + program_user_roles assignment management UI/API.
- Wiring program-scoped frontend views to `/api/v1/programs/...` instead
  of the legacy flat endpoints.
- Per-role dashboard shaping on `/api/v1/programs/{program}/dashboard`
  (it currently returns one aggregate shape for any authorized role,
  unlike the legacy `/dashboard` endpoint which differentiates by role).
- Backend `lang/` translation files (pre-existing gap: `__('auth.failed')`
  and validation messages resolve to literal English regardless of
  locale — not introduced by this phase, not fixed by it either).
