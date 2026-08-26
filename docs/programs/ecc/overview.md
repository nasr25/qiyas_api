# ECC — Overview

ECC (Essential Cybersecurity Controls / الضوابط الأساسية للأمن السيبراني,
code `ECC`) is the platform's third active compliance program, and the
first to genuinely exercise the engine's flexible, arbitrary-depth
hierarchy: Main Domain → Subdomain → Control → Subcontrol (four levels),
versus Qiyas/Sumoud's two free-text levels (Perspective/Axis).

## What exists today

- The `ECC` `compliance_programs` row (migration
  `2026_07_21_000004_seed_ecc_compliance_program.php`).
- A new, generic, self-referential hierarchy engine (`ComplianceNode` +
  `ComplianceContentVersion`) — see `hierarchy.md` and
  `content-versioning.md`.
- Independent program configuration (terminology, **hierarchy** — new this
  phase, workflow, extensions, evidence, assignment, import, features with
  scoring and Not-Applicable both disabled).
- An independent workflow definition, reusing the exact same
  `WorkflowService` Qiyas/Sumoud use.
- Six dedicated ECC test accounts plus two tri-program cross-program role
  scenarios.
- One seeded active test cycle, one development content version, and a
  small four-level test hierarchy (2 controls, 1 subcontrol). **No
  official ECC domains, controls, subcontrols, guidance, evidence
  requirements, or scoring formula exist in this repository.**

## What is NOT in this phase

NDMO, ISO 27001, ISO 22301, COBIT, official ECC regulatory content, an
approved ECC scoring formula, a Not-Applicable approval workflow (deferred,
disabled), additional review committees, automatic cross-framework control
mapping, SMS/WhatsApp/Teams notifications.

## The central engineering decision this phase: a bridge, not a rewrite

Rather than rewrite `WorkflowService`/`RequirementAssignmentController`/
`EvidenceSubmissionController`/every review controller/every dashboard and
report query/the XLSX importer/every relevant Vue component to be
hierarchy-model-agnostic — a large, high-risk undertaking touching code
that has been correct and tested since Phase 1 — an ECC **assessable**
`ComplianceNode` (Control or Subcontrol) mirrors itself 1:1 into the
existing `standards` table via a nullable `compliance_node_id` bridge.
Every one of Assignment/Evidence/Review/SLA/Extension/Notification/
Dashboard/Report/XLSX-export continues to operate on `Standard` exactly as
it does for Qiyas and Sumoud, completely unmodified. `ComplianceNode`
supplies the genuine arbitrary-depth hierarchy representation and
navigation (breadcrumbs, parent/child validation, content versioning) that
`Standard`'s two free-text fields structurally cannot. See
`hierarchy.md`, "Why a bridge, not a rewrite" for the full reasoning and
the honest limits of this approach.
