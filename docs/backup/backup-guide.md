# Backup Guide

**Author:** Nasser

## What is backed up

`scripts/backup.sh` produces a single checksummed archive containing:

1. **Database dump** (`mysqldump --single-transaction --triggers`) —
   a consistent snapshot without locking the database for the
   duration of the dump.
2. **Evidence storage** (`storage/app/private`) — evidence files,
   XLSX imports, and their error reports.
3. **Public/branding storage** (`storage/app/public`) — versioned
   branding assets (logos/favicons).

## What is deliberately excluded

**`.env` and `APP_KEY` are never included.** Backing up an encryption
key alongside the data it protects, in an ordinary unprotected
archive, defeats the purpose of encrypting the SMTP password at rest
in the first place. If `.env`/`APP_KEY` need to be recoverable for
disaster recovery, that must go through a separate, access-controlled
protected process — see `docs/security/secrets-management.md`. This
script's manifest explicitly records `"excludes_secrets": true` so
this is never ambiguous to whoever runs a restore later.

## Running a backup

```bash
scripts/backup.sh [output-directory]   # defaults to storage/backups
```

Reads `DB_HOST`/`DB_DATABASE`/`DB_USERNAME`/`DB_PASSWORD`/`DB_SOCKET`
from the environment or falls back to parsing `.env` directly for
those specific keys (never `APP_KEY` or any other secret). Produces
three files per run:

- `qiyas-backup-<timestamp>.tar.gz` — the archive itself.
- `qiyas-backup-<timestamp>.tar.gz.sha256` — its checksum.
- `qiyas-backup-<timestamp>.manifest.json` — timestamp, database name,
  archive name/size/checksum, contents list, and the
  `excludes_secrets` flag.

`storage/backups/` is git-ignored — a real backup archive (containing
real user data and, if the target database has one configured, the
SMTP password's ciphertext) must never be committed to version
control.

## Recommended schedule

Daily database + storage backup, retained 30 days, matching the
earlier Phase-3 recommendation (`docs/qiyas-backup-and-recovery.md`).
This is a **recommendation carried forward, not an automated
schedule** — no cron/Task Scheduler entry for `scripts/backup.sh` is
configured by this repository; wiring it into the target
environment's own scheduled-task mechanism is a deployment-time step.

## Recovery objectives

RPO (recovery point objective) ≤ 24 hours with a daily backup
schedule; RTO (recovery time objective) target < 4 hours — carried
forward from the Phase-3 recommendation. The actual measured restore
duration from this phase's drill (a much smaller dataset than a real
production environment) was under 2 seconds for the database restore
alone — see `docs/backup/restore-guide.md` for the full drill record;
treat the RTO target as a goal to validate against a production-scale
dataset, not as something this phase's small-scale drill proves.

## What this phase actually verified

A real backup was taken against the dev database (`qiyas_db`, 43
users, 151 audit log entries, 16 email templates, 1 branding asset, 1
SMTP settings row), producing a 565,527-byte archive, checksum-verified.
See `docs/backup/restore-guide.md` for the restore half of the drill.
