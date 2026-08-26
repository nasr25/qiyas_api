# NDMO — Known Limitations (Phase 7)

> **Status update — 26 August 2026.** This page records the Phase 7 gap list.
> The Dynamic Compliance Engine has since resolved several of the items
> below. The historical text is kept intact; this table is the current truth.
>
> | Phase 7 gap | Status now |
> |---|---|
> | #1 Import does not resolve/create the `ComplianceNode` chain — *"the platform's most important remaining engine gap"* | **Resolved** — this was the gap the dynamic engine was built to close. NDMO imports and exports at its full 6-level depth (22 columns) |
> | #7 Domain/Policy/Standard-grouped dashboard and report metrics | **Resolved** — grouping is per-level and configuration-driven |
> | #8 Full NDMO XLSX Playwright journey | **Resolved** — `dynamic-xlsx/template-depth.spec.ts`, 7 tests, including old-template rejection |
> | #9 Occasional full-suite Playwright timeout under contention | **Resolved** — diagnosed as a threshold, not a defect; `timeout` raised to 60 s with no assertion relaxed. Two consecutive clean runs at 231/231 |
> | #2 Content-version comparison reporting | Still open |
> | #3 Not-Applicable process and assessment-result model | Still deferred by explicit instruction |
> | #4 No approved NDMO scoring formula | Still open — a business decision |
> | #5 Data-classification metadata unpopulated | Still open |
> | #6 Responsibility removal has no frontend control | Still open |
> | #8 SLA time-travel and responsive tests | Still open |
>
> NDMO is also the program on which the audit proved the old engine's silent
> path truncation (finding C2). It now runs at its full depth.
>
> Current documentation: [`../../dynamic-compliance-structure.md`](../../dynamic-compliance-structure.md)

## The generic-engine correction confirmed this phase (finding no new gap is itself a finding)

The mandatory pre-implementation analysis (re-running Qiyas/Sumoud/ECC
regression, reviewing hierarchy flexibility and content-version
handling) confirmed the Phase 4-6 engine needed **zero further changes**
to support a fourth program with yet another hierarchy shape and depth.
This is strong evidence the engine's genericity claims from Phases 5-6
were real, not coincidental to ECC's specific shape. The two genuinely
new pieces this phase — the responsibility model and evidence
classification metadata — were purpose-built generic additions per the
brief's explicit new requirements, not NDMO-specific workarounds.

## Real, acknowledged gaps against the brief's full vision

1. **Official-content import is still not functionally complete** —
   unchanged from ECC's Phase 6 gap, now confirmed to affect a SECOND
   program. `QiyasImportService` creates/updates flat `standards` rows;
   it does not resolve or create the parent `ComplianceNode` chain, nor
   record a `ComplianceContentVersion` on confirm. See `xlsx-import.md`.
   **This is the platform's most important remaining engine gap** —
   fixing it once benefits every hierarchy-configured program at once.
2. **Content-version comparison reporting and a dedicated "create cycle
   against version X" flow are not built** — same gap as ECC.
3. **Not-Applicable process and the formal assessment-result model are
   both entirely deferred** — disabled feature flags only, no models, no
   workflows, no UI. Correct per the brief's explicit instruction, but a
   real gap against the full vision.
4. **No approved NDMO scoring formula.**
5. **Data-classification metadata is prepared but unpopulated and
   unenforced** — see `data-classification.md`.
6. **Responsibility removal/reassignment has no frontend control** — the
   backend endpoint exists and is tested; no UI button calls it yet.
7. **Domain/Policy/Standard-grouped dashboard and report metrics are not
   built** — same gap as ECC.
8. **SLA time-travel Playwright tests, full NDMO XLSX Playwright journey
   (blocked by gap #1 regardless), responsive tests, and full cross-
   browser scenario coverage beyond smoke were not built** — same
   categories of gap as all three other programs.
9. **A local-machine full-suite Playwright run at 6-way default
   parallelism occasionally times out one test under resource
   contention** (confirmed transient — the same test passes reliably
   both in isolation and at 3-way parallelism, with a different failure
   symptom each time it occurred, the signature of contention rather
   than a deterministic defect). Documented as an infrastructure/scaling
   observation as the suite has grown to 55 cases, not a functional
   regression — see `docs/playwright-ndmo-tests.md`.

## Release readiness

See the Phase 7 final report for the formal pilot-deployment-readiness
classification and full blocker list.
