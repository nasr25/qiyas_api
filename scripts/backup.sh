#!/usr/bin/env bash
#
# Full application backup: MySQL database dump + evidence storage
# (storage/app/private) + branding/public assets (storage/app/public) +
# a checksummed manifest. Does NOT include .env / APP_KEY / any
# encryption key — those require the separate, approved PROTECTED
# backup process (see docs/security/secrets-management.md) and must
# never travel in an ordinary application backup archive.
#
# Usage: scripts/backup.sh [output-directory]
# Requires: mysqldump, tar, shasum on PATH; DB_* and MYSQL_* env vars
# or a working .env in the current directory (read via `php artisan
# config:show` would require booting the app — this script reads .env
# directly to stay dependency-free).
set -euo pipefail
cd "$(git rev-parse --show-toplevel)"

OUT_DIR="${1:-storage/backups}"
TIMESTAMP="$(date +%Y%m%d_%H%M%S)"
WORKDIR="$(mktemp -d)"
trap 'rm -rf "$WORKDIR"' EXIT

# Minimal, dependency-free .env parsing — only the handful of DB_* keys
# needed for mysqldump, never APP_KEY or any secret (see header note).
env_get() {
  grep -E "^${1}=" .env 2>/dev/null | tail -1 | cut -d'=' -f2- | sed -e 's/^"//' -e 's/"$//'
}

DB_HOST="${DB_HOST:-$(env_get DB_HOST)}"
DB_HOST="${DB_HOST:-127.0.0.1}"
DB_DATABASE="${DB_DATABASE:-$(env_get DB_DATABASE)}"
DB_USERNAME="${DB_USERNAME:-$(env_get DB_USERNAME)}"
DB_PASSWORD="${DB_PASSWORD:-$(env_get DB_PASSWORD)}"
DB_SOCKET="${DB_SOCKET:-$(env_get DB_SOCKET)}"

if [[ -z "$DB_DATABASE" ]]; then
  echo "backup.sh: could not determine DB_DATABASE (set it in .env or the environment)." >&2
  exit 1
fi

echo "== Backing up database '${DB_DATABASE}' =="
MYSQLDUMP_ARGS=(-u "$DB_USERNAME" --single-transaction --triggers)
if [[ -n "$DB_PASSWORD" ]]; then
  MYSQLDUMP_ARGS+=(-p"$DB_PASSWORD")
fi
if [[ -n "$DB_SOCKET" ]]; then
  MYSQLDUMP_ARGS+=(-S "$DB_SOCKET")
else
  MYSQLDUMP_ARGS+=(-h "$DB_HOST")
fi
mysqldump "${MYSQLDUMP_ARGS[@]}" "$DB_DATABASE" > "${WORKDIR}/database.sql"

echo "== Archiving evidence storage (storage/app/private) =="
if [[ -d storage/app/private ]]; then
  tar -czf "${WORKDIR}/evidence-storage.tar.gz" -C storage/app private
else
  echo "  (no storage/app/private directory yet — skipped)"
fi

echo "== Archiving branding/public assets (storage/app/public) =="
if [[ -d storage/app/public ]]; then
  tar -czf "${WORKDIR}/public-storage.tar.gz" -C storage/app public
else
  echo "  (no storage/app/public directory yet — skipped)"
fi

mkdir -p "$OUT_DIR"
ARCHIVE="${OUT_DIR}/qiyas-backup-${TIMESTAMP}.tar.gz"
tar -czf "$ARCHIVE" -C "$WORKDIR" .
CHECKSUM_FILE="${ARCHIVE}.sha256"
shasum -a 256 "$ARCHIVE" > "$CHECKSUM_FILE"

cat > "${OUT_DIR}/qiyas-backup-${TIMESTAMP}.manifest.json" <<EOF
{
  "timestamp": "${TIMESTAMP}",
  "database": "${DB_DATABASE}",
  "archive": "$(basename "$ARCHIVE")",
  "archive_size_bytes": $(stat -f%z "$ARCHIVE" 2>/dev/null || stat -c%s "$ARCHIVE"),
  "sha256": "$(cut -d' ' -f1 "$CHECKSUM_FILE")",
  "contents": ["database.sql", "evidence-storage.tar.gz", "public-storage.tar.gz"],
  "excludes_secrets": true,
  "note": "APP_KEY/.env are intentionally excluded — see docs/security/secrets-management.md for the protected backup process."
}
EOF

echo ""
echo "Backup complete: ${ARCHIVE}"
echo "Checksum: $(cat "$CHECKSUM_FILE")"
