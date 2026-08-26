# Government Compliance Management Platform — Backend (Laravel API)

**Author:** Nasser

Multi-program government compliance platform hosting four active
Compliance Programs on one **dynamic compliance engine**: **Qiyas** (DGA
digital-government compliance), **Sumoud**, **ECC**, and **NDMO**.

**Hierarchy depth is data, not code.** Each program defines its own
structure — how many levels, what they are called in Arabic and English,
which are assignable, assessable, evidence-bearing, and which appear in
dashboards, reports and filters. Adding a level is inserting a row; it
requires no source-code change and no deployment. The four live programs
run at three different depths (3 / 5 / 5 / 6) through one code path, and
test fixtures prove the same engine at 3, 5 and 7 levels.

`ComplianceNode` is the **single authoritative model** for compliance
content. The legacy `Standard` authoring path was retired — see
[`docs/dynamic-hierarchy-migration-plan.md`](docs/dynamic-hierarchy-migration-plan.md).

Start here:
[`docs/dynamic-compliance-structure.md`](docs/dynamic-compliance-structure.md) ·
[`docs/hierarchy-data-model.md`](docs/hierarchy-data-model.md) ·
[`docs/program-structure-settings.md`](docs/program-structure-settings.md)

- **Stack:** Laravel 13 (REST API), JWT auth (tymon/jwt-auth), Spatie RBAC, MySQL 8
- **Frontend:** Vue 3 SPA + Tailwind CSS (separate repo `qiyas_frontend`) — see that repo's README
- **API base:** `/api/v1`
- **No artificial intelligence functionality of any kind** is present anywhere in this platform — see [`docs/current-repository-cleanup.md`](docs/current-repository-cleanup.md).
- **Offline-first:** the platform runs with zero public-internet access — see [`docs/offline-assets.md`](docs/offline-assets.md).

## Platform administration (Super Admin)

- [`docs/administration/super-admin-guide.md`](docs/administration/super-admin-guide.md) — overview of every Super Admin capability
- [`docs/administration/branding.md`](docs/administration/branding.md) — versioned logo/favicon management
- [`docs/administration/smtp-settings.md`](docs/administration/smtp-settings.md) — encrypted-at-rest SMTP configuration
- [`docs/administration/email-templates.md`](docs/administration/email-templates.md) — notification email template editing
- [`docs/administration/system-settings.md`](docs/administration/system-settings.md) — the full settings catalog

## Operations & deployment

- [`docs/deployment/iis-production.md`](docs/deployment/iis-production.md), [`offline-deployment.md`](docs/deployment/offline-deployment.md), [`release-process.md`](docs/deployment/release-process.md), [`rollback.md`](docs/deployment/rollback.md)
- [`docs/operations/operations-guide.md`](docs/operations/operations-guide.md), [`queue-and-scheduler.md`](docs/operations/queue-and-scheduler.md), [`monitoring.md`](docs/operations/monitoring.md), [`health-checks.md`](docs/operations/health-checks.md), [`troubleshooting.md`](docs/operations/troubleshooting.md)
- [`docs/backup/backup-guide.md`](docs/backup/backup-guide.md), [`restore-guide.md`](docs/backup/restore-guide.md), [`docs/disaster-recovery.md`](docs/disaster-recovery.md)

### Multi-program architecture docs

- [`docs/multi-program-architecture.md`](docs/multi-program-architecture.md) — ComplianceProgram model, generic hierarchy, routing, security model
- [`docs/qiyas-migration-plan.md`](docs/qiyas-migration-plan.md) — how existing Qiyas data was migrated, verification, rollback
- [`docs/roles-and-scopes.md`](docs/roles-and-scopes.md) — platform vs. program vs. department role matrix, Quick Login accounts

```bash
php artisan compliance:migrate-qiyas    # (re)map users onto program_user_roles, idempotent
php artisan compliance:verify-migration # read-only integrity report
```

---

## Quick start

```bash
composer install
cp .env.example .env
php artisan key:generate
php artisan jwt:secret
# set DB_* and (optional) MAIL_* in .env
php artisan migrate --seed       # schema + roles + departments + test users + demo data
php artisan storage:link
php artisan serve
```

After `migrate --seed` the platform is **immediately usable and fully populated**:
departments, all roles, each program's own hierarchy structure, hierarchy
content built from that structure, live assignments and evidence,
notifications and audit logs.

```bash
php artisan system:demo-data              # re-populate platform demo data
php artisan compliance:seed-test-fixtures # 3-, 5-, 7-level test programs (+ TESTX)
```

Program content is authored through the hierarchy screens or imported from
the program's own generated XLSX template — see
[`docs/dynamic-xlsx-engine.md`](docs/dynamic-xlsx-engine.md). There is no
longer a fixed standards catalogue: content shape follows each program's
structure.

### Verifying an installation

```bash
php artisan compliance:verify-hierarchy          # structures, nodes, level semantics
php artisan compliance:verify-program-structure NDMO
php artisan compliance:verify-cross-program      # program isolation
```

---

## Business Workflow (Qiyas example)

> The tables below describe the **Qiyas** program specifically, as a
> concrete example — the underlying engine is generic and the same
> shapes apply to Sumoud/ECC/NDMO with program-specific terminology.
> See `docs/programs/{sumoud,ecc,ndmo}/workflow.md` for the others.

| # | Question | Answer |
|---|----------|--------|
| 1 | Who creates the Qiyas cycle? | **Qiyas Administrator** |
| 2 | Who imports content? | **Program Manager** — XLSX generated from the program's own structure |
| 3 | Who assigns requirements to departments? | **Program Manager**, on levels marked assignable |
| 4 | Who sees assigned requirements? | **Employees** — only assignments for **their own department** |
| 5 | Who uploads documents? | **Employees** of the assigned department |
| 6 | Where does the employee upload? | From **My Requirements**, on levels that accept evidence |
| 7 | What happens after upload? | Document stays **Draft** until submitted |
| 8 | What happens after Submit? | Status becomes **Under Review** |
| 9 | Who reviews documents? | **Auditor** |
| 10 | Who approves documents? | **Auditor** |
| 11 | Who rejects documents? | **Auditor**, with a **mandatory rejection reason** |
| 12 | What happens after rejection? | Employees of the same department **update and resubmit** |
| 13 | Who approves extension requests? | **Auditor** |
| 14 | What does the Executive Viewer do? | View **dashboards and reports only** (read‑only) |
| 15 | What does the Super Admin do? | Technical administration: users, roles, permissions, settings, branding, email, audit logs |

**Document lifecycle:** `Draft → (submit) → Under Review → (auditor) → Approved | Rejected`.
Rejected documents are editable and can be resubmitted by the same department.

**Extension lifecycle:** Employee requests (new date + reason) → `Pending` →
Auditor `Approve` / `Reject` (with reason).

---

## Role Permission Summary

| Capability | Super Admin | Qiyas Admin | Auditor | Employee | Executive |
|------------|:-----------:|:-----------:|:-------:|:--------:|:---------:|
| Manage users / roles / settings / branding / audit logs | ✅ | — | — | — | — |
| Create / activate / close cycle | ✅ | ✅ | — | — | — |
| Import content (XLSX) | ✅ | ✅ | — | — | — |
| Author hierarchy content | ✅ | ✅ | — | — | — |
| Assign requirements to departments | ✅ | ✅ | — | — | — |
| View **all** departments' progress | ✅ | ✅ | ✅ | — | ✅ |
| View **own** department assignments | ✅ | ✅ | ✅ (all) | ✅ (own) | aggregate |
| Upload / edit (draft·rejected) / submit documents | ✅ | — | — | ✅ (own dept) | — |
| Approve / reject documents (+ reason) | ✅ | — | ✅ | — | — |
| Approve / reject extension requests | ✅ | — | ✅ | — | — |
| Request extension | ✅ | — | — | ✅ | — |
| Add comments | ✅ | — | ✅ | ✅ (own dept) | — |
| View reports | ✅ | ✅ | ✅ | — | ✅ |
| Create / update / delete / upload / approve | ✅ | partial | review only | own dept | ❌ (read‑only) |

> The **Department Coordinator** role still exists in the DB for backward
> compatibility but is removed from the main workflow (treated like Employee).

---

## Testing Users (dev quick‑login)

All test accounts use password **`Password123!`**. On the login page, when
`APP_ENV=local` or `APP_DEBUG=true`, a **Quick Login** panel shows a button per
user (name · role · department) and logs in with no password. The panel and the
`/auth/quick-login` endpoint are **disabled in production**.

| Username | Role | Department |
|----------|------|------------|
| `superadmin` | Super Admin | — |
| `qiyas_admin` | Qiyas Administrator | — |
| `auditor_1`, `auditor_2` | Auditor | — |
| `executive_viewer` | Executive Viewer | — |
| `it_employee_1`, `it_employee_2` | Employee | Information Technology |
| `hr_employee_1`, `hr_employee_2` | Employee | Human Resources |
| `finance_employee_1`, `finance_employee_2` | Employee | Finance |
| `legal_employee_1`, `legal_employee_2` | Employee | Legal |
| `operations_employee_1`, `operations_employee_2` | Employee | Operations |

---

## Data Access Rules

Authorization is enforced on the **backend**, not just hidden in the UI:

- **Employees** are hard‑scoped to their own `department_id`. They cannot read
  or modify another department's assignments, evidence, comments, statistics, or
  files — even by changing an id in the URL (the API returns **403**). Endpoints
  filter by the authenticated user's department regardless of client input.
- **Auditors** can read/review documents across **all** departments.
- **Qiyas Administrator** can manage all standards and view assignment progress.
- **Executive Viewer** has **read‑only** access to aggregated dashboards/reports.
- **Super Admin** can access all data (bypasses role checks via `Gate::before`).

### Audit log

Every significant action is recorded in `audit_logs` with: user id, **role**,
**department id**, action, entity type/id, old/new values, IP address, user
agent, and timestamp. Logged actions include: login, **quick login**, logout,
user/role/permission/settings changes, standard import & **assignment**,
document upload/edit/submit/approve/reject/download, extension
request/approve/reject, and comment creation.

---

## Tests

Backend feature/unit tests (PHPUnit) live in `tests/Feature` and
`tests/Unit`:

```bash
php artisan test
```

Frontend end-to-end tests (Playwright) live in the `frontend` repo at
`tests/e2e/` and require an isolated E2E environment — see
[`docs/testing/playwright-guide.md`](docs/testing/playwright-guide.md).

Load testing uses k6 (never Playwright) — see
[`docs/testing/load-testing.md`](docs/testing/load-testing.md).

## CI quality gate

`scripts/scan-prohibited-references.sh` deterministically scans every
currently tracked file for references to automated code-generation
tools and fails the build if any unreviewed match is found — see
[`docs/current-repository-cleanup.md`](docs/current-repository-cleanup.md).
Run it manually — `bash scripts/scan-prohibited-references.sh` — alongside
`composer audit` and the full test suite. It ran automatically on every push
until the GitHub Actions pipeline was retired; nothing invokes it for you now.
