# ECC — Known Limitations (Phase 6)

Honest gap list this phase's final report points to.

## The generic-engine correction found and fixed this phase

The mandatory pre-implementation analysis (re-running Qiyas/Sumoud
regression, reviewing hierarchy flexibility) confirmed the Phase 4/5
engine — program configuration, workflow, roles, evidence, review, SLA,
extensions, notifications, dashboards, reports, audit, cross-program
isolation, and the frontend role-resolution fix from Phase 5 — needed
**zero further changes** to support a third program. The one genuinely
new engine capability required was hierarchy depth: Qiyas/Sumoud's
two-level free-text shape could not represent ECC's four levels. This was
the single blocking gap, and it was fixed by adding the generic
`ComplianceNode`/`ComplianceContentVersion` engine (see `hierarchy.md`),
not by working around it inside ECC-specific code.

## Real, acknowledged gaps against the brief's full vision

1. **Official-content import is not functionally complete.**
   `QiyasImportService` creates/updates flat `standards` rows; it does
   not yet resolve or create the parent `ComplianceNode` chain (Domain/
   Subdomain) from an imported row, nor record a `ComplianceContentVersion`
   on confirm. The "download → populate → validate → preview hierarchy →
   confirm → content-version record" flow described in the brief works
   for the flat two-level shape (as it already does for Qiyas/Sumoud) but
   not yet for ECC's deeper hierarchy. See `xlsx-import.md`. **This is
   also the reason no official ECC content could be imported this
   phase even if a source file had been supplied** — the pipeline to
   receive it is not finished.
2. **Content-version comparison reporting, and a dedicated "create cycle
   against version X" flow, are not built.** See `content-versioning.md`.
3. **Not-Applicable process is entirely deferred** — a disabled feature
   flag only, no model, no workflow, no UI. Correct per the brief's
   explicit instruction ("do not enable it without an approved business
   rule"), but a real gap against the full vision.
4. **No approved ECC scoring formula** — `scoring_enabled=false`,
   documented in `scoring-limitations.md`.
5. **Domain/Subdomain-grouped dashboard and report metrics are not
   built** — `DashboardMetricsService` has no hierarchy-grouping
   awareness yet.
6. **SLA time-travel Playwright tests, full ECC XLSX Playwright journey
   (blocked by gap #1 above regardless), responsive tests, and full
   cross-browser scenario coverage beyond smoke were not built** — the
   same categories of gap already left open for Qiyas (Phase 4) and
   Sumoud (Phase 5).
7. **A non-assessable `ComplianceNode` (Domain, Subdomain) has no bridge
   into any existing engine table** — by design (only assessable items are
   assignable), but it means "the hierarchy" is represented across two
   models (`ComplianceNode` for structure/navigation, `Standard` for
   assignment/evidence/workflow) rather than one — see `hierarchy.md`'s
   "Why a bridge, not a rewrite."
8. **`HierarchyExplorerView.vue` is intentionally minimal** — a
   functional drill-down tree with create-child support, not a full
   hierarchy-editing UI (no move/reorder/archive/edit-in-place). It
   satisfies the mandatory ECC Playwright lifecycle's hierarchy-creation
   steps and is generic (usable by any future program with a `hierarchy`
   configuration), but is a foundation, not a finished feature.

## Release readiness

See the Phase 6 final report for the formal NDMO-readiness classification
and full blocker list.
