# Rollback Procedure

**Author:** Nasser

**This procedure is documented and its individual pieces (database
restore, evidence/branding storage restore) were tested in isolation
this phase; the procedure as a whole has not been rehearsed as a
single end-to-end rollback against a real staged deployment**, since
no DEV/TEST server exists in this environment. Treat it as reviewed
guidance, not a field-proven runbook, until it has been run once for
real.

## What a rollback must restore

Application files, frontend assets, database migrations, queue
workers, the scheduler, branding settings, SMTP settings, email
templates, and system settings — i.e. everything a deployment could
have changed.

## Before any deployment (so a rollback is possible)

1. Create a database backup: `scripts/backup.sh` (includes evidence
   and branding storage; explicitly excludes `.env`/`APP_KEY` — see
   `docs/backup/backup-guide.md`).
2. Create a configuration backup through the approved protected
   process (`.env`/`APP_KEY` — see `docs/security/secrets-management.md`).
3. Record the active branding version (per asset type — see
   `docs/administration/branding.md`).
4. Record the effective SMTP configuration **status** (enabled/
   disabled, host, port — never the secret) via `GET
   /api/v1/admin/smtp-settings`.
5. Record the current release version (the previous release manifest).
6. Verify the previous release's artifact and its checksum file are
   still available.

## Rollback steps

1. Stop the queue worker service and the scheduler task.
2. Restore application files and frontend assets from the previous
   release's artifact (never rebuild on the server — the same
   immutable-artifact principle as a forward deployment).
3. **Database migrations**: never automatically reverse a destructive
   migration (a `down()` that drops a column/table can lose data added
   since the migration ran) without a tested-safe strategy. If the
   new release added purely additive migrations (new tables/nullable
   columns), rolling back application code while leaving those
   migrations in place is usually safe and preferred over running
   `migrate:rollback`. If a migration was destructive, restore the
   database from the pre-deployment backup instead of attempting a
   programmatic rollback.
4. Restore branding/SMTP/email-template/system settings to their
   recorded pre-deployment state, if the rolled-back release's code
   can no longer read the new release's settings shape (e.g. a new
   settings table introduced by the release being rolled back).
5. Restart the queue worker service; re-enable the scheduler task.
6. Run the post-rollback verification checklist below.

## Post-rollback verification checklist

Login (a real account, not just the health endpoint), program
selection, a representative workflow action, evidence access,
branding renders correctly, SMTP configuration status matches what was
recorded, queue workers are running, the scheduler task is active, and
`/up` + `GET /api/v1/admin/health` both report healthy.

## Rollback compatibility

The release manifest (`scripts/generate-release-manifest.sh`) has a
`rollback_compatible_with` field — record which prior release a given
release can safely roll back to; a release whose migrations are not
purely additive should record that it is **not** safely rollback-
compatible without a full database restore.
