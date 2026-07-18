# NDMO — Data Classification Metadata

`evidence_files.classification_metadata` (nullable JSON, migration
`2026_07_22_000002_add_classification_metadata_to_evidence_files.php`) —
prepared per the brief's explicit instruction, **not populated or
enforced this phase**.

## What exists

A column to hold future structured values such as classification level,
contains-personal-data, contains-sensitive-data, retention category,
access restriction, encryption-required, download-restriction — generic,
program-agnostic, values never hard-coded anywhere in this codebase.

## What deliberately does NOT exist

- No UI writes to this column.
- No approved organizational classification scheme is encoded anywhere.
- **File-download authorization does not read this column at all** — it
  continues to go through the existing program/cycle/department/
  submission-ownership checks in `EvidenceSubmissionController`. This is
  a deliberate design choice, not an oversight: the brief explicitly
  warns "do not allow the frontend to decide access solely from
  metadata," and the safest way to guarantee that is for the backend
  authorization path to never consult this field at all until an
  approved classification-driven access model is designed and reviewed.

## Required before this becomes a real feature

An approved organizational data-classification scheme (the actual
allowed values and their meaning), and an explicit decision on whether/
how classification should affect authorization (a materially larger
design question than adding a column) — see the final Phase 7 report's
"القرارات التنظيمية المطلوبة" section.
