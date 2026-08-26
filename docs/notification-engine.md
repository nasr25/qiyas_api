# Notification Engine

## What changed in Phase 4: events, not direct calls

New event `App\Events\WorkflowNotificationRequested` (eventType,
assignment, resolved recipients) and its sole listener
`App\Listeners\SendWorkflowNotification`, registered in
`AppServiceProvider::boot()`. `WorkflowService::notify()` and
`ExtensionService::notify()` now call `event(new
WorkflowNotificationRequested(...))` instead of calling
`NotificationService::dispatchForAssignment()` directly — satisfying the
brief's explicit "Workflow services must publish events, not send emails
directly."

Recipient resolution (`assignmentRecipients()`,
`departmentManagerRecipients()`, `stageRecipients()`,
`reviewersAndManager()`, etc.) deliberately stays in the dispatching
service rather than moving into the listener — those methods already have
the department/program/role context needed to find the right people, and
duplicating that resolution logic in the listener would be a second
source of truth for "who gets notified," not a cleaner architecture.

`ProcessSlaCommand`'s SLA-triggered notifications
(warning/breach/overdue) were **not** moved onto this event — they are
dispatched by a scheduled command, not a request-scoped workflow
transition, and forcing them through the same event/listener seam would
not have changed their actual behavior. They do get the same resilience
fix (see below).

## Resilience fix (found via Phase 4 E2E testing, not designed in advance)

`SendWorkflowNotification::handle()` and `ProcessSlaCommand`'s
notification calls are now wrapped in `try/catch`, logging failures
instead of letting them propagate. **Why this was necessary**: the Phase 4
E2E backend runs `QUEUE_CONNECTION=sync` (a legitimate, simple deployment
configuration, and the fastest way to get deterministic E2E test
behavior without a queue worker process). Under `sync`, a queued
`Notification`'s mail send happens inline, in the same request — so a
plain, unreachable-SMTP-server error (`Connection could not be established
with host "127.0.0.1:587"`) was crashing the entire
`RequirementAssignmentController::store()` request with a 500 error,
discovered the moment the Playwright full-lifecycle test tried to create
its first assignment. With a real (non-sync) queue connection this exact
failure would already have been isolated to the background job — but the
code should not have depended on that being the only safety net. **Fixed**:
notification delivery is now unconditionally a best-effort side effect of
a workflow transition, never a precondition for it, regardless of queue
driver.

## Unchanged (already correct)

- Idempotency: `NotificationLog.idempotency_key` unique constraint +
  `firstOrCreate()`/`wasRecentlyCreated`, unchanged this phase.
- Per-recipient read/delete isolation:
  `NotificationController` scopes every query through
  `$request->user()->notifications()` — reverified in Phase 4 via
  `tests/Feature/Workflow/EndToEndBusinessScenariosTest.php`'s Scenario 13
  test (unchanged, still passing).
- Safe template rendering (`EmailTemplateRenderer`), recipient-locale
  selection — unchanged, Phase 3 verification still holds.

## Verified

Full backend suite (107 tests) passes with the event/listener refactor in
place, confirming zero behavioral regression. The Playwright full-lifecycle
suite's assignment-creation step is itself the regression test that
exposed and then confirmed the fix for the sync-queue crash — it now
passes end to end.
