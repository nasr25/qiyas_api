# ECC — Content Versioning

`compliance_content_versions` (migration `2026_07_21_000001_...`,
`ComplianceContentVersion` model): tracks a framework content release
independently of any one cycle — `version_label`, `source_name`,
`source_date`, `imported_by`, `file_hash`, `template_version`, `status`
(draft/active/superseded), `effective_date`, `previous_version_id`,
`change_summary`.

`assessment_cycles.content_version_id` (nullable, added by
`2026_07_21_000003_add_hierarchy_bridge_columns.php`) ties a cycle to
exactly one content version for its entire lifetime.
`compliance_nodes.content_version_id` and `standards.content_version_id`
record which version each hierarchy node/mirrored requirement came from.

## Historical-cycle protection — proven

`ECCProgramEngineTest::test_updating_content_version_does_not_alter_a_historical_cycle`
creates a V2 content version after a cycle already exists on V1 and
asserts the cycle's `content_version_id` is unchanged — publishing a new
version never silently rewrites an existing cycle's hierarchy.

## Not built this phase

- **Version comparison reporting** (added/removed/modified controls
  between two content versions) — no comparison service or UI exists.
- **A UI/command to create a new cycle explicitly selecting a newer
  content version** — currently only possible by setting
  `content_version_id` directly (e.g. via `ComplianceNodeService` callers
  or a future admin tool); no dedicated endpoint.
- **Automatic evidence migration between versions** — correctly NOT
  implemented, per the brief's explicit prohibition; there is no code
  path that could do this even accidentally, since evidence is tied to an
  assignment/cycle, never to a content version directly.
- **Import-driven content-version creation** — see `xlsx-import.md`'s
  honest limitation; the importer does not yet create a
  `ComplianceContentVersion` row on confirm.

These are real, acknowledged gaps against the brief's full content-
versioning vision — the underlying model and the one guarantee that
matters most (a historical cycle is never silently altered) are built and
tested; the surrounding tooling to manage versions day-to-day is not.
