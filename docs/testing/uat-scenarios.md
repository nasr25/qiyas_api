# UAT Scenarios — Phase 8 additions

**Author:** Nasser

New scenarios covering surfaces introduced or hardened in Phase 8, in
the same format as `docs/uat/qiyas-uat-en.md` (test case ID format
`UAT-<ROLE>-<NN>`). This document ships with Actual Result/Pass-Fail/
Tester/Test Date fields blank by design — see
`docs/testing/uat-plan.md`.

---

## Super Admin — Branding

### UAT-SA-B01 — Upload and activate a new logo
- **Preconditions**: Logged in as `superadmin`. A valid PNG file ready to upload.
- **Steps**: 1) Navigate to Settings → Branding. 2) Choose a file for "Header Logo." 3) Confirm it appears as a pending, not-yet-live draft. 4) Click "Save" on the draft. 5) Check the sidebar/header logo.
- **Expected Result**: The upload does not go live immediately (step 3 shows a clearly marked pending state); after Save, the header logo updates without a page reload or manual cache clear.
- **Actual Result**:
- **Pass/Fail**:
- **Tester**:
- **Test Date**:
- **Notes**:

### UAT-SA-B02 — Reject an unsafe SVG upload
- **Preconditions**: Logged in as `superadmin`. An SVG file containing a `<script>` tag.
- **Steps**: 1) Attempt to upload it as any logo type.
- **Expected Result**: The file is either rejected with a clear error, or accepted only after the script content is stripped — never stored or displayed with the script intact.
- **Actual Result**:
- **Pass/Fail**:
- **Tester**:
- **Test Date**:
- **Notes**:

### UAT-SA-B03 — Restore a previous logo version
- **Preconditions**: A logo type with at least two uploaded/activated versions.
- **Steps**: 1) Open "Version history" for that type. 2) Click "Restore" on a superseded version.
- **Expected Result**: The restored version becomes active again; no version is ever deleted from history.
- **Actual Result**:
- **Pass/Fail**:
- **Tester**:
- **Test Date**:
- **Notes**:

### UAT-SA-B04 — Non-Super-Admin cannot manage branding
- **Preconditions**: Logged in as any non-Super-Admin role.
- **Steps**: 1) Attempt to navigate to Settings.
- **Expected Result**: Redirected away — no branding controls are reachable.
- **Actual Result**:
- **Pass/Fail**:
- **Tester**:
- **Test Date**:
- **Notes**:

---

## Super Admin — SMTP

### UAT-SA-S01 — Configure SMTP and verify the password is never shown again
- **Preconditions**: Logged in as `superadmin`. Real (or a test) SMTP relay details.
- **Steps**: 1) Navigate to Settings → Email Settings. 2) Enter host/port/username/password, save. 3) Reload the page.
- **Expected Result**: After reload, the password field is empty and shows only a "configured" status with a last-changed date — the real password is never displayed.
- **Actual Result**:
- **Pass/Fail**:
- **Tester**:
- **Test Date**:
- **Notes**:

### UAT-SA-S02 — Test Connection before saving
- **Preconditions**: Logged in as `superadmin`.
- **Steps**: 1) Enter SMTP details without saving. 2) Click "Test Connection."
- **Expected Result**: A clear success or failure message appears; on failure, no server banner, certificate detail, or credential is shown.
- **Actual Result**:
- **Pass/Fail**:
- **Tester**:
- **Test Date**:
- **Notes**:

### UAT-SA-S03 — Changing an unrelated field does not clear the password
- **Preconditions**: SMTP already configured with a working password.
- **Steps**: 1) Change only the "From Name" field. 2) Leave the password field blank. 3) Save. 4) Use "Test Connection."
- **Expected Result**: The connection test still succeeds using the previously saved password.
- **Actual Result**:
- **Pass/Fail**:
- **Tester**:
- **Test Date**:
- **Notes**:

---

## Offline use

### UAT-X-01 — Full application walkthrough with no internet access
- **Preconditions**: A network configuration that blocks all outbound traffic except to the application's own server.
- **Steps**: Log in, select a program, view the dashboard, open a requirement, view reports, check notifications, and (as Super Admin) open Branding/SMTP/Email Templates.
- **Expected Result**: Every page renders correctly — fonts, icons, charts, and styling all load — with no broken assets and no browser console errors about a failed/blocked request.
- **Actual Result**:
- **Pass/Fail**:
- **Tester**:
- **Test Date**:
- **Notes**:

---

## Multi-program / multi-role

### UAT-X-02 — A user with roles in two programs sees only their authorized data in each
- **Preconditions**: A test account with, e.g., Program Manager in Qiyas and Auditor in Sumoud.
- **Steps**: 1) Log in. 2) Open Qiyas — confirm Program Manager capabilities. 3) Switch to Sumoud — confirm only Auditor capabilities, no Program Manager controls.
- **Expected Result**: Capabilities are correctly scoped per program for the same logged-in user, with no bleed-through in either direction.
- **Actual Result**:
- **Pass/Fail**:
- **Tester**:
- **Test Date**:
- **Notes**:

---

## Backup-restored-environment smoke test

### UAT-X-03 — Core functionality after a database/storage restore
- **Preconditions**: A restored environment per `docs/backup/restore-guide.md`.
- **Steps**: Log in as a real account, open a program, view an evidence file, check Branding renders correctly, check the SMTP "configured" status matches what was expected, check the audit log is present.
- **Expected Result**: All of the above work correctly against the restored environment, with no data corruption or missing records.
- **Actual Result**:
- **Pass/Fail**:
- **Tester**:
- **Test Date**:
- **Notes**:

---

## Scope note

These scenarios cover the platform-wide (Super Admin, offline,
multi-program/multi-role, restore-verification) surfaces new to Phase
8. Program-specific workflow UAT (the day-to-day Program Manager/
Auditor/Department Manager/Employee scenarios) remains in
`docs/uat/qiyas-uat-en.md`/`-ar.md` for Qiyas; equivalent per-program
sets for Sumoud/ECC/NDMO are a documented gap — see
`docs/testing/uat-plan.md`.
