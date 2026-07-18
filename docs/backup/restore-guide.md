# Restore Guide

**Author:** Nasser

## Running a restore

```bash
scripts/restore.sh <archive.tar.gz> <target-db-name> [target-storage-dir]
```

- Verifies the archive's checksum against its `.sha256` file first, if
  present.
- **Always restores into an isolated target** — the target database
  name is a required argument, and the script refuses to run if that
  name contains `prod`, `production`, or `live` (case-insensitive).
  There is no "restore over the live database" default; an operator
  must explicitly and separately handle a real production restore
  outside this script's safety guard, with the additional manual
  precautions that implies.
- Creates the target database fresh (`DROP DATABASE IF EXISTS` +
  `CREATE DATABASE`), restores the SQL dump into it, then extracts the
  evidence and public/branding storage archives into the given target
  storage directory.

## Post-restore verification checklist

Run these against the **restored, isolated** target before trusting
it — never skip straight to declaring a restore successful:

- [ ] Row counts for key tables match the source (users, branding
      assets, SMTP settings, email templates, audit logs,
      requirement assignments, or whatever set is relevant to the
      backup being verified).
- [ ] A known account (e.g. the super-admin user) exists and its
      password hash round-tripped correctly.
- [ ] If an SMTP password was configured at backup time, it
      **decrypts successfully** in the restored database — this
      specifically proves the encrypted secret survived the
      backup/restore cycle intact (encryption is keyed off `APP_KEY`,
      which is unrelated to the backup archive — the same running
      application's `APP_KEY` decrypts it either way, since `APP_KEY`
      is never part of the backup).
- [ ] The active branding asset per type still resolves to a real,
      restored file on disk.
- [ ] A recent audit log entry is present and its content is intact.
- [ ] Program cycles, requirements, assignments, evidence metadata,
      and evidence file downloads work end-to-end for a spot-checked
      record.
- [ ] Review decisions, SLA state, notifications, content versions,
      and system settings are present as expected.
- [ ] Login works against the restored database (for a real
      environment restore — not required for a throwaway drill DB).

## This phase's actual drill (real, executed, not simulated)

1. **Backup**: `scripts/backup.sh` against the dev database
   (`qiyas_db`) — archive `qiyas-backup-20260718_134115.tar.gz`,
   565,527 bytes, SHA-256
   `586cf8930eb39452794c6d8550475d8ba37b5cf1b6229eed002678a889f415f0`,
   checksum-verified.
2. **Restore**: `scripts/restore.sh` into a fresh, isolated database
   `qiyas_restore_drill_db` and a throwaway storage path
   (`/tmp/restore-drill-storage`) — completed in **~1.6 seconds** for
   the database restore.
3. **Row-count verification**: `users` (43), `branding_assets` (1),
   `smtp_settings` (1), `email_templates` (16), `audit_logs` (151),
   `requirement_assignments` (13) — **every count matched the source
   exactly.**
4. **Functional verification** (via `php artisan tinker` against
   `DB_DATABASE=qiyas_restore_drill_db`):
   - Super-admin user exists: **YES**.
   - SMTP password decrypts successfully: **YES** — proving the
     encrypted secret round-trips through backup/restore correctly.
   - Active branding asset resolves: **YES** (`logo_primary v1`).
   - Latest audit log entry intact: **YES**
     (`smtp_settings.password_configured`).
5. **Storage verification**: branding asset files present at the
   restored storage path (`/tmp/restore-drill-storage/public/
   branding/`), file listing confirmed non-empty and matching expected
   filenames.
6. **Safety-guard verification**: re-ran `scripts/restore.sh` with a
   target database name containing `production` — the script correctly
   refused and exited non-zero without touching anything.
7. **Cleanup**: the drill database and throwaway storage path were
   dropped/deleted after verification.

## Scale caveat

This drill's dataset (43 users, 151 audit entries) is far smaller than
a real production environment's expected volume. Restore duration and
disk-space requirements should be re-measured against a production-
scale dataset before relying on the RTO target in
`docs/backup/backup-guide.md`.

## What was not drilled

A restore of `.env`/`APP_KEY` through the separate protected process
(no such vault exists in this environment — see
`docs/security/secrets-management.md`), and a restore onto a
genuinely separate physical/virtual environment (this drill restored
into a different database name and storage path on the **same** MySQL
server and filesystem, not a distinct target host).
