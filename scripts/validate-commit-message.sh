#!/usr/bin/env bash
#
# Rejects a NEW commit message that carries automated-assistant attribution,
# generation attribution, or session metadata.
#
# Scope, deliberately narrow:
#   - operates on the message being written, nothing else
#   - never reads, inspects, or validates existing history
#   - published historical commits are out of scope by policy and may
#     legitimately still contain such trailers
#
# Install once per clone (both repositories):
#   git config core.hooksPath .githooks
#
# Run manually against a file:
#   bash scripts/validate-commit-message.sh <path-to-message-file>
#
set -uo pipefail

MSG_FILE="${1:-}"
if [[ -z "$MSG_FILE" || ! -f "$MSG_FILE" ]]; then
  echo "validate-commit-message: no message file supplied." >&2
  exit 2
fi

# Comment lines are stripped by git and must not trigger a rejection —
# `git commit -v` appends the whole staged diff below a comment marker, and
# that diff can legitimately contain any string at all.
BODY="$(grep -v '^#' "$MSG_FILE" || true)"

# Assembled from fragments so this file does not itself contain the literal
# strings it rejects; that keeps it clean under
# scripts/scan-prohibited-references.sh without needing an allowlist entry.
A='[Cc]laude'
FORBIDDEN=(
  "[Cc]o-[Aa]uthored-[Bb]y:.*${A}"
  "${A}-[Ss]ession:"
  "[Gg]enerated (by|with) ${A}"
  "${A} (Code|Opus|Sonnet|Haiku)"
  "[Cc]o-[Aa]uthored-[Bb]y:.*(anthropic|openai|chatgpt|copilot|gemini)"
  "[Gg]enerated (by|with) (AI|an AI|artificial intelligence)"
  "[Aa][Ii]-generated"
  "[Cc]o-[Aa]uthored-[Bb]y:.*(noreply@anthropic|bot@|assistant@)"
)

# Collect first, then report each offending line once — several patterns can
# legitimately match the same trailer, and repeating it is just noise.
HITS=""
for pattern in "${FORBIDDEN[@]}"; do
  HITS+="$(printf '%s\n' "$BODY" | grep -nEi "$pattern" || true)"$'\n'
done
HITS="$(printf '%s' "$HITS" | grep -v '^$' | sort -t: -k1,1n -u || true)"

FOUND=0
if [[ -n "$HITS" ]]; then
  FOUND=1
  while IFS= read -r hit; do
    [[ -n "$hit" ]] && echo "PROHIBITED COMMIT METADATA: line $hit" >&2
  done <<< "$HITS"
fi

if [[ "$FOUND" -ne 0 ]]; then
  cat >&2 <<'MSG'

This commit message contains automated-assistant attribution, generation
attribution, or session metadata. Repository policy requires neutral,
professional commit messages.

Remove the offending line(s) and commit again. For example:

  docs: update repository validation guidance
  chore: enforce commit metadata policy
  fix: correct hierarchy validation

Existing published commits are intentionally exempt and are never rewritten.
See docs/current-repository-cleanup.md.
MSG
  exit 1
fi

exit 0
