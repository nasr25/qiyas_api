# Compliance Engine — Coupling Analysis and Refactoring Plan (Phase 4)

Written **before** any Phase 4 code change, per the brief's mandatory
analysis-first requirement. Every finding below is backed by a direct file
reference, not an assumption — see the grep/read commands in this session
for how each was located.

## Method

Searched the entire `app/` tree for: literal `'QIYAS'`/`"QIYAS"` string
comparisons, `$program->code ===` branches, hardcoded stage/transition maps,
Qiyas-named classes, and role-key literals scattered outside policy
definitions. Cross-checked the frontend for the same patterns. This is a
smaller list than a from-scratch platform would produce, because Phases 1-2
already made several of the target architectural decisions (generic entity
names, a single centralized `WorkflowService`, a shared `ReviewQueueController`
base, program-scoped `SlaSetting`/`EmailTemplate` tables) — the analysis
below reflects that starting position honestly rather than manufacturing
findings to justify a larger rewrite than the codebase needs.

## Findings

| # | Location | Finding | Classification |
|---|---|---|---|
| 1 | `app/Services/WorkflowService.php` `NEXT_STAGE`/`STATUS_FOR_STAGE` | The stage-transition map is a PHP class constant, not data. Correct in *behavior* (every transition is already centralized through one service — no controller sets status directly) but not *configurable* — a future program cannot get a different stage sequence without a code change. | **Must refactor now** — this is the literal target of the brief's Workflow Engine requirement. |
| 2 | `app/Exports/Qiyas/RequirementsSheet.php` `COLUMNS` constant, `App\Exports\Qiyas\*`, `QiyasImportController`/`QiyasImportService`/`QiyasImportValidator` | The entire import/export path is Qiyas-named and hardcodes the column list as a PHP constant. A future program's XLSX columns would require new PHP classes, not configuration. | **Must refactor now** — matches the brief's explicit "Do not hard-code Qiyas headings inside the generic importer." |
| 3 | `app/Policies/ExtensionRequestPolicy.php::decide()` | Hardcodes the literal role-key `'auditor'` as the extension-reviewer role. The *behavior* (Auditor decides, Department Manager view-only) is Qiyas's own business rule and correctly must not change — but the *mechanism* enforcing it should read from program configuration so a future program's different reviewer role doesn't require editing this class. | **Must refactor now** — small, safe, matches "configurable requester/reviewer role." |
| 4 | `app/Services/EvidenceUploadValidator.php` | Reads file-type/size limits from the **platform-wide** `Setting` group `evidence_upload`, not a program-scoped policy. One set of upload rules currently applies to every program. | **Must refactor now** — matches the brief's explicit "Evidence policies must be program-scoped." |
| 5 | `app/Services/CycleService.php::create()` line 29 | Defaults to the QIYAS program when none is passed, documented as "backward compatibility with the legacy `/cycles` route." | **Qiyas-specific by design / safe to defer** — an explicit, documented compatibility shim for one legacy route, not scattered logic; every other call site already passes an explicit program. |
| 6 | `app/Http/Controllers/Api/{DashboardController, ExecutiveDashboardController, Programs/ProgramDashboardController, Workflow/WorkflowDashboardController}` + 3 report controllers | Four dashboard controllers and three report controllers each independently construct similar `RequirementAssignment::forProgram($program)->active()->where(...)->count()`-style queries. Not pure duplication — `DashboardController` is the pre-Phase-1 legacy flat-route dashboard kept for URL compatibility, `ProgramDashboardController` is Phase 1's taxonomy-level view, `WorkflowDashboardController` is Phase 2's per-stage workflow view — but the count-query construction itself is duplicated across them. | **High-risk technical debt / partially addressed this phase** — full consolidation of all four touches every dashboard's existing, tested, live-verified behavior; addressed partially (see §6 of this plan) by extracting a shared metrics service for the workflow dashboard specifically, the rest deferred. |
| 7 | `app/Policies/*`, `app/Services/Workflow*`, `app/Http/Controllers/Api/Workflow/*` — role-key string literals (`'program-manager'`, `'auditor'`, `'department-manager'`, `'employee'`) | Used as string literals in 16 files rather than named constants. These are already the **generic** Phase 1 role vocabulary (not Qiyas-specific names — a future program reuses the identical four role keys), just not centralized. | **Shared platform concern / safe to defer** — a code-quality nit, not a barrier to reuse. |
| 8 | `frontend/src/locales/{ar,en}.json` `workflow` namespace | Qiyas terminology (منظور/محور/معيار) lives in the static i18n files, not pulled from program configuration at runtime. | **Qiyas-specific by design / no refactor needed** — this is exactly where the brief itself says terminology belongs ("Program-specific terminology only at the presentation layer"). Backend entities (`Standard.perspective`/`Standard.axis`) are already generic free-text columns, not renamed to Qiyas terms in the schema. A forward-looking config-exposed terminology endpoint is added (§6) so a future program's frontend *can* pull labels dynamically, but the current approach is not a defect. |
| 9 | `frontend/src/router/index.js` `DEFAULT_PROGRAM_CODE = 'QIYAS'` | One documented constant driving the legacy flat-route → program-scoped-route redirect. | **Qiyas-specific by design / safe to defer** — single documented location, explicitly for backward compatibility, not scattered assumption-of-one-program logic. |
| 10 | `Standard.perspective` / `Standard.axis` columns | Free-text strings, not a normalized hierarchy table. Carried forward as deferred debt from Phase 1 (documented in `docs/multi-program-architecture.md`) and Phase 2/3 known-issues. | **High-risk technical debt / safe to defer** — normalizing this is a materially larger, riskier schema change than the time available in this phase; re-flagging honestly rather than attempting it under pressure. |
| 11 | `app/Http/Controllers/Api/Workflow/ReviewQueueController.php` (abstract base for Department Manager/Auditor/Program Manager review) | Already a single shared service with per-role subclasses providing only `stage()`/`ability()`/`roleLabel()` — no duplicated approval logic. | **Already satisfies target architecture** — this already is the "Review Engine" the brief asks for; no refactor needed. |
| 12 | `app/Services/{SlaService, NotificationService, EmailTemplateRenderer}.php` | Already program-agnostic: no `$program->code` branching, driven entirely by program-scoped `SlaSetting`/`EmailTemplate` rows. | **Already satisfies target architecture** — no refactor needed. |
| 13 | `app/Http/Middleware/EnsureProgramAccess.php` | Already fully generic program isolation, resolves by route-parameter code, no Qiyas-specific logic. | **Already satisfies target architecture** — no refactor needed. |

## What this means for scope

Findings #11-13 being already-compliant is the reason this phase is a
**refactor**, not a **rewrite** — most of the "engine" boundaries the brief
describes already exist as the actual service architecture from Phases 1-3;
what's missing is specifically: (a) the workflow stage/transition map living
in code instead of data, (b) two policy decisions (extension reviewer role,
evidence limits) being platform-wide instead of program-scoped, and (c) the
XLSX import/export path being Qiyas-named instead of configuration-driven.
Sections 1-4 above are exactly the four changes this phase makes to the
backend. Section 6 (dashboards) is addressed partially, not fully, and is
reported honestly as such rather than claimed complete.

## Refactoring sequence for this phase

1. Program Configuration Engine: `program_configurations` +
   `program_configuration_versions` tables (validated JSON per category,
   versioned, audited), seeded with Qiyas's current values extracted
   *from* the hardcoded locations above (not invented new values) —
   zero behavior change on seed.
2. Workflow Engine: `workflow_definitions` / `workflow_stage_definitions` /
   `workflow_transition_definitions` tables; refactor `WorkflowService` to
   read the transition map from these (cached), replacing the `NEXT_STAGE`/
   `STATUS_FOR_STAGE` constants; seed the exact current Qiyas sequence.
3. Extension Engine: `ExtensionRequestPolicy::decide()` reads the reviewer
   role from program configuration instead of the `'auditor'` literal.
4. Evidence Engine: `EvidenceUploadValidator` reads from a program-scoped
   evidence policy (falling back to the existing platform `Setting` values
   as defaults, so no currently-configured limit changes for Qiyas).
5. Import/Export Engine: `import_template_definitions` table holds Qiyas's
   column mapping; the exporter and validator read from it instead of the
   `RequirementsSheet::COLUMNS` constant — output file is byte-identical
   for the existing test fixtures.
6. Dashboard Engine (partial): a shared `DashboardMetricsService` with
   reusable count-builder methods, adopted by `WorkflowDashboardController`.
7. Domain events: `WorkflowStageChanged`, `ReviewApproved`, `ReviewRejected`,
   `ExtensionApproved`, `ExtensionRejected` events dispatched by the
   workflow/extension services; `NotificationService` dispatch moves behind
   a listener so notification delivery is event-driven rather than a direct
   call embedded in the transition logic (SLA completion and audit logging
   remain synchronous, inside the same transaction as the status change —
   both require read-your-write consistency with the transition itself,
   which an async listener would not guarantee; documented explicitly as a
   deliberate boundary, not an oversight).
8. Re-run the full existing test suite after every step above — zero
   regression is the acceptance bar for each step, not just the final one.

Steps not undertaken this phase (see `docs/compliance-engine-known-issues.md`
for the full list with justification): full consolidation of all four
dashboard/report controllers into one engine; normalizing
`perspective`/`axis` into a real hierarchy table; a fully generic frontend
terminology-injection layer (the presentation-layer approach already in use
is compliant, so this is an enhancement, not a fix).
