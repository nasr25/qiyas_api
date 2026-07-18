# UAT Plan

**Author:** Nasser

## Purpose and scope

User Acceptance Testing is a **separate gate from automated testing**.
A green PHPUnit/Playwright/k6 run means the software behaves as its
authors intended; it does not mean the business has accepted it.
**This platform must never be marked "UAT passed" based only on
automated test results** — every scenario in
`docs/testing/uat-scenarios.md` and `docs/uat/qiyas-uat-ar.md`/
`qiyas-uat-en.md` ships with blank Actual Result/Pass-Fail/Tester/Test
Date fields, to be filled in by a real human tester during a real UAT
round.

## Test case format

`UAT-<ROLE>-<NN>`, each with: Preconditions, Steps, Expected Result,
Actual Result (blank), Pass/Fail (blank), Tester (blank), Test Date
(blank), Notes (blank).

## Roles covered

| Role | Existing scenarios | Document |
|---|---|---|
| Super Admin | 3+ (email templates, audit log) | `docs/uat/qiyas-uat-en.md`/`-ar.md` |
| Executive Viewer | Yes | Same |
| Program Manager (Qiyas) | Yes | Same |
| Auditor (Qiyas) | Yes | Same |
| Department Manager (Qiyas) | Yes | Same |
| Employee (Qiyas) | Yes | Same |
| Cross-cutting (any role) | Yes | Same |
| Super Admin — Branding | New this phase | `docs/testing/uat-scenarios.md` |
| Super Admin — SMTP | New this phase | Same |
| Offline use | New this phase | Same |
| Multi-program / multi-role user | New this phase | Same |
| Backup-restored-environment smoke test | New this phase | Same |

## Required test data

`docs/qiyas-test-data-guide.md` and the per-program
`docs/programs/{sumoud,ecc,ndmo}/test-data.md` files list the seeded
accounts and demo data each scenario set assumes. Quick Login accounts
(password `Password123!` where a password is needed at all) are listed
in `docs/roles-and-scopes.md` and `README.md`.

## Approval gates

A UAT round requires both:

- **Business approval** — the scenario owner (the role's real business
  stakeholder, not the tester) signs off that the *behavior* meets
  their actual need, not just that the steps executed without error.
- **Technical approval**, where a scenario has a technical dependency
  (e.g. a real SMTP relay, a real AD domain) that could not be fully
  exercised in a lower environment — the technical approver confirms
  the dependency was actually exercised in the UAT environment, not
  assumed.

Neither approval is granted by this document — both must be recorded
per-scenario by the actual people running the UAT round.

## Known scope gap

Per-program UAT scenario sets for Sumoud, ECC, and NDMO (equivalent to
the 29 Qiyas scenarios) were **not separately authored** in this
phase — the underlying engine is generic and every Qiyas scenario's
*shape* applies directly to the other three programs with only
program/terminology substitution, but the actual per-program scenario
documents do not exist yet. This is recorded as an open item in the
final readiness report rather than silently omitted; a real UAT round
covering Sumoud/ECC/NDMO should derive scenarios from the Qiyas
template plus each program's `docs/programs/{program}/workflow.md`
before that round begins.
