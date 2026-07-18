#!/usr/bin/env bash
#
# Deterministic, non-AI scan of the CURRENT tracked-file tree for
# references to automated code-generation tools (Claude, Anthropic,
# ChatGPT, OpenAI, GPT, Copilot, Gemini, LLM/"language model",
# "generative AI", "AI-generated", "vibe coding", and Arabic
# equivalents). Uses only `git grep` and POSIX text tools — no AI
# involved in the scan itself.
#
# Scope: tracked files at HEAD only. Never touches Git history (no
# rewriting, no scanning of old commits/blobs) — see
# docs/current-repository-cleanup.md.
#
# Any exclusion below is a single reviewed line, pinned by exact
# SHA-256 content hash (not a filename/directory wildcard). If the
# pinned line's content ever changes, its hash stops matching and the
# scan re-flags it for re-review — an exclusion can never silently
# widen. Add a new entry only after confirming the match is a genuine
# false positive (third-party package metadata, official regulatory
# content, or a documented platform-limitation disclosure) and
# recording the justification here.
set -euo pipefail
cd "$(git rev-parse --show-toplevel)"

PATTERN='claude|anthropic|chatgpt|openai|\bgpt\b|copilot|gemini|\bllm\b|language model|generative ai|ai-generated|vibe coding|الذكاء الاصطناعي|تم إنشاؤه بواسطة|نموذج لغوي|مساعد ذكي'

# format: <path>:<line-number>:<sha256 of the exact line content>
ALLOWLIST=(
  # laravel/agent-detector (vendor/laravel/agent-detector) — a real,
  # independently-published Composer package whose OWN stated purpose is
  # "Detect if code is running in an AI agent or automated development
  # environment." "claude" is that package's own keyword metadata
  # describing what it detects, not an attribution of who built this
  # platform. Third-party package metadata is never edited.
  'composer.lock:7867:54c8502b392e078b5ebe14ec041df5665d7bdd7f2cfd83c6a019e0d7da2e6e22'

  # Official Saudi government regulatory standards content (imported
  # verbatim from the published Qiyas compliance standard), naming the
  # Saudi Authority for Data and Artificial Intelligence (SDAIA) and
  # referencing government AI-technology-adoption policy requirements.
  # Legitimate regulatory subject matter — not a generation-tool
  # attribution — and must not be altered, as it would misrepresent an
  # official standard's text.
  'database/seeders/data/qiyas_standards.json:633:457876e67cf6b88c0a621959d5d01d713470bc6df47517cc7bd45fc38ac03e67'
  'database/seeders/data/qiyas_standards.json:635:e1164e7d4adb4e1a4fb7ee109a0fc8dec2bf46cae85e3633489343a6937def78'
  'database/seeders/data/qiyas_standards.json:645:e66870a639e3c5294a4adb45fc6f31c2072420a89bc82664814a9772ab8a0845'
  'database/seeders/data/qiyas_standards.json:647:1a72d31203438fd23671b610c05b6ee2660a688c06534238b6d7b47ef53dfa7b'
  'database/seeders/data/qiyas_standards.json:957:88f9d523b51bb2bca975048501a0314d7034b317df13c860e704475264ef032d'
  'database/seeders/data/qiyas_standards.json:959:7ee9f22b9f32614d60ea35211b14eeaff20b29b40acbe8244f51c7ebf4faf616'

  # A documented platform-scope disclosure ("this platform does not
  # produce AI-generated recommendations") in the Qiyas known-issues
  # log — a limitation statement, not an attribution claim.
  'docs/qiyas-known-issues.md:100:b93df34015646880c415ecab80f1320dea8a925989a5ec25c01a07f59c9be330'
)

is_allowed() {
  local file="$1" line="$2" content="$3"
  local content_hash
  content_hash=$(printf '%s' "$content" | shasum -a 256 | cut -d' ' -f1)
  local entry entry_file entry_line entry_hash
  for entry in "${ALLOWLIST[@]}"; do
    entry_file="${entry%%:*}"
    local rest="${entry#*:}"
    entry_line="${rest%%:*}"
    entry_hash="${rest#*:}"
    if [[ "$file" == "$entry_file" && "$line" == "$entry_line" && "$content_hash" == "$entry_hash" ]]; then
      return 0
    fi
  done
  return 1
}

violations=0
while IFS=: read -r file line content; do
  [[ -z "$file" ]] && continue
  if ! is_allowed "$file" "$line" "$content"; then
    echo "PROHIBITED REFERENCE: ${file}:${line}: ${content}"
    violations=$((violations + 1))
  fi
done < <(git grep -inE "$PATTERN" -- . ':!vendor' ':!node_modules' 2>/dev/null || true)

if [[ "$violations" -gt 0 ]]; then
  echo ""
  echo "${violations} prohibited reference(s) found in tracked files with no matching reviewed allowlist entry."
  echo "See docs/current-repository-cleanup.md for the review process."
  exit 1
fi

echo "No unreviewed prohibited references found."
exit 0
