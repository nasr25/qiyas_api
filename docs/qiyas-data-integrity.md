# Qiyas — Phase 3 Data Integrity

## `php artisan compliance:verify-qiyas`

A new, read-only command (never writes to the database) verifying the
Phase 2 operational-workflow data specifically, complementing Phase 1's
`compliance:verify-migration`. See
`app/Console/Commands/VerifyQiyasDataIntegrity.php`.

Reports counts for: the Qiyas program, active cycles, distinct perspectives/
axes, standards, requirement assignments, evidence submissions, evidence
files, review decisions, extension requests, SLA instances, notification
deliveries, and import logs.

Then runs 17 integrity checks:

| Check | What it catches |
|---|---|
| Assignments with dangling department_id / requirement_id | A department or standard deleted without cleaning up its assignments. |
| Submissions without a parent assignment | A submission created outside `WorkflowService`. |
| Evidence files without a parent submission | An orphaned upload (e.g. a failed transaction that didn't fully roll back). |
| Decisions without a parent submission | A `WorkflowDecision` written outside `WorkflowService::decide()`. |
| Duplicate active assignments for the same requirement | A race in `WorkflowService::assign()`'s "one active assignment per requirement" invariant slipping past its row lock. |
| Duplicate pending extension requests for the same assignment | `ExtensionService::request()`'s "one pending request at a time" rule being violated. |
| Duplicate active SLA instances for the same assignment+stage | Two SLA clocks running for the same stage simultaneously — would corrupt delay attribution. |
| Active SLA instances left open on a completed assignment | `SlaService::closeActiveInstance()` being skipped on the final-approval path. |
| Approved submissions without a final Program Manager decision | A submission reaching `approved` status through anything other than `WorkflowService::decide()`'s final-approval branch. |
| Returned-for-revision submissions without a rejection decision | Same class of bug, for the reject path. |
| Approved extensions whose effective due date was not advanced | `ExtensionService::decide()`'s approval branch not actually updating the assignment. |
| Submissions with a status outside the known workflow states | Any write that bypassed the domain service entirely (e.g. a raw DB update). |
| Standards missing an Arabic name | Data-entry/import gap in an Arabic-first platform. |
| Submissions / assignments / SLA instances missing `compliance_program_id` | Program-context loss — the same class of check Phase 1's migration verifier runs, extended to Phase 2 tables. |

Run it any time — after a migration, after a bulk import, before or after a
production deployment, or as a periodic health check. Exit code `0` on all
checks passing, `1` if any fails. Nothing about running it is destructive or
irreversible.

```
php artisan compliance:verify-qiyas
```

Verified against the current dev database: all 17 checks pass.

Two automated tests prove the command actually detects a problem rather than
always reporting success (`tests/Feature/Workflow/DataIntegrityCommandTest.php`):
one confirms a clean pass on a healthy fixture, the other deliberately writes
an `approved` submission with no matching `WorkflowDecision` (bypassing
`WorkflowService`, simulating exactly the kind of bug this command exists to
catch) and confirms the command returns exit code `1` and names the specific
failing check — while also confirming the command itself did not "fix"
anything (`WorkflowDecision::count()` still `0` afterward), since it is
read-only by design.

## Database constraints reviewed

- **Foreign keys**: every Phase 2 table's foreign keys use
  `constrained()->cascadeOnDelete()` or `->nullOnDelete()` as appropriate
  (e.g. `evidence_files.evidence_submission_id` cascades — a deleted
  submission's files are cleaned up; `requirement_assignments.employee_id`
  nulls out rather than cascading — deleting a user record must not delete
  the historical assignment).
- **Uniqueness enforced at the DB level, not just application code**:
  `evidence_submissions (requirement_assignment_id, version_number)`,
  `notification_logs.idempotency_key`, `sla_settings.compliance_program_id`,
  `email_templates.template_key` — each of these is also an
  application-level invariant, but backed by a real unique index so a bug
  in the service layer cannot silently produce duplicates.
- **Enums**: `evidence_submissions.status`,
  `requirement_assignments.status`, `workflow_decisions.stage`/`decision`,
  `sla_instances.stage`/`status`, `extension_requests.status` are all DB
  `enum` columns, not free-text — an invalid value is rejected by MySQL
  itself, not merely by application validation.
- **Character encoding**: the platform's migrations use Laravel's default
  connection charset (`utf8mb4` on MySQL), confirmed sufficient for Arabic
  text storage — no mojibake observed in any Arabic field across the demo
  dataset (`الأنظمة الحكومية`-style content renders correctly in both the
  API responses and the generated XLSX template's Arabic instructions
  sheet).
- **Portable SQL**: every Phase 2 migration uses Laravel's Blueprint DSL
  (`->change()`, named indexes under MySQL's 64-character limit) rather than
  raw MySQL-specific SQL, which is why the full test suite runs against
  SQLite without any migration failure — a real portability check, not a
  claim.

## Not added: partial/conditional unique indexes for "one active row"

MySQL 8 does not support partial (filtered) unique indexes, and a
regular unique index on `(requirement_assignment_id, stage)` for
`sla_instances` would incorrectly reject the normal case of a *closed*
instance followed by a new *active* one for the same stage (e.g., after a
rejection cycle re-opens the employee stage). The "only one active row"
invariant is therefore enforced by `WorkflowService`'s
transaction-plus-row-lock discipline (verified structurally, not by a DB
constraint) and independently checked for by
`compliance:verify-qiyas`'s duplicate-active-instance query. This is a
deliberate trade-off, not an oversight — documented here so it isn't
mistaken for one.
