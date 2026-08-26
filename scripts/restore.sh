#!/usr/bin/env bash
#
# Restores a backup created by scripts/backup.sh into a TARGET database
# and directory tree. Always restores into an isolated target — never
# defaults to overwriting the live database — the target database name
# is a required argument, and this script refuses to run against a name
# that looks like a production database.
#
# Usage: scripts/restore.sh <archive.tar.gz> <target-db-name> [target-storage-dir]
set -euo pipefail
cd "$(git rev-parse --show-toplevel)"

ARCHIVE="${1:?Usage: scripts/restore.sh <archive.tar.gz> <target-db-name> [target-storage-dir]}"
TARGET_DB="${2:?Usage: scripts/restore.sh <archive.tar.gz> <target-db-name> [target-storage-dir]}"
TARGET_STORAGE="${3:-storage/app}"

TARGET_DB_LOWER="$(printf '%s' "$TARGET_DB" | tr '[:upper:]' '[:lower:]')"
for marker in prod production live; do
  if [[ "$TARGET_DB_LOWER" == *"$marker"* ]]; then
    echo "restore.sh: refusing to restore into a database whose name contains \"$marker\" — restore into an isolated database explicitly." >&2
    exit 1
  fi
done

if [[ -n "${1:-}" ]] && [[ -f "${ARCHIVE}.sha256" ]]; then
  echo "== Verifying archive checksum =="
  shasum -a 256 -c "${ARCHIVE}.sha256"
fi

WORKDIR="$(mktemp -d)"
trap 'rm -rf "$WORKDIR"' EXIT
tar -xzf "$ARCHIVE" -C "$WORKDIR"

DB_HOST="${DB_HOST:-127.0.0.1}"
DB_USERNAME="${DB_USERNAME:-root}"
DB_PASSWORD="${DB_PASSWORD:-}"
DB_SOCKET="${DB_SOCKET:-}"

MYSQL_ARGS=(-u "$DB_USERNAME")
if [[ -n "$DB_PASSWORD" ]]; then
  MYSQL_ARGS+=(-p"$DB_PASSWORD")
fi
if [[ -n "$DB_SOCKET" ]]; then
  MYSQL_ARGS+=(-S "$DB_SOCKET")
else
  MYSQL_ARGS+=(-h "$DB_HOST")
fi

echo "== Creating/replacing target database '${TARGET_DB}' =="
mysql "${MYSQL_ARGS[@]}" -e "DROP DATABASE IF EXISTS \`${TARGET_DB}\`; CREATE DATABASE \`${TARGET_DB}\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"

echo "== Restoring database dump =="
mysql "${MYSQL_ARGS[@]}" "$TARGET_DB" < "${WORKDIR}/database.sql"

if [[ -f "${WORKDIR}/evidence-storage.tar.gz" ]]; then
  echo "== Restoring evidence storage to ${TARGET_STORAGE} =="
  mkdir -p "$TARGET_STORAGE"
  tar -xzf "${WORKDIR}/evidence-storage.tar.gz" -C "$TARGET_STORAGE"
fi

if [[ -f "${WORKDIR}/public-storage.tar.gz" ]]; then
  echo "== Restoring public/branding storage to ${TARGET_STORAGE} =="
  mkdir -p "$TARGET_STORAGE"
  tar -xzf "${WORKDIR}/public-storage.tar.gz" -C "$TARGET_STORAGE"
fi

echo ""
echo "Restore complete into database '${TARGET_DB}' and storage path '${TARGET_STORAGE}'."
echo "Run the post-restore checklist next — see docs/backup/restore-guide.md."
