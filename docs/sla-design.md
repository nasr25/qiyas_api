# SLA Design

## SLA vs. delivery due date — two separate concepts

| | Workflow SLA | Delivery due date |
|---|---|---|
| Measures | How long **each responsible party** takes at their stage | Whether the requirement is completed by its overall deadline |
| Entity | `SlaInstance` (one row per stage occurrence) | `RequirementAssignment.effective_due_date` |
| Who can breach it | Employee, Department Manager, Auditor, or Program Manager — individually | The requirement as a whole |
| Affected by extension approval | No | Yes — `effective_due_date` moves |
| Affected by who is currently reviewing | N/A — a new instance opens per stage | N/A — one date for the whole requirement |

They are deliberately never combined into one number. A requirement can be
`pending_auditor`, overdue on its delivery due date, while the Auditor's own
SLA instance is still well within its window — that is a **delivery**
problem caused by an earlier stage's delay, not the Auditor's fault, and the
UI/reports must not conflate the two (see "Attributing delay" below).

## Configuration (`sla_settings`, one row per `ComplianceProgram`)

Managed by that program's Program Manager (or Super Admin) via
`GET/PUT /api/v1/programs/{program}/sla-settings`. Fields:

- Four stage values + units (`employee_submission_sla_value/unit`,
  `department_manager_review_sla_value/unit`, `auditor_review_sla_value/unit`,
  `program_manager_review_sla_value/unit`) — hours or days.
- `use_business_days`, `working_days` (0=Sunday..6=Saturday, configurable —
  **not** hard-coded to any specific country's holiday calendar), 
  `working_day_start`/`working_day_end`, `timezone` (default `Asia/Riyadh`,
  fully overridable per program).
- `pause_sla_during_returned_revision`, `pause_sla_during_pending_extension`
  — policy toggles (see "Pausing" below).
- `warning_threshold_percentage` (default 80) and `is_enabled`.

Every change is audit-logged (`sla_settings.updated`, old/new values).

## Start/stop rules

Implemented in `SlaService` and called from `WorkflowService`/
`ExtensionService` at every transition — never triggered by a scheduled job
or a page view:

| Event | SLA effect |
|---|---|
| Requirement assigned | Opens an `employee` stage instance |
| Employee submits | Closes `employee` instance, opens `department_manager` instance |
| Department Manager approves | Closes `department_manager` instance, opens `auditor` instance |
| Department Manager rejects | Closes `department_manager` instance, opens a new `employee` instance |
| Auditor approves | Closes `auditor` instance, opens `program_manager` instance |
| Auditor rejects | Closes `auditor` instance, opens a new `employee` instance |
| Program Manager approves (final) | Closes `program_manager` instance — nothing else opens |
| Program Manager rejects | Closes `program_manager` instance, opens a new `employee` instance |
| Department reassigned | Cancels the open `employee` instance (`status = cancelled`, not counted as a breach), opens a fresh one for the new department |

A closed instance's final `status` is `completed_within_sla` or
`completed_after_sla`, decided by comparing the completion time to the
instance's own `due_at`.

## Historical accuracy: settings snapshot

Every `SlaInstance` stores `settings_snapshot` (the relevant `sla_settings`
values at the moment it opened). If the Program Manager changes SLA values
next month, every already-closed instance keeps reporting against the rules
that were actually in force when it ran — `SlaService::calculateDueAt()` and
the elapsed-time calculations always read from the *instance's* snapshot in
historical contexts, never re-read live settings for closed instances.

## Business-day/hour calculation

When `use_business_days` is true, `SlaService::calculateDueAt()` walks
forward from the start time, skipping non-working days and clipping to the
configured working window (`working_day_start`–`working_day_end`) each day,
until the configured stage duration (converted to minutes) is consumed. When
false, it is a plain calendar addition. No holiday calendar is hard-coded —
if a deployment needs one, `working_days` is the only mechanism provided in
Phase 1; a full holiday-calendar feature is deferred (see final report).

## Breach/warning detection

`php artisan compliance:process-sla` (scheduled every 30 minutes — see
`docs/windows-scheduler-and-queues.md`):

1. For every **active** `SlaInstance` with a `due_at`: if `now() > due_at`,
   mark it `breached` and queue a `sla_breached` notification once (keyed by
   instance ID — re-running the command never re-notifies for the same
   instance since its status is no longer `active`).
2. Otherwise, if elapsed time ≥ `warning_threshold_percentage` of the total
   window, queue a `sla_warning` notification once per instance (keyed by
   instance ID, independent of how many times the command runs).
3. Separately, for every active `RequirementAssignment` whose
   `effective_due_date` has passed, records a `requirement_became_overdue`
   event and queues a daily-deduplicated `requirement_overdue` reminder.

## Attributing delay (not blaming the Employee for reviewer delay)

Because each stage has its **own** `SlaInstance`, reports can separate:

- Requirements currently delayed *because the Employee* hasn't acted
  (`employee` stage instance breached/overdue).
- Requirements waiting *with* the Department Manager, Auditor, or Program
  Manager (their respective stage instance active/breached).

`WorkflowDashboardController::departmentManager()` and
`WorkflowReportController` build employee-workload and department reports
from this per-stage data — an employee's dashboard never shows a "breach"
for a stage they were never responsible for. See
`docs/qiyas-role-permissions.md`.

## Pausing (documented, minimal implementation in Phase 1)

`pause_sla_during_returned_revision` and `pause_sla_during_pending_extension`
are stored and exposed in the settings UI as policy intent for Phase 2's
scope. The **employee** stage instance opened on a rejection already
represents the "employee is now correcting a returned submission" period
correctly by design (a fresh instance, not a resumed clock), which satisfies
the practical need in the common case. Full pause/resume of a single
in-flight instance's clock (rather than closing and reopening) is
deferred — see the final report's technical-debt section.
