# Qiyas — Backup and Recovery

**No backup was actually taken and restored in this environment** — this is
a documented procedure to follow and rehearse before production, not a
verified-working drill. See `docs/qiyas-known-issues.md`.

## What needs backing up

| Item | Location | Why |
|---|---|---|
| MySQL database | the `qiyas_db` schema (or your production DB name) | Every workflow record, decision, SLA instance, notification log, email template, program setting. |
| Evidence files | `storage/app/private/evidence/{program}/{assignment}/{submission}/` | Referenced by `EvidenceFile.storage_path` — losing these without losing the DB rows leaves broken, unrecoverable references. |
| Imported XLSX files (retained) | `storage/app/private/imports/{program}/` | Referenced by `ImportLog.stored_file_name`; kept so a completed import can be re-audited against the exact file that produced it. |
| Import error reports | Generated on demand from `ImportLog.validation_report_path` (JSON), not pre-generated files — the JSON error data itself is the durable artifact, covered by the database backup. |
| `.env` (environment configuration) | Repository root on the server, outside `public/` | SMTP/DB/LDAP credentials, `APP_KEY`. **Encrypt this backup separately** — it contains secrets the database backup does not. |
| Email templates | In the database (`email_templates` table) | Covered by the database backup — no separate file backup needed. |
| Program settings | In the database (`settings` table, and `sla_settings`) | Covered by the database backup. |

The database and evidence-file backups **must be taken together, on the
same schedule**, and restored together — a database restored from Tuesday
paired with evidence files restored from Wednesday would leave `EvidenceFile`
rows pointing at files that don't exist yet (or vice versa, orphaned files
with no matching row). `compliance:verify-qiyas`'s "evidence files without a
parent submission" check would catch the file-without-row case after a
mismatched restore, but not the reverse (a row whose file went missing) —
so consistency has to be enforced by backup process discipline, not
detected after the fact.

## Backup commands

**Database** (MySQL, run from a host with `mysqldump` access — matches the
same client used elsewhere in this project, e.g.
`/Applications/XAMPP/xamppfiles/bin/mysqldump` in local dev, the
equivalent MySQL client path in production):

```bash
mysqldump --single-transaction --routines --triggers \
  -u <backup_user> -p qiyas_db > qiyas_db_$(date +%Y%m%d_%H%M%S).sql
```

`--single-transaction` avoids locking live tables during the dump (safe for
InnoDB, which every table in this schema uses).

**Evidence files and retained imports** (Windows Server, PowerShell):

```powershell
Compress-Archive -Path "C:\inetpub\wwwroot\qiyas-api\storage\app\private" `
  -DestinationPath "D:\backups\qiyas-storage_$(Get-Date -Format yyyyMMdd_HHmmss).zip"
```

**`.env`**: copy to an encrypted backup location separately (e.g. a
password-protected archive or a secrets vault) — never alongside the plain
database/storage backups.

## Frequency and retention

These are recommendations, not enforced by the platform — configure via
your organization's actual backup tooling (Windows Server Backup, a SQL
Server Agent-style scheduled task calling the commands above, or equivalent):

- **Database**: daily full backup, retained 30 days; consider hourly
  incremental/binlog-based backups if the recovery-point objective below
  needs to be tighter than "up to 24 hours of data loss."
- **Evidence files**: daily, retained 30 days (aligned with the database
  schedule — see the consistency note above).
- **`.env`**: on every change, retained per your organization's secrets
  rotation policy.
- **Audit logs specifically**: covered by the database backup; do not
  separately purge `audit_logs` on a schedule shorter than your compliance
  retention requirement — see `docs/qiyas-known-issues.md` and
  `docs/qiyas-role-permissions.md` for what the audit log is relied upon
  for.

## Recovery Point Objective / Recovery Time Objective (recommended)

- **RPO**: 24 hours with daily backups as configured above (i.e., up to one
  day of workflow activity could be lost in a worst-case disaster). If this
  is not acceptable, move to more frequent database backups (hourly
  incremental) — evaluate against actual submission volume once real usage
  data exists.
- **RTO**: target under 4 hours for a full restore (database + storage +
  redeploy code + verify), assuming backups are readily accessible
  (on-site or fast-retrieval off-site storage, not cold archival tape).

These are starting recommendations for an internal government compliance
platform, not contractually validated numbers — adjust to your
organization's actual disaster-recovery requirements.

## Restore procedure

1. Provision/restore the target server (or confirm the existing one is
   available).
2. Restore `.env` from its encrypted backup.
3. Restore the database:
   ```bash
   mysql -u <user> -p qiyas_db < qiyas_db_YYYYMMDD_HHMMSS.sql
   ```
4. Restore `storage/app/private/` from the matching-date storage archive.
5. `composer install --no-dev --optimize-autoloader` (rebuild vendor/ —
   never restore `vendor/` from a backup archive; always reinstall from
   `composer.lock`).
6. `php artisan config:clear && php artisan config:cache`
7. **Run the restore-validation checklist below before declaring the
   restore successful.**

## Restore validation checklist (run every time, not only in a real
disaster — this is also how you rehearse the procedure)

- [ ] `php artisan migrate:status` shows all migrations as `Ran`.
- [ ] `php artisan compliance:verify-migration` passes (Phase 1 structural
      integrity).
- [ ] `php artisan compliance:verify-qiyas` passes (Phase 2 workflow
      integrity) — this is also exactly the check that would catch a
      database/storage restore-date mismatch (orphaned evidence files or
      dangling references).
- [ ] Spot-check: open one known evidence submission from before the
      backup date and confirm its evidence file downloads correctly (proves
      DB↔storage consistency, not just that both restored without error).
- [ ] `GET /api/v1/admin/health` reports all components `ok`.
- [ ] Log in as a known test account and confirm the login itself works
      (proves `APP_KEY`/JWT secret restored correctly — a mismatched
      `APP_KEY` between backup and restore would invalidate all existing
      sessions/tokens, which is expected, but should be a known
      consequence, not a surprise during a real incident).

## Rollback plan (bad deployment, not a disaster)

For a bad code deployment rather than data loss: redeploy the previous
release's code (keep at least the last 2-3 release folders available, not
just the current one) and, only if the bad deployment included a migration,
`php artisan migrate:rollback --step=N` for exactly the migrations that
release introduced — never a blanket rollback that could revert
unrelated, already-stable schema changes. Confirm with
`php artisan compliance:verify-qiyas` afterward either way.
