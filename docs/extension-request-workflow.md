# Extension Request Workflow

## Entity

Phase 2 reuses the existing Phase 1 `ExtensionRequest` table rather than
creating a parallel Qiyas-specific entity — see
`docs/multi-program-architecture.md`. Two shapes coexist on the same table:

- **Legacy** (`document_id` set): the original Phase 1 Document-based flow,
  unchanged.
- **Phase 2** (`requirement_assignment_id` set): the RequirementAssignment/
  EvidenceSubmission workflow described here.

## Rule: the Department Manager is never in this loop

An extension request goes **directly** from the Employee to the Auditor.
`ExtensionService::decide()` is only reachable via
`/api/v1/programs/{program}/reviews/auditor/extension-requests/{id}/approve|reject`,
gated by `ExtensionRequestPolicy::decide()`, which checks
`hasProgramRole($program, 'auditor')` — a Department Manager calling this
endpoint gets a 403 regardless of which department they manage. The
Department Manager can only **view** that a request exists, via
`ExtensionRequestController::forAssignment()`.

## Request rules (enforced in `ExtensionService::request()`)

1. Reason is required (`FormRequest`-level validation on the controller).
2. Requested due date is required and must be a future date.
3. Requested due date must be later than the assignment's **current
   effective due date** — not the original one, so a second extension after
   a first was approved must still move the date forward.
4. Only one `pending` request may exist per `RequirementAssignment` at a
   time — a second request while one is pending returns 409.

## Decision rules (enforced in `ExtensionService::decide()`)

1. Only a `pending` request can be decided — deciding twice returns 409.
2. Rejection requires a reason (validated at the controller, enforced again
   in the service).
3. On approval: `RequirementAssignment.effective_due_date` is set to the
   requested date. `original_due_date` is **never** touched.
4. On rejection: nothing about the assignment changes; the employee keeps
   working against the existing effective due date.

## Due date model

| Field | Meaning | Ever overwritten? |
|---|---|---|
| `original_due_date` | The date set when the requirement was first assigned | No |
| `effective_due_date` | The date actually in force — moves only on extension approval | Yes, on each approved extension |
| `current_due_date` (on the `ExtensionRequest` row) | Snapshot of the effective due date at the moment the request was made | No (historical record) |
| `requested_due_date` | What the employee asked for | No |

Because `original_due_date` never changes, a requirement that was already
late when an extension was later approved still shows its true historical
delay — the extension does not retroactively erase it. See
`docs/sla-design.md` §"SLA vs. delivery due date" for how this interacts
with SLA measurement (they are independent: approving an extension does
**not** touch any `SlaInstance` row).

## Notifications and audit

Every request/decision writes a `WorkflowEvent` (`extension_requested`,
`extension_approved`, `extension_rejected`) and an `AuditService` entry, and
queues (deduplicated) notifications:

- On request: the program's Auditors and the assignment's Department
  Manager.
- On decision: the requesting Employee and the Department Manager.

See `docs/email-notifications.md` for the template keys involved.

## Cross-program isolation

`ExtensionRequestController`/`AuditorReviewController` resolve the request
by `id` **and** `compliance_program_id` together — an auditor authorized
only for QIYAS cannot decide an extension request that belongs to a
different program, even if they guess a valid ID (404, not 403, to avoid
confirming the ID's existence).
