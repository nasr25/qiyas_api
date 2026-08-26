# Qiyas — User Acceptance Testing (UAT) Checklist (English)

See `docs/qiyas-test-data-guide.md` for the test accounts and seeded
scenarios referenced below. Fill in "Actual Result," "Pass/Fail," "Tester,"
and "Test Date" during your UAT round — this document ships with those
fields blank by design.

Test case ID format: `UAT-<ROLE>-<NN>`.

---

## Super Admin

### UAT-SA-01 — Manage a program's email templates
- **Preconditions**: Logged in as `superadmin`.
- **Steps**: 1) Navigate to Admin → Email Templates. 2) Open the
  `requirement_assigned` template. 3) Edit the English body text (small,
  reversible change). 4) Save. 5) Use "Preview" to render it with sample
  values. 6) Use "Test Send" to send to your own inbox.
- **Expected Result**: Save succeeds; preview renders both Arabic and
  English versions with sample data substituted for every `{{variable}}`;
  test-send delivers one email without exposing SMTP credentials anywhere
  in the response.
- **Actual Result**:
- **Pass/Fail**:
- **Tester**:
- **Test Date**:
- **Notes**:

### UAT-SA-02 — Template variable validation rejects unknown placeholders
- **Preconditions**: Logged in as `superadmin`, editing any email template.
- **Steps**: 1) Add `{{not_a_real_variable}}` to the template body. 2) Save.
- **Expected Result**: Save is rejected with a clear error naming the
  unsupported variable — not silently accepted.
- **Actual Result**:
- **Pass/Fail**:
- **Tester**:
- **Test Date**:
- **Notes**:

### UAT-SA-03 — View platform-wide audit log
- **Preconditions**: Logged in as `superadmin`. At least one workflow
  action has occurred (use the seeded demo data).
- **Steps**: Navigate to Admin → Audit Logs, filter by program = Qiyas.
- **Expected Result**: Every workflow action (assignment, submission,
  decision, extension, import) appears with user, role, department, and
  timestamp — never with file contents or secrets.
- **Actual Result**:
- **Pass/Fail**:
- **Tester**:
- **Test Date**:
- **Notes**:

### UAT-SA-04 — Protected health check
- **Preconditions**: Logged in as `superadmin`.
- **Steps**: Call `GET /api/v1/admin/health` (via a REST client, with your
  auth token).
- **Expected Result**: Returns per-component status (database, cache,
  queue, storage, scheduler) without any hostname, credential, or file
  path in the response.
- **Actual Result**:
- **Pass/Fail**:
- **Tester**:
- **Test Date**:
- **Notes**:

---

## Executive Viewer

### UAT-EV-01 — Read-only dashboard access
- **Preconditions**: Logged in as `executive_viewer`.
- **Steps**: Navigate to the Executive Dashboard for Qiyas.
- **Expected Result**: Aggregate compliance metrics display correctly; no
  edit, assign, approve, reject, or import controls are visible anywhere.
- **Actual Result**:
- **Pass/Fail**:
- **Tester**:
- **Test Date**:
- **Notes**:

### UAT-EV-02 — Cannot perform a write action even via direct API call
- **Preconditions**: Logged in as `executive_viewer`, have your auth token.
- **Steps**: Attempt `POST /api/v1/programs/QIYAS/assignments` directly
  (bypassing the UI).
- **Expected Result**: Rejected with 403, regardless of UI hiding the
  button — this proves backend enforcement, not just UI hiding.
- **Actual Result**:
- **Pass/Fail**:
- **Tester**:
- **Test Date**:
- **Notes**:

---

## Qiyas Program Manager

### UAT-PM-01 — Assign a requirement to a department
- **Preconditions**: Logged in as `qiyas_admin`. An unassigned standard
  exists in the current cycle.
- **Steps**: 1) Open Assignments → Create. 2) Select a standard, a
  department, an optional employee, and a due date. 3) Save.
- **Expected Result**: Assignment created with status "Assigned"; the
  chosen department's Employee/Department Manager can now see it.
- **Actual Result**:
- **Pass/Fail**:
- **Tester**:
- **Test Date**:
- **Notes**:

### UAT-PM-02 — Reassign a requirement to a different department
- **Preconditions**: An active assignment exists (e.g. seeded scenario 1).
- **Steps**: 1) Open the assignment. 2) Choose "Reassign." 3) Select a new
  department. 4) Enter a reason (required). 5) Save.
- **Expected Result**: Reassignment is rejected without a reason; with a
  reason, the old department loses access and the new department gains it;
  the assignment's history still shows the original department.
- **Actual Result**:
- **Pass/Fail**:
- **Tester**:
- **Test Date**:
- **Notes**:

### UAT-PM-03 — Configure SLA settings
- **Preconditions**: Logged in as `qiyas_admin`.
- **Steps**: Open SLA Settings, change one stage's SLA value, save.
- **Expected Result**: Saved successfully; a bilingual explanation is shown
  for each field; the change is audit-logged.
- **Actual Result**:
- **Pass/Fail**:
- **Tester**:
- **Test Date**:
- **Notes**:

### UAT-PM-04 — Final approval
- **Preconditions**: An assignment is at status "Pending Program Manager"
  (seeded scenario 6).
- **Steps**: Open the review queue, open the submission, click Approve.
- **Expected Result**: Status becomes "Approved" (terminal); dashboards'
  approved count increases by one.
- **Actual Result**:
- **Pass/Fail**:
- **Tester**:
- **Test Date**:
- **Notes**:

### UAT-PM-05 — Final rejection returns directly to the Employee
- **Preconditions**: An assignment is at status "Pending Program Manager."
- **Steps**: Open the review queue, click Reject, enter a mandatory
  reason.
- **Expected Result**: Status becomes "Returned for Revision"; the
  assignment's next actor is the Employee — **not** the Department
  Manager or Auditor.
- **Actual Result**:
- **Pass/Fail**:
- **Tester**:
- **Test Date**:
- **Notes**:

### UAT-PM-06 — Download and import the Qiyas requirements template
- **Preconditions**: Logged in as `qiyas_admin`.
- **Steps**: 1) Download the template. 2) Add one new standard row. 3)
  Upload it for preview. 4) Review the preview (should show 0 errors, 1 new
  standard). 5) Confirm.
- **Expected Result**: Preview never writes anything to the database; after
  confirm, the new standard appears in the requirements list.
- **Actual Result**:
- **Pass/Fail**:
- **Tester**:
- **Test Date**:
- **Notes**:

---

## Qiyas Auditor

### UAT-AU-01 — Cannot review before Department Manager approval
- **Preconditions**: Logged in as `auditor_1`. An assignment is at status
  "Pending Department Manager" (seeded scenario 4).
- **Steps**: Attempt to approve it from the Auditor review queue.
- **Expected Result**: Rejected — it does not appear in the Auditor's
  queue at all until the Department Manager has approved it.
- **Actual Result**:
- **Pass/Fail**:
- **Tester**:
- **Test Date**:
- **Notes**:

### UAT-AU-02 — Approve after Department Manager approval
- **Preconditions**: An assignment is at status "Pending Auditor" (seeded
  scenario 5).
- **Steps**: Open the Auditor review queue, approve.
- **Expected Result**: Status becomes "Pending Program Manager"; the
  Department Manager's earlier decision is still visible in the timeline,
  unchanged.
- **Actual Result**:
- **Pass/Fail**:
- **Tester**:
- **Test Date**:
- **Notes**:

### UAT-AU-03 — Decide an extension request
- **Preconditions**: A pending extension request exists (seeded scenario
  12).
- **Steps**: Open Auditor → Extension Requests, approve or reject with a
  reason.
- **Expected Result**: On approval, the assignment's effective due date
  updates to the requested date while the original due date is unchanged.
  On rejection, the due date does not change at all. Both the Employee and
  the Department Manager receive a notification.
- **Actual Result**:
- **Pass/Fail**:
- **Tester**:
- **Test Date**:
- **Notes**:

### UAT-AU-04 — Cannot perform final approval
- **Preconditions**: An assignment is at status "Pending Program Manager."
- **Steps**: Attempt to approve it as the Auditor.
- **Expected Result**: Rejected — this stage is not in the Auditor's
  queue.
- **Actual Result**:
- **Pass/Fail**:
- **Tester**:
- **Test Date**:
- **Notes**:

---

## Department Manager

### UAT-DM-01 — Approve a submission from own department
- **Preconditions**: Logged in as `it_manager`. An IT-department
  assignment is at status "Pending Department Manager" (seeded scenario
  4).
- **Steps**: Open the review queue, approve.
- **Expected Result**: Status becomes "Pending Auditor."
- **Actual Result**:
- **Pass/Fail**:
- **Tester**:
- **Test Date**:
- **Notes**:

### UAT-DM-02 — Cannot review another department's submission
- **Preconditions**: Logged in as `it_manager`. An HR-department
  submission is pending Department Manager review.
- **Steps**: Attempt to open/approve the HR submission (including by
  directly navigating to its URL/ID).
- **Expected Result**: 403/404 — no HR submission data, file names, or
  counts are visible to the IT manager.
- **Actual Result**:
- **Pass/Fail**:
- **Tester**:
- **Test Date**:
- **Notes**:

### UAT-DM-03 — Reject with a mandatory reason
- **Preconditions**: An IT-department submission is pending Department
  Manager review.
- **Steps**: Click Reject without entering a reason; then with a reason.
- **Expected Result**: Rejected without a reason; succeeds with one. The
  submission returns directly to the Employee.
- **Actual Result**:
- **Pass/Fail**:
- **Tester**:
- **Test Date**:
- **Notes**:

### UAT-DM-04 — View (but not decide) an extension request
- **Preconditions**: A pending extension request exists for the manager's
  department.
- **Steps**: Open the extension request from the Department Manager view.
- **Expected Result**: Details are visible; there is no approve/reject
  control anywhere on this screen for this role.
- **Actual Result**:
- **Pass/Fail**:
- **Tester**:
- **Test Date**:
- **Notes**:

### UAT-DM-05 — View own department's employee delay information
- **Preconditions**: An employee in the manager's department has an
  overdue assignment (seeded scenario 9).
- **Steps**: Open the Department Manager dashboard.
- **Expected Result**: The delayed employee and requirement appear;
  reviewer-caused delays (if any) are **not** attributed to this employee.
- **Actual Result**:
- **Pass/Fail**:
- **Tester**:
- **Test Date**:
- **Notes**:

---

## Employee

### UAT-EMP-01 — View only own department's authorized assignments
- **Preconditions**: Logged in as `it_employee_1`.
- **Steps**: Open "My Requirements."
- **Expected Result**: Only IT-department assignments appear; no HR/
  Finance/Legal/Operations items are visible, even by direct URL/ID.
- **Actual Result**:
- **Pass/Fail**:
- **Tester**:
- **Test Date**:
- **Notes**:

### UAT-EMP-02 — Upload evidence, save a draft, submit
- **Preconditions**: An assignment with no submission yet exists (seeded
  scenario 1).
- **Steps**: 1) Open the assignment. 2) Upload one file. 3) Confirm it
  shows as a draft (not yet submitted). 4) Click Submit.
- **Expected Result**: Draft is editable and re-editable before submit;
  after submit, status becomes "Pending Department Manager" and the
  submission can no longer be edited by the Employee.
- **Actual Result**:
- **Pass/Fail**:
- **Tester**:
- **Test Date**:
- **Notes**:

### UAT-EMP-03 — Correct and resubmit a returned submission
- **Preconditions**: A submission is at status "Returned for Revision"
  with a visible rejection reason (seeded scenario 7).
- **Steps**: 1) Open the submission and read the rejection reason. 2)
  Upload a corrected file. 3) Submit again.
- **Expected Result**: A **new version** is created (old evidence and
  decision history remain viewable in the timeline); the new version
  starts again at "Pending Department Manager."
- **Actual Result**:
- **Pass/Fail**:
- **Tester**:
- **Test Date**:
- **Notes**:

### UAT-EMP-04 — Request an extension
- **Preconditions**: An active assignment exists.
- **Steps**: Open the assignment, request an extension with a reason and a
  requested date later than the current effective due date.
- **Expected Result**: Request created with status "Pending"; a second
  request cannot be created while one is already pending; the request
  reaches the Auditor, not the Department Manager, for a decision.
- **Actual Result**:
- **Pass/Fail**:
- **Tester**:
- **Test Date**:
- **Notes**:

### UAT-EMP-05 — Cannot approve, reject, assign, or import
- **Preconditions**: Logged in as `it_employee_1`.
- **Steps**: Attempt each of: approving a submission, assigning a
  requirement, importing an XLSX file (via direct API calls, not just
  checking the UI hides these).
- **Expected Result**: All rejected with 403.
- **Actual Result**:
- **Pass/Fail**:
- **Tester**:
- **Test Date**:
- **Notes**:

### UAT-EMP-06 — Bilingual interface
- **Preconditions**: Logged in as `it_employee_1`.
- **Steps**: Switch the interface language between Arabic and English on
  the My Requirements page.
- **Expected Result**: Every visible label, status, and validation message
  changes language; layout correctly switches RTL/LTR; no untranslated
  keys or mixed-language text appear.
- **Actual Result**:
- **Pass/Fail**:
- **Tester**:
- **Test Date**:
- **Notes**:

---

## Cross-cutting scenarios (any role, as noted)

### UAT-X-01 — Concurrent review conflict
- **Preconditions**: Two reviewer accounts with access to the same pending
  submission (e.g. two browser sessions as `auditor_1`).
- **Steps**: Open the same submission in both sessions. Approve it in the
  first. Then attempt to reject it in the second (still showing the old
  state).
- **Expected Result**: The second action is rejected with a clear conflict
  message (not a silent failure or a duplicate decision); only the first
  decision is recorded.
- **Actual Result**:
- **Pass/Fail**:
- **Tester**:
- **Test Date**:
- **Notes**:

### UAT-X-02 — Notification isolation
- **Preconditions**: Two users who both received a notification for the
  same event.
- **Steps**: Mark it read (or delete it) as one user.
- **Expected Result**: The other user's copy is unaffected.
- **Actual Result**:
- **Pass/Fail**:
- **Tester**:
- **Test Date**:
- **Notes**:
