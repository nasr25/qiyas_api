# NDMO — Responsibility Model (Data Owner / Data Steward)

New generic engine piece this phase — `compliance_responsibilities`
table, `ComplianceResponsibility` model, `ResponsibilityService`. Not
NDMO-specific in code: any program can enable specific responsibility
types through its own `responsibilities` configuration category; NDMO is
simply the first program to enable `data_owner`/`data_steward`.

## The one guarantee that matters most: labels do not bypass authorization

**No controller, policy, or Gate check anywhere in this codebase reads a
`responsibility_type` to authorize a workflow action.** This is enforced
by construction (the config schema has no field for granting authority at
all, not merely an unused one), and proven by a real test:
`NDMOProgramEngineTest::test_responsibility_label_alone_never_grants_workflow_authority`
assigns a user as Data Owner on an assignment, then attempts to have that
same user approve the Department Manager review stage through the actual
HTTP endpoint — and confirms it is forbidden (403), because the only
thing that ever grants workflow authority is a `program_user_roles`
row with the matching role key.

## Structure

`ComplianceResponsibility`: program/cycle/assignment-scoped, optional
`user_id` and/or `department_id`, `responsibility_type`, `start_date`/
`end_date`, `is_active`, `reason`, `created_by`. **Never deleted** —
`ResponsibilityService::revoke()` sets `is_active=false` and stamps
`end_date`, preserving full history for audit
(`test_responsibility_can_be_assigned_and_revoked_preserving_history`
confirms the row still exists, just inactive, after revocation).

## Configuration

NDMO's `responsibilities` category: `enabled_types: [data_owner,
data_steward]`, each with bilingual labels. An unapproved type (anything
not in `enabled_types`) is rejected by `ResponsibilityService::assign()`
— proven by `test_an_unapproved_responsibility_type_is_rejected`. Qiyas,
Sumoud, and ECC have no `responsibilities` configuration at all, so the
feature is fully absent for them (the frontend renders nothing — see
`RequirementAssignmentsView.vue`'s conditional section).

## Frontend

The assignment-creation form (`RequirementAssignmentsView.vue`) shows an
optional select per enabled responsibility type, populated from a
department-scoped user lookup, once a department is chosen. The Employee's
task detail page (`MyRequirementDetailView.vue`) displays active
responsibilities on their assignment. Both are generic — driven entirely
by the program's configuration, with no NDMO-specific markup.

## Verified end to end

The Playwright NDMO lifecycle test assigns both a Data Owner and a Data
Steward through the real UI during assignment creation, and confirms both
render correctly on the Employee's task detail page — not just at the API
level.

## Not built this phase

- Removing/reassigning a responsibility through the UI (backend endpoint
  exists — `DELETE /programs/{program}/responsibilities/{id}` — no
  frontend control calls it yet).
- Responsibility-based notification events (the brief's "Responsibility
  assigned" notification event is not wired to the domain-event pipeline
  this phase).
