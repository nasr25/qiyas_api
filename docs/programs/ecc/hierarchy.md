# ECC — Flexible Hierarchy Engine

## The problem

Qiyas and Sumoud represent their hierarchy as two free-text fields
(`standards.perspective`, `standards.axis`) plus the leaf `Standard` row
itself — safe for exactly two parent levels above the assessable item.
ECC's real shape needs up to four: Main Domain → Subdomain → Control →
Subcontrol. Forcing that into the two-level free-text shape would either
lose a level of official structure or require concatenating levels into a
single string — both explicitly forbidden by the brief.

## The solution: `ComplianceNode` — one generic, self-referential table

`compliance_nodes` (migration `2026_07_21_000002_...`): `parent_id`
(self-FK), `node_type`, `level` (0-based, computed from parent), `code`,
bilingual name/description/guidance, `is_assessable`, `status`, `metadata`,
program/cycle/content-version scoping — represents ANY depth, for ANY
program that configures a `hierarchy` category, not just ECC. No new table
per level.

## Validation (`ComplianceNodeService`)

Every rule the brief's "Hierarchy Validation" section requires is enforced
in one place, never left to callers:

- **Program match**: parent, cycle, and content version must all belong to
  the same program as the node being created — cross-program references
  throw `InvalidHierarchyException` (422), not a silent 500 or a
  successfully-created contaminated row.
- **Valid parent/child type pairs**: read from the program's own
  `hierarchy.levels[].parent_type` — a generic lookup table, not an
  ECC-specific if-branch. A `control` cannot be a root node; a `subdomain`
  cannot parent another `subdomain`.
- **Maximum configured depth**: `hierarchy.max_depth` (4 for ECC).
- **Duplicate codes**: rejected within the same `(program, content
  version)` scope, enforced twice — once in `ComplianceNodeService`
  implicitly via the unique index, and again at the database level
  (`compliance_nodes_program_version_code_unique`) as defense in depth.
- **Circular hierarchy**: structurally near-impossible by construction (a
  node's parent must already exist before the child can reference it), but
  `compliance:verify-program {code}` independently walks every node's
  ancestor chain (bounded to 20 hops) and reports any that never reach a
  root, as a read-only detector against any future direct-DB manipulation.

All four proven by real tests in `ECCProgramEngineTest.php` (invalid
parent/child type, ECC node with a Qiyas parent, ECC node with a Sumoud
cycle, max-depth exceeded, duplicate code).

## Why a bridge, not a rewrite

An **assessable** `ComplianceNode` (Control, Subcontrol — per ECC's
`hierarchy` config, both are marked `is_assessable: true` so an approved
source that stops at Control level still works) mirrors itself into
`standards` via `ComplianceNodeService::createAssessableNode()`, setting
`standards.compliance_node_id` back-reference. The mirrored row's
`perspective`/`axis` fields are populated from the node's own ancestor
chain (Domain/Subdomain names) purely for display inside the *existing*
Standard-based views — `ComplianceNode`'s own parent chain remains the
source of truth for real navigation.

This means every one of `WorkflowService`, `RequirementAssignmentController`,
`EvidenceSubmissionController`, `DepartmentManagerReviewController`,
`AuditorReviewController`, `ProgramManagerReviewController`, `SlaService`,
`ExtensionService`, the notification pipeline, `DashboardMetricsService`,
the report controllers, and the XLSX template export (since renamed to
`App\Exports\Hierarchy\HierarchyTemplateExport`, retiring the cosmetic
"Qiyas" naming debt noted here) works for an ECC
Control with **zero code changes** — confirmed by the full ECC Playwright
lifecycle passing through the exact same
`RequirementAssignmentsView.vue`/`MyRequirementDetailView.vue`/
`ReviewDetailView.vue` components Qiyas and Sumoud use.

## Honest limitation

A non-assessable node (Domain, Subdomain) has no bridge — it exists only
in `compliance_nodes`, with no equivalent `Standard` row. This is correct
(Domains/Subdomains are not assignable/assessable items), but it does mean
`ComplianceNode` and `Standard` are two different, only partially
overlapping representations of "the hierarchy" for ECC — a genuine, if
necessary, architectural seam. A future phase migrating Qiyas/Sumoud onto
`ComplianceNode` fully (replacing free-text perspective/axis) would remove
this seam platform-wide; not attempted this phase — see
`known-limitations.md`.
