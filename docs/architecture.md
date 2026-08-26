# Architecture

**Author:** Nasser

## Overview

A multi-program government compliance platform: a Laravel 13 JSON API
backend and a separate Vue 3 SPA frontend, hosting four active
Compliance Programs (**Qiyas**, **Sumoud**, **ECC**, **NDMO**) on one
generic engine. See `docs/multi-program-architecture.md` for the
original Phase-1 design and `docs/compliance-engine-architecture.md`
for the configuration-driven engine introduced in Phase 4.

```
┌─────────────────────┐        HTTPS/JSON        ┌──────────────────────────┐
│  Vue 3 SPA (Vite)    │ ───────────────────────► │  Laravel 13 API           │
│  Pinia · vue-router  │ ◄─────────────────────── │  /api/v1                  │
│  vue-i18n · Tailwind │                           │  JWT auth, Spatie RBAC    │
└─────────────────────┘                           └───────────┬──────────────┘
                                                                │
                                        ┌───────────────────────┼───────────────────────┐
                                        ▼                       ▼                       ▼
                                    MySQL 8               Queue (database          Local disk storage
                              (all app data,               driver, `jobs`/         (private: evidence,
                          program-scoped tables)         `failed_jobs` tables)     imports; public:
                                                                                    branding assets)
```

## Program isolation

Every program-scoped resource carries a `compliance_program_id`
(directly or via a scoped parent). `EnsureProgramAccess` middleware
resolves `/programs/{program}/...` routes by program `code`; an
inactive program or a user with no program role gets a 404 (never a
403, so a URL guess cannot even confirm the program exists to that
user). Nested controllers re-verify the program id on every model they
touch, closing cross-program IDOR even if a resource id from one
program were guessed against another program's route. See
`docs/cross-program-isolation.md`, `docs/three-program-isolation.md`,
`docs/four-program-isolation.md` for the isolation tests run at each
program-count milestone.

## Roles

Two layers, not one:

- **Platform-level** (spatie/laravel-permission): `super-admin`,
  `executive`. Super Admin bypasses all authorization
  (`Gate::before`); Executive Viewer gets implicit read-only access to
  every active program.
- **Program-level** (`program_user_roles.role_key`): `program-manager`,
  `auditor`, `department-manager`, `employee` — scoped to the specific
  program (and, for department-manager/employee, the specific
  department) the row grants.

Full matrix in `docs/roles-and-scopes.md`.

## The generic hierarchy engine

Qiyas and Sumoud originally used a flat, free-text `perspective`/`axis`
grouping. ECC's official standard has a 4-level hierarchy
(Domain → Subdomain → ... ), which a flat model couldn't represent, so
Phase 6 introduced `ComplianceNode` (arbitrary-depth structure/
navigation tree, self-referencing `parent_id`) and
`ComplianceContentVersion` (versioned official-standard content bound
to a node). `Standard`/`Requirement` — the assignment/evidence/
workflow unit — is bridged to `ComplianceNode` rather than merged into
it, so existing Qiyas/Sumoud data needed no migration. NDMO (Phase 7)
reused this engine with a 5th hierarchy level and required **zero**
engine changes, which is the strongest evidence the design is actually
generic rather than ECC-specific.

## Workflow engine

Program-configurable stage/transition definitions
(`workflow_definitions`, `workflow_stage_definitions`,
`workflow_transition_definitions`) drive a `WorkflowService` shared by
every program. `RequirementAssignment` → `EvidenceSubmission` →
`WorkflowDecision` is the append-only decision trail (never mutates a
past decision — a resubmission creates a new submission version).

## Responsibility engine (Phase 7)

A generic `ComplianceResponsibility` model (program-scoped,
append-only assignment history) generalizes "who is responsible for
this node/requirement" beyond the original department-manager/
employee shape — used by NDMO's Data Owner/Data Steward roles without
any NDMO-specific schema.

## Settings, branding, and SMTP (Phase 8)

Three purpose-built stores, additive to the pre-existing generic
`Setting` key-value table (still used for non-secret, non-versioned
settings like platform name and upload limits):

- `branding_assets` — versioned, per-asset-type (logo/favicon)
  uploads; only one `active` row per type; `activate()`/`restore()`
  never delete history. See `docs/administration/branding.md`.
- `smtp_settings` — a single encrypted-at-rest row (`Crypt::` on the
  password); applied to the runtime mail config at boot. See
  `docs/administration/smtp-settings.md`.
- `setting_versions` — an append-only change log shared by both,
  secret-aware (never stores a decrypted historical secret).

## Storage layout

| Disk | Root | Visibility | Contents |
|---|---|---|---|
| `private` | `storage/app/private` | Not web-accessible | Evidence files, import files/error reports |
| `public` | `storage/app/public` | Web-accessible via `/storage` | Branding assets, favicons |

## Frontend architecture

Vue 3 + Vite + Pinia + vue-router + vue-i18n + Tailwind CSS + Headless
UI + Heroicons. (Note: an earlier phase's stated tech stack mentioned
PrimeVue; it was never actually integrated in this codebase — the real
UI has always used Tailwind + Headless UI. This is a documentation
correction, not a code change.) `src/stores/app.js` holds global
locale/theme/branding state; `src/services/index.js` is the single
axios-based API client layer.

## Related documents

- `docs/compliance-engine-architecture.md` — the configuration-driven
  engine in full detail
- `docs/programs/{sumoud,ecc,ndmo}/overview.md` — per-program specifics
- `docs/security/security-hardening.md` — the security review of this
  architecture
