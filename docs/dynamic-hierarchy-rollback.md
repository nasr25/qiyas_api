# Dynamic Hierarchy — Rollback

**Status: tested.** Executed against an isolated database, not reasoned
about. Reproduce with:

```bash
bash scripts/rollback-test.sh
```

The script provisions its own `qiyas_rollback_db`, prints PASS/FAIL per
check, and exits non-zero on the first failure. Last run: **all checks
passed** (2026-08-26).

## What the migrations are

Seven migrations make up the dynamic hierarchy change, rolled back newest
first:

1. `2026_08_25_000001_create_hierarchy_definitions_table`
2. `2026_08_25_000002_create_hierarchy_level_definitions_table`
3. `2026_08_25_000003_create_program_structure_versions_table`
4. `2026_08_25_000004_add_dynamic_hierarchy_columns_to_compliance_nodes`
5. `2026_08_25_000005_repoint_workflow_tables_to_compliance_nodes`
6. `2026_08_25_000006_drop_standards_mirror_columns`
7. `2026_08_26_000001_widen_import_log_template_version`

```bash
php artisan migrate:rollback --step=7
```

## Two real bugs this test found

Both were in code that looked correct and passed every functional test.

### 1. Index dropped before its foreign key (MySQL errno 1553)

`down()` dropped `evidence_submissions_node_status_idx` before dropping the
foreign key that depended on it. MySQL refuses:

```
SQLSTATE[HY000]: 1553 Cannot drop index 'evidence_submissions_node_status_idx':
needed in a foreign key constraint
```

Because **MySQL DDL is not transactional**, the rollback did not simply
fail — it stopped *half applied*, with two migrations reverted and five
still in place. Fixed by ordering every `down()` as: drop constraint →
drop index → drop column. The same ordering bug had already appeared in the
forward direction (migration 6) and is now consistent in both.

### 2. `NOT NULL` cannot be restored over post-cutover data

`down()` restored `requirement_id BIGINT UNSIGNED NOT NULL`. Any assignment
created **after** the cutover references a `ComplianceNode` and has no
Standard to point back at, so the column is NULL for those rows:

```
SQLSTATE[22004]: 1138 Invalid use of NULL value
```

There is no correct value to invent. Three options were available — fail the
rollback (leaves the schema half-reverted), fabricate ids (silent data
corruption), or leave the column nullable and say so. The migration now
takes the third: it restores `NOT NULL` **only** when every row still
references a Standard, and otherwise logs a warning naming the row count.

**Consequence, stated plainly:** rolling back a database that already holds
node-based assignments leaves `requirement_id` nullable. The schema is
reverted and the application runs, but the old NOT NULL invariant is not
restored. **A true revert of a database with post-cutover data is a
restore-from-backup**, which step 12 of the test exercises and verifies.

## What the test verifies

| Step | Check |
|---|---|
| 1 | Migrate + seed to head, `mysqldump` backup taken |
| 2 | Pre-rollback state recorded (users, departments, programs, settings, memberships, templates, definitions, levels, versions, nodes) |
| 3 | Assignments / evidence / SLA rows exist to exercise |
| 4 | `compliance:verify-hierarchy` clean **before** rollback |
| 5 | **Partial-failure recovery** — a foreign key is dropped to simulate a halfway failure; `migrate` is re-run and is a safe no-op; constraint restored |
| 6 | `migrate:rollback --step=7` completes with no error |
| 7 | Schema reverted: 3 tables removed, 5 columns removed, mirror columns restored, `compliance_node_id` removed |
| 8 | **Platform data intact** — users, departments, programs, settings, program memberships, email templates all unchanged |
| 9 | No foreign keys reference the dropped tables; no assignment points at a missing standard |
| 10 | Application starts: `artisan` boots, routes resolve, `migrate:status` readable |
| 11 | Migrations **re-apply cleanly** after rollback (roll forward again) |
| 12 | Restore from backup; integrity checks clean afterwards |

Measured on the final re-verification run (2026-08-26, after the test
fixtures were added):

| Preserved object | Before rollback | After rollback |
|---|---|---|
| Users | 63 | **63** |
| Departments | 13 | **13** |
| Programs | 8 | **8** |
| Program memberships (`program_user_roles`) | 71 | **71** |
| Platform settings | 12 | **12** |
| Email templates | 16 | **16** |

Script verdict: **"All rollback checks passed."** Nothing on the user's
preserve-list — users, departments, authentication, roles, permissions,
program memberships, settings, branding, SMTP, notification templates, audit
infrastructure, or the programs themselves — is touched by a rollback.

## Partial-application safety

MySQL applies each DDL statement independently, so any migration touching
several objects can be interrupted mid-way. The measures taken:

- **Forward migrations are idempotent where they can be.** Migration 6
  guards every column drop with `Schema::hasColumn()`, so a re-run after a
  half-applied failure completes instead of erroring on an already-dropped
  object. This was added after a real half-application during development.
- **Constraint-before-index ordering** in both directions (above).
- **Re-running `migrate` is a no-op** for already-recorded migrations —
  verified in step 5.
- **Already-created objects**: `firstOrCreate`/`updateOrCreate` throughout
  the seeders, so re-running them does not duplicate.

## Recovery procedure

1. **Stop the application** (or put it in maintenance mode).
2. **Take a backup first** — `mysqldump --routines --triggers <db>`. Do this
   even if you intend to roll back rather than restore.
3. `php artisan migrate:rollback --step=7`.
4. If it stops part-way: read the error, fix the object it names, then re-run
   the same command. The migrations are written so a retry is safe.
5. Run `php artisan migrate:status` to confirm what actually reverted.
6. If the database held post-cutover data and the NOT NULL invariant matters,
   **restore from the backup** instead of relying on the rollback.
7. Verify with `compliance:verify-hierarchy` and `compliance:verify-cross-program`.

## Not covered

- Rollback under concurrent write load.
- Rollback of a database larger than the rollback-test fixture. (The
  performance fixture reaches 9,336 nodes, but rollback was not re-timed at
  that size — only correctness was verified.)
- Restoring a backup taken from a *different* schema version.
