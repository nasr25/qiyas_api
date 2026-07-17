# Evidence Versioning

## Two levels of versioning

1. **Submission version** (`evidence_submissions.version_number`) — one row
   per full submission cycle for a `RequirementAssignment`. The first time
   an employee opens an assigned requirement, version 1 is created in
   `draft` status. Every time a submission is rejected and the employee
   starts correcting it, a **new** row is created (`version_number + 1`) —
   the rejected version is never edited or deleted, it remains a permanent,
   read-only historical record with its own files and decision.

2. **File version** (`evidence_files`, scoped to one `evidence_submission`)
   — every uploaded file belongs to exactly one submission version. Files
   are never overwritten: uploading a "replacement" file adds a new
   `evidence_files` row; removing a file while still in `draft` soft-deletes
   it (`is_active = false`) and deletes the physical object from storage,
   recorded as a `evidence_removed` workflow event and audit log entry.

## Why this shape

`WorkflowDecision` rows reference `evidence_submission_id`, not
`requirement_assignment_id` — a decision is always made against one
specific, immutable version. This is what makes "Program Manager cannot
approve an outdated evidence version" enforceable: `WorkflowService::decide()`
locks the `EvidenceSubmission` row and checks its `status` still matches the
expected pending status for that stage before allowing a decision — if the
employee has already started a new version (impossible while a decision is
pending, but relevant if two reviewers race), the stale action gets a 409
Conflict, not a silent no-op.

## What is preserved forever

- Every `EvidenceSubmission` row (all versions, including rejected ones).
- Every `EvidenceFile` row, active or not, with its SHA-256 `file_hash`.
- Every `WorkflowDecision` (append-only — see below).
- Every `WorkflowEvent` in the timeline, referencing the exact
  `evidence_version` involved.

## Append-only decisions

`WorkflowDecision` is **never** updated or deleted by application code. A
correction is always a new `EvidenceSubmission` version with its own new
decisions — there is no "edit review" action anywhere in the system.

## Security

Evidence files are stored on the `private` disk under
`evidence/{program_id}/{assignment_id}/{submission_id}/{uuid}.{ext}` — the
UUID-based `stored_name` is unrelated to the original filename, and the
storage path is never returned to the client. Every download goes through
`EvidenceSubmissionController::downloadFile()`, which re-checks
`EvidenceSubmissionPolicy::downloadFile()` (program + department scoping)
before streaming the file and writing an `evidence.downloaded` audit log
entry — knowing a file ID alone is not sufficient to retrieve it.

See `docs/qiyas-role-permissions.md` for exactly which roles can view/
download which submissions.
