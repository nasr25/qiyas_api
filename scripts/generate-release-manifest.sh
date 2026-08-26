#!/usr/bin/env bash
#
# Generates a release manifest for a deployment artifact: version, Git
# commit hash, build timestamp, author, dependency hashes, the pending
# migration list, and a checksum of the artifact itself. See
# docs/deployment/release-process.md.
#
# Usage: scripts/generate-release-manifest.sh <release-version> [artifact-path]
set -euo pipefail
cd "$(git rev-parse --show-toplevel)"

RELEASE_VERSION="${1:?Usage: scripts/generate-release-manifest.sh <release-version> [artifact-path]}"
ARTIFACT_PATH="${2:-}"

COMMIT_HASH="$(git rev-parse HEAD)"
BUILD_TIMESTAMP="$(date -u +%Y-%m-%dT%H:%M:%SZ)"
COMPOSER_LOCK_HASH="$(shasum -a 256 composer.lock | cut -d' ' -f1)"

# Migrations present in this release that a fresh/target database has not
# necessarily run yet — informational only; the actual pending set
# depends on the target environment's current migration table.
MIGRATION_LIST=$(find database/migrations -maxdepth 1 -name '*.php' -exec basename {} \; | sort)
MIGRATION_JSON=$(printf '%s\n' "$MIGRATION_LIST" | awk 'NF' | sed 's/.*/"&"/' | paste -sd, -)

ARTIFACT_CHECKSUM="null"
ARTIFACT_SIZE="null"
if [[ -n "$ARTIFACT_PATH" && -f "$ARTIFACT_PATH" ]]; then
  ARTIFACT_CHECKSUM="\"$(shasum -a 256 "$ARTIFACT_PATH" | cut -d' ' -f1)\""
  ARTIFACT_SIZE="$(stat -f%z "$ARTIFACT_PATH" 2>/dev/null || stat -c%s "$ARTIFACT_PATH")"
fi

OUT_FILE="release-manifest-${RELEASE_VERSION}.json"
cat > "$OUT_FILE" <<EOF
{
  "release_version": "${RELEASE_VERSION}",
  "git_commit_hash": "${COMMIT_HASH}",
  "build_timestamp": "${BUILD_TIMESTAMP}",
  "author": "Nasser",
  "backend_dependency_hash": "${COMPOSER_LOCK_HASH}",
  "database_migrations": [${MIGRATION_JSON}],
  "artifact_checksum_sha256": ${ARTIFACT_CHECKSUM},
  "artifact_size_bytes": ${ARTIFACT_SIZE},
  "offline_asset_verification": "see docs/offline-assets.md — run scripts/scan-prohibited-references.sh and the frontend CDN-hostname check before release",
  "configuration_changes": "record manually per docs/deployment/release-process.md",
  "manual_steps_required": "record manually per docs/deployment/release-process.md",
  "queue_restart_required": true,
  "scheduler_changes": "record manually per docs/deployment/release-process.md",
  "rollback_compatible_with": "record the previous release_version this can roll back to",
  "known_issues": "see the readiness report's unresolved-issues section"
}
EOF

echo "Release manifest written to ${OUT_FILE}"
cat "$OUT_FILE"
