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

  # This script's own header comment and PATTERN/allowlist-justification
  # text necessarily spell out the exact trigger words to document what
  # the scan searches for and why each exclusion above is legitimate —
  # inherently self-matching, and not an attribution of any kind.
  'scripts/scan-prohibited-references.sh:4:f9eb4c730a47c1cb4bffde0b6b9ebefe3938dd0e626da1dca21a382d4a24ca63'
  'scripts/scan-prohibited-references.sh:5:949478f556e1723e2ff9a66c74560b68c32e2346890e8e16d660cc280fb5e6f5'
  'scripts/scan-prohibited-references.sh:6:2dcb046d383c91bc46d14ab37df187674e25d84486b63e2964718ba64507b5f6'
  'scripts/scan-prohibited-references.sh:25:bd05f0134916815d96aaa1d7b2b813930443d6c0559ed0e966adda7feaa7dbe7'
  'scripts/scan-prohibited-references.sh:32:30f1d007b524f827b0fcfb4ffa107be6814cd113d0205bd66053b9c5d8369e1f'
  'scripts/scan-prohibited-references.sh:52:8b5131f181a0831cfaf488e9b9cd7674717c21fe9986e84c697ac0937307615d'

  # This phase's own documentation ABOUT the prohibited-reference
  # policy, the dependency scan, and the final Arabic readiness report
  # necessarily names the trigger words/tool names to explain what was
  # searched for, found, and reviewed — self-referential
  # meta-documentation, not an attribution of any kind. See
  # docs/current-repository-cleanup.md, docs/dependency-inventory.md,
  # and docs/phase-8-final-report-ar.md.
  'docs/current-repository-cleanup.md:28:3199adbb679b2254305a4a47a4bbe3b6a0fd5a122cb996d25a64483a0e2d44b1'
  'docs/current-repository-cleanup.md:29:943df2f05309b341cea16e2abb82008d715c44bab8f2205502bd92d0830f017b'
  'docs/current-repository-cleanup.md:30:9fb45773aa7bd9b829f74d4e069c9436583534e8b41446d0be605ca85e633510'
  'docs/current-repository-cleanup.md:31:aef7ef1f9551ce9ac4c3a039ee80bd2f1a1466330b079f2488c298437a2aa1c8'
  'docs/current-repository-cleanup.md:39:0c13fc7ffc4a751efeae3da10a4838c62528ab665668045864261b7fd4198ecc'
  'docs/current-repository-cleanup.md:52:980d518a8e5080aceaf619140636ee398dabf18c9caf8d782c085fcc1c812d32'
  'docs/current-repository-cleanup.md:54:e6df2ded0b514f8cfbb1a698de39166cef222e42915acc036dd83551ed3061af'
  'docs/current-repository-cleanup.md:59:b9ea5ad05212ba48ae53c9fd97f4541253d07ae3c54a84b4e03b163d273d6bb4'
  'docs/current-repository-cleanup.md:101:551766267df8e81a4f287c413858f6a65f555757a0307b54faff9bb27dc38044'
  'docs/dependency-inventory.md:72:c065ea680dc32a8d68f2c51a3dd4d8a6880cca42729d4ef4575186a21dca2941'
  'docs/dependency-inventory.md:74:3c7b5bd100370276c3d3055daa81b45bb84c3108a9d0940b5013fb0475461078'
  'docs/phase-8-final-report-ar.md:16:4698368278fd70b6c42511c6edbc70c1342e793d50c13e8e109c07cfc1471828'
  'docs/phase-8-final-report-ar.md:44:9c659156a429ea7ed551e9f7a203c78861f1624367f297dd0c264ad29c618fc8'
  'docs/phase-8-final-report-ar.md:53:c4356ba366222b00b43d68f3e67d39432fe416ec87aa685aa5855f2ec81cb069'
  'docs/phase-8-final-report-ar.md:62:067343ec88e34f4ac90f71c5e960fa1427da6dcbeb2acab4fe5ab43cef70f3dd'
  'docs/phase-8-final-report-ar.md:65:f1552ac7f4cd6de877cd6e9f16c57ec2c3fcf6f382718b810ca0db457409c79f'
  'docs/phase-8-final-report-ar.md:68:a8e7f75bc431702cafa85022fdd6e40fe58f0e141cfb3ab66ebf286c13a56ca8'
  'docs/phase-8-final-report-ar.md:83:bdf12173155ee2b8be17629805b73546b5b436bfe433707c41aa562a976d0f24'
  'docs/phase-8-final-report-ar.md:118:af608045b5f7ef0b79ae8685d602dc79b560776e2b7b49f5ea7d98adb300576b'
  'docs/phase-8-final-report-ar.md:121:6703a370e467d4ea7b64fb3fd323639c525217c8180f3bf043e1be66fb9222d5'
  'docs/phase-8-final-report-ar.md:130:201e15c7b3d319d694ea03e0bee6a979d46ad212e40cdcb350a21371dfddcdfc'
  'docs/phase-8-final-report-ar.md:136:d0f5d1c7dfc8a0cad307a410951727137146b240d90af6ce06831a190ea7223c'
)

is_allowed() {
  local file="$1" line="$2" content="$3"
  local content_hash
  content_hash=$(printf '%s' "$content" | shasum -a 256 | cut -d' ' -f1)
  local entry entry_file entry_line entry_hash
  # The bash-3.2 shipped on macOS (pre-4.4 behavior) treats expanding an
  # empty array under `set -u` as an unbound-variable error — the
  # `+"${ALLOWLIST[@]}"` guard keeps this portable across bash versions.
  for entry in "${ALLOWLIST[@]+"${ALLOWLIST[@]}"}"; do
    [[ -z "$entry" ]] && continue
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
