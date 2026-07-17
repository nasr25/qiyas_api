# Email Notifications

## Architecture

Three cooperating pieces, designed so a future channel (SMS, Teams, ...)
can be added without touching the workflow services:

1. **`EmailTemplate`** (platform-wide, Super Admin managed) — one row per
   `template_key` (== `event_type`), bilingual subject/body, `is_enabled`,
   `supported_variables`.
2. **`NotificationService::dispatchForAssignment()` /
   `dispatchOnce()`** — the single place that decides *whether* to queue a
   notification, using a deterministic idempotency key written to
   `NotificationLog` (unique constraint) before dispatch. `WorkflowService`,
   `ExtensionService`, and `ProcessSlaCommand` never call
   `$user->notify()` directly — only through this service.
3. **`WorkflowEventNotification`** (`ShouldQueue`) — the actual
   `Illuminate\Notifications\Notification` class. `via()` returns
   `['database', 'mail']` if an enabled template exists for the event, or
   just `['database']` otherwise (disabled/missing template → in-app
   notification still fires, no email is sent — this is what satisfies
   "disabled template does not send").

## Deduplication

`NotificationService::dispatchOnce()` uses
`NotificationLog::firstOrCreate(['idempotency_key' => $key], [...])` — the
unique constraint on `idempotency_key` guarantees that even if the same
logical event is dispatched twice (e.g. a retried request, or the scheduled
command finding the same breach on two runs), only the **first** call's
`wasRecentlyCreated` is true and actually calls `notify()`. Keys are built
deterministically:

- Assignment-scoped events: `{event}:assignment:{id}:v{submission_version}:user:{recipient}`.
- SLA warnings: `sla_warning:instance:{instance_id}` (no date suffix — fires
  once ever per instance).
- SLA breaches: `sla_breached:instance:{instance_id}:{date}` (breach state
  is terminal per instance, so in practice this also fires once).
- Overdue reminders: `requirement_overdue:assignment:{id}:day:{date}` — an
  intentional daily repeat, one per calendar day the requirement stays
  overdue.

## Variable rendering — no code execution

`EmailTemplateRenderer` substitutes `{{variable}}` tokens via
`preg_replace_callback` against a fixed variable map built from the
assignment/submission/recipient — there is no `eval`, no Blade compilation
of user-editable content, and no way for a template author to run PHP.
Values are HTML-escaped (`e()`) in the body and stripped of line breaks in
the subject (preventing header injection). Unknown `{{placeholders}}` are
left as literal text rather than silently blanked, so a Super Admin
authoring a template immediately sees a typo instead of a mysteriously empty
sentence.

Supported variables (`EmailTemplateRenderer::supportedVariables()`):
`recipient_name`, `employee_name`, `reviewer_name`, `department_name`,
`program_name`, `cycle_name`, `requirement_code`, `requirement_name`,
`current_status`, `due_date`, `effective_due_date`, `requested_due_date`,
`days_remaining`, `days_overdue`, `sla_due_at`, `sla_breach_duration`,
`rejection_reason`, `review_notes`, `action_url`. The Super Admin template
editor (`EmailTemplateController::update()`) rejects any `{{...}}` token
outside this list at save time.

## Super Admin management

`GET/PUT /api/v1/admin/email-templates[/{id}]` — Super Admin only
(`role:super-admin`). Also:

- `POST .../{id}/preview` — renders subject/body with sample bracketed
  values (`[recipient_name]`, ...) in the requested locale, using the exact
  same `EmailTemplateRenderer` as real sends, so preview and production
  output can never diverge.
- `POST .../{id}/test-send` — sends a real templated email to an
  admin-supplied address using the platform's actual mail configuration,
  without exposing SMTP credentials to the response. Audit-logged
  (`email_template.test_sent`).

## Event → template key list

Every key below is seeded with default bilingual content by
`EmailTemplatesSeeder` and is the literal `event_type` passed to
`NotificationService`:

`requirement_assigned`, `requirement_reassigned`, `employee_reassigned`,
`submission_sent_to_department_manager`, `department_manager_rejected`,
`submission_sent_to_auditor`, `auditor_rejected`,
`submission_sent_to_program_manager`, `program_manager_rejected`,
`program_manager_approved`, `extension_requested`, `extension_approved`,
`extension_rejected`, `sla_warning`, `sla_breached`, `requirement_overdue`.

The remaining events named in the brief (due-date-updated,
department-manager-approved as a *distinct* notification from
submission-sent-to-auditor, employee-assigned as distinct from
requirement-assigned, upcoming-due-date reminders at configurable
day-offsets, and a dedicated SMS/Teams channel) are not separately wired in
Phase 1 — the mechanism above is fully general and adding a new event is a
one-line `NotificationService` call plus a seeded template row; which
specific additional events get wired is deferred, see the final report.

## In-app notification center

`WorkflowEventNotification::toArray()` writes into Laravel's existing
`notifications` table (the same one Phase 1's Document-based notifications
already use) — each row's `notifiable_id` scopes it to exactly one
recipient. `NotificationController::markRead()`/`destroy()` already operate
per-row by primary key, so one user reading or deleting their own
notification cannot affect another user's row — this was already correct
in Phase 1 and required no change for Phase 2.

## Recipient language

`toMail()`/`toArray()` read `$notifiable->locale` (the recipient's own
saved preference, defaulting to `ar`) — never the acting user's locale — so
a bilingual team always receives mail in their own configured language
regardless of who triggered the event.
