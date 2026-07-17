# Evidence Engine

## What changed in Phase 4

`EvidenceUploadValidator` read file-type/size limits from a
**platform-wide** `Setting` group (`evidence_upload`) before this phase —
one policy for every program. It now accepts an optional
`?ComplianceProgram $program` on every method
(`validateFile`/`validateSubmissionLimits`/`allowedExtensions`/
`maxFileSizeMb`/`maxFilesPerSubmission`/`maxTotalSubmissionSizeMb`), and
prefers the program's `evidence` configuration category over the platform
`Setting` when one exists — falling back to the platform default for a
program that hasn't been given its own evidence policy yet (so nothing
regresses for a program created before this category existed).

`EvidenceSubmissionController::uploadFile()` now passes
`$this->program($request)` through.

## Verified this is genuinely per-program, not just per-parameter

`test_evidence_upload_limits_are_program_scoped_not_platform_wide`
(`tests/Feature/Engine/ProgramConfigurationEngineTest.php`) reconfigures
Qiyas to a `pdf`-only allowlist, confirms a `.docx` upload is now rejected
for Qiyas, **and** confirms a different, unconfigured program still falls
back to the platform default (`.docx` still allowed there) — proving the
two programs' policies are independent, not a single global toggle
disguised as a parameter.

## Unchanged (already correct, reverified after the refactor)

- Real content-type detection (`getMimeType()`, not the client-supplied
  extension) — see `docs/qiyas-security-review.md` finding #2 (Phase 3) for
  the disguised-executable fix this builds on.
- UUID storage names, immutable submitted versions, one row per version —
  `EvidenceFile`/`EvidenceSubmission`, unchanged this phase.
- Program/department authorization on download —
  `EvidenceSubmissionPolicy::downloadFile()`, unchanged this phase.
- Antivirus integration point: still not implemented (was already
  documented as deferred in Phase 3's known-issues; not revisited here).
