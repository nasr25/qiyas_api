# Compliance Engine — Architecture (Phase 4)

## Target vs. actual

```
Compliance Platform
|
|-- Program Configuration Engine   [NEW this phase — program_configurations]
|-- Hierarchy Engine                [already generic — Standard/AssessmentCycle, Phase 1]
|-- Assignment Engine                [already generic — RequirementAssignment/WorkflowService, Phase 2]
|-- Workflow Engine                 [NEW this phase — workflow_definitions replace PHP constants]
|-- Review Engine                    [already generic — ReviewQueueController, Phase 2]
|-- Evidence Engine                  [NEW this phase — program-scoped policy]
|-- SLA Engine                       [already generic — SlaService/SlaSetting, Phase 2]
|-- Deadline Engine                  [already generic — RequirementAssignment due-date fields, Phase 2]
|-- Extension Engine                 [NEW this phase — program-scoped reviewer role]
|-- Notification Engine              [NEW this phase — domain-event seam]
|-- Import and Export Engine         [NEW this phase — import_template_definitions]
|-- Dashboard Engine                 [PARTIAL this phase — DashboardMetricsService]
|-- Reporting Engine                 [unchanged — WorkflowReportController, Phase 2]
|-- Audit Engine                     [already unified — AuditService, Phase 1]
|
|-- Qiyas Configuration              [program_configurations rows, seeded by QiyasProgramConfigurationSeeder]
|-- Future Sumoud/ECC/NDMO Configuration  [not implemented — out of scope, see the brief]
```

Bracketed notes above are not decoration — they are the actual finding from
`docs/compliance-engine-migration.md`'s coupling analysis, and they matter:
**this phase is a refactor, not a rewrite**, because six of the fourteen
boxes already met the target architecture from Phase 1-3 design decisions
(a single centralized `WorkflowService`, generic entity names, program-scoped
`SlaSetting`/`EmailTemplate` tables, a shared `ReviewQueueController` base).
Claiming otherwise to look more thorough would be dishonest about what
changed and would misrepresent the real, lower risk profile of this phase.

## What genuinely changed

1. **Program Configuration Engine** — `program_configurations` +
   `program_configuration_versions` (see `docs/program-configuration.md`).
2. **Workflow Engine** — `workflow_definitions` /
   `workflow_stage_definitions` / `workflow_transition_definitions`
   replace the `NEXT_STAGE`/`STATUS_FOR_STAGE` PHP constants in
   `WorkflowService` (see `docs/workflow-engine.md`).
3. **Extension Engine** — the reviewer role is program-configured, not a
   literal `'auditor'` string (see `docs/extension-engine.md`).
4. **Evidence Engine** — upload limits are program-scoped, not only
   platform-wide (see `docs/evidence-engine.md`).
5. **Import/Export Engine** — the XLSX column list comes from program
   configuration (see `docs/import-export-engine.md`).
6. **Dashboard Engine (partial)** — `DashboardMetricsService` extracts the
   workflow-status count-builder Qiyas's Program Manager dashboard uses
   (see `docs/dashboard-reporting-engine.md`).
7. **Notification Engine** — `WorkflowNotificationRequested` domain event +
   `SendWorkflowNotification` listener; `WorkflowService`/`ExtensionService`
   publish events instead of calling `NotificationService` directly (see
   `docs/notification-engine.md`).

## What did not change (by design)

`SlaService`, `SlaSetting`, `EmailTemplate`, `EmailTemplateRenderer`,
`ReviewQueueController`, `EnsureProgramAccess`, `AuditService` — all
already program-agnostic before this phase, verified during the coupling
analysis, left untouched. Rewriting working, already-generic code to look
busier would have added risk for no benefit — see
`docs/compliance-engine-migration.md` for the specific evidence behind
each of these calls.

## What a future program (Sumoud, ECC, ...) reuses without copying

Every controller, service, migration, and Vue component listed above is
program-parametric already (takes a `ComplianceProgram $program` /
`route.params.programCode`, never a hardcoded ID). Onboarding a second
program requires, in order:

1. A `ComplianceProgram` row + `program_user_roles` for its team.
2. `program_configurations` rows for its terminology, extensions,
   evidence, assignment, and import categories (see
   `docs/qiyas-engine-configuration.md` for the exact Qiyas values to use
   as a starting template — not to copy the business rules, only the
   *shape* of the configuration).
3. A `workflow_definitions` + stages + transitions row set — which may
   describe an entirely different stage sequence, not just Qiyas's three
   reviewers (see the honest limitation in `docs/workflow-engine.md` about
   the `evidence_submissions.status` enum).
4. Program-scoped Vue routes reusing the exact same view components with
   `programCode` route params — no new `.vue` files needed for the
   workflow pages themselves.

No Sumoud/ECC/NDMO business content was implemented in this phase, per the
brief.
