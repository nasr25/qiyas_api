# ECC — Evidence Engine

Uses the exact same generic Evidence Engine Qiyas/Sumoud use
(`EvidenceUploadValidator`, `WorkflowService::addFile()`/`getOrCreateDraft()`,
`EvidenceSubmissionController`) — zero code change, since an ECC Control's
evidence submission is a normal `EvidenceSubmission` row tied to the
bridged `Standard` (via `RequirementAssignment.requirement_id`).

An ECC evidence file is linked (through the existing chain) to: ECC
program (`compliance_program_id`), ECC cycle, the assessable
`ComplianceNode` (via `Standard.compliance_node_id`), assignment,
submission version, department, and uploading user — no new relationship
was required.

## File policy

Independent per-program configuration (`evidence` category) — same
mechanism proven independent for Sumoud in Phase 5
(`ProgramConfigurationEngineTest::test_evidence_upload_limits_are_program_scoped_not_platform_wide`),
unchanged this phase. ECC's current values are organizational defaults,
not an official file policy — see `known-limitations.md`.

## Evidence catalog (structured requirements)

Populated only when explicitly provided — `ComplianceNodeService`'s
`evidence_requirements_ar` attribute writes directly to the bridged
`Standard.evidence_documents` field (the same field Qiyas/Sumoud use for
this purpose); nothing is manufactured from the control description.

## Cross-program isolation

Unchanged mechanism: a user's access to Qiyas/Sumoud evidence never
grants ECC evidence access, since `EvidenceFile`/`EvidenceSubmission`
authorization always re-derives from the acting program's
`program_user_roles` and department scope — proven generically by the
Phase 5 cross-program isolation suite, exercised again for ECC in
`tests/e2e/cross-program/ecc-isolation.spec.ts`.
