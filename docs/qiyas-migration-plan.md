# Qiyas Migration Plan

How existing Qiyas data and users were moved under the new
`ComplianceProgram` layer, how to verify it, and how to roll it back.

## 1. Pre-migration backup

Before running this on any environment with real data:

```bash
# XAMPP MySQL example — adjust for your environment
/Applications/XAMPP/xamppfiles/bin/mysqldump -u root -h 127.0.0.1 qiyas_db > qiyas_db_pre_phase1_backup.sql
```

Restore with `mysql -u root qiyas_db < qiyas_db_pre_phase1_backup.sql` if needed.

## 2. What migrates, and how

Two distinct mechanisms, run in this order:

### 2a. Schema migrations (`php artisan migrate`)

Everything derivable purely from existing foreign keys is backfilled
**inside the migration itself**, in a single transaction per statement, so
`php artisan migrate` alone leaves the database fully consistent:

| Migration | What it does |
|---|---|
| `2026_07_17_000001_create_compliance_programs_table` | Creates `compliance_programs`; inserts the QIYAS row (baseline data, not demo data) |
| `2026_07_17_000002_create_program_user_roles_table` | Creates `program_user_roles` (empty — see §2b) |
| `2026_07_17_000003_add_program_fields_to_assessment_cycles_table` | Adds `compliance_program_id` + ProgramCycle fields to `assessment_cycles`; backfills every existing cycle to QIYAS; makes the column NOT NULL |
| `2026_07_17_000004..000006` | Backfills `compliance_program_id` on `standards`, `documents`, `extension_requests` via their cycle/document FK chain; NOT NULL |
| `2026_07_17_000007` | Backfills `comments.compliance_program_id` for Document-type comments (the only commentable today); stays nullable |
| `2026_07_17_000008` | Best-effort backfill of `audit_logs.compliance_program_id` for the four model types where it's unambiguous (AssessmentCycle/Standard/Document/ExtensionRequest); stays nullable — historical rows with no model or an unmapped type legitimately stay NULL |
| `2026_07_17_000009_rebrand_platform_settings` | Renames the global `branding.platform_name`(`_en`) setting from "Qiyas Platform" to "Government Compliance Management Platform" — conditionally, only if still at the old default (won't clobber a site that already customized it) |

All backfill SQL uses portable `UPDATE ... SET col = (SELECT ...)`
subqueries (not MySQL-specific `UPDATE ... JOIN`), verified against both
MySQL (dev database) and SQLite (test suite).

### 2b. User → program role mapping (`compliance:migrate-qiyas` / `ProgramMembershipSeeder`)

This step depends on users and spatie roles already existing, so it cannot
run inside a schema migration on a fresh install (chicken-and-egg with
seeding). It is:

- `App\Services\ProgramMigrationService::migrateUsersToProgram()` — the
  actual logic, idempotent (checks for an existing row before inserting).
- Invoked automatically by `database/seeders/ProgramMembershipSeeder.php`
  (added to `DatabaseSeeder`'s call list), so `php artisan migrate --seed`
  on a fresh install ends up fully wired.
- Also invocable by hand: `php artisan compliance:migrate-qiyas`
  (`--dry-run` reports counts without writing).

### Role migration matrix

| Existing spatie role | Platform role (unchanged) | New `program_user_roles` row (program = QIYAS) |
|---|---|---|
| `super-admin` | Super Admin — implicit access to all programs | none (not needed) |
| `executive` | Executive Viewer — implicit read-only access to all active programs | none (not needed) |
| `qiyas-admin` | — | `role_key = program-manager` |
| `auditor` | — | `role_key = auditor` |
| `coordinator` | — | `role_key = department-manager`, `department_id = user.department_id` |
| `employee` | — | `role_key = employee`, `department_id = user.department_id` |

No spatie role, permission, or user account is removed or modified by this
process — it is purely additive.

## 3. What is preserved

- **IDs**: every cycle, standard, document, extension request, comment,
  and user keeps its original primary key. Nothing was dropped and
  recreated.
- **Foreign keys**: `standards.cycle_id`, `documents.requirement_id`,
  `documents.department_id`, `documents.cycle_id`,
  `extension_requests.document_id`, etc. are untouched.
- **Uploaded file references**: `document_versions.file_path` and the
  physical files on the `private` disk are untouched — this migration
  never writes to storage.
- **User/department ownership**: `submitted_by`, `reviewed_by`,
  `requested_by`, `department_id` columns are untouched.
- **Status history**: `documents.status`, `extension_requests.status`,
  `document_versions` (full version history) are untouched.
- **Timestamps**: no `created_at`/`updated_at` is rewritten by the
  backfill (only the new `compliance_program_id` column is populated).
- **Auditability**: existing `audit_logs` rows are kept as-is; only a
  best-effort `compliance_program_id` is added where derivable (§2a).

## 4. Verification

```bash
php artisan compliance:verify-migration
```

Read-only, modifies nothing. Reports:

- Programs / Qiyas cycles / domains / categories / requirements /
  assignments / evidence submissions / program user role counts.
- Orphan checks: cycles/standards/documents/extension requests missing
  `compliance_program_id` (should be zero after §2a).
- Dangling FK checks: documents with a deleted department, standards with
  a deleted cycle.
- Users with no role at all (neither a platform role nor a program role).

Exit code is non-zero if any check fails, so it is safe to wire into a
deployment pipeline as a gate.

Result on this database at the time of writing: **all checks passed**
(1 program, 2 cycles, 97 requirements, 24 assignments, 48 evidence
submissions, 15 program-role assignments, zero orphans).

## 5. Rollback

Each new migration has a `down()` that reverses its `up()`:

```bash
# Roll back the 9 Phase 1 migrations, in reverse order
php artisan migrate:rollback --step=9
```

This drops `compliance_programs` and `program_user_roles`, and removes the
added columns from `assessment_cycles`, `standards`, `documents`,
`extension_requests`, `comments`, `audit_logs` — restoring the exact
pre-Phase-1 schema. `2026_07_17_000009`'s `down()` restores the old
"Qiyas Platform" branding text (again, only if it still matches the
Phase-1 value, so it won't clobber a subsequent admin edit).

Rolling back does **not** delete any Qiyas domain data (cycles, standards,
documents, ...) — only the new program-layer columns/tables are removed.
`program_user_roles` rows are lost on rollback (by design — they only
exist because of this migration); re-running
`php artisan compliance:migrate-qiyas` after a forward re-migration
recreates them identically, since the mapping is deterministic from
existing spatie roles.

## 6. Migration order for a fresh environment

```bash
php artisan migrate
php artisan db:seed          # runs ProgramMembershipSeeder automatically
php artisan compliance:verify-migration
```

## 7. Migration order for an existing populated environment (this repo)

```bash
# 1. Backup (see §1)
php artisan migrate --step        # or plain `migrate`; already run here
php artisan db:seed --force       # idempotent — re-seeds nothing destructively
php artisan compliance:verify-migration
```

Both were executed against this project's live development database
during Phase 1 implementation; verification passed with zero orphans.
