# Government Compliance Management Platform — Backend (Laravel API)

Multi-program government compliance platform. **Qiyas** — DGA digital‑government
compliance (assessment cycles, standards, evidence documents, review/approval
workflow) — is the first Compliance Program hosted on the platform; the
architecture supports adding further programs (Sumoud, ECC, NDMO, ...)
without another major database or authorization redesign. See
[`docs/multi-program-architecture.md`](docs/multi-program-architecture.md).

- **Stack:** Laravel 13 (REST API), JWT auth (tymon/jwt-auth), Spatie RBAC, MySQL 8
- **Frontend:** Vue 3 SPA (separate repo `qiyas_frontend`)
- **API base:** `/api/v1`

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

After `migrate --seed` the platform is **immediately usable and fully populated**
(departments, all roles, an active cycle, sample standards/requirements/documents,
notifications, audit logs). Re‑populate any time with:

```bash
php artisan system:demo-data
```

The 89 real DGA standards load separately into a draft cycle via
`php artisan db:seed --class=StandardsCatalogSeeder`.

---

## Business Workflow

| # | Question | Answer |
|---|----------|--------|
| 1 | Who creates the Qiyas cycle? | **Qiyas Administrator** |
| 2 | Who imports standards? | **Qiyas Administrator** (Excel import) |
| 3 | Who assigns standards to departments? | **Qiyas Administrator** |
| 4 | Who sees assigned standards? | **Employees** — only standards assigned to **their own department** |
| 5 | Who uploads documents? | **Employees** of the assigned department |
| 6 | Where does the employee upload? | From the **My Department Standards** page (inline, no separate module) |
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
| Import standards (Excel) | ✅ | ✅ | — | — | — |
| Add standards | ✅ | ✅ | — | — | — |
| Assign standards to departments | ✅ | ✅ | — | — | — |
| View **all** departments' progress | ✅ | ✅ | ✅ | — | ✅ |
| View **own** department standards | ✅ | ✅ | ✅ (all) | ✅ (own) | aggregate |
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
  or modify another department's standards, documents, comments, statistics, or
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

Feature tests covering role permissions and department data isolation live in
`tests/Feature`. Run them with:

```bash
php artisan test
```
