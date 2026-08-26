# ECC — Scoring Limitations

`features.scoring_enabled = false` for ECC, set explicitly by
`ECCProgramConfigurationSeeder` — **no approved ECC scoring formula
exists**, and none is invented or assumed.

What IS shown: workflow status counts and completion/approval percentages
(via `DashboardMetricsService`, the same neutral counts Qiyas/Sumoud
display), overdue and SLA indicators. What is NOT shown: any regulatory
compliance percentage, any weighted score, any reuse of Qiyas's weight
model or Sumoud's scoring behavior.

`features.not_applicable_enabled = false`: the Not-Applicable process
described in the brief is deferred — no approval workflow, no unilateral
Employee self-marking, nothing beyond a disabled feature flag exists this
phase. See `known-limitations.md`.

Both flags must remain `false` until an authorized organizational
decision supplies an approved scoring formula and/or Not-Applicable
business rule — see `docs/three-program-isolation.md`'s completion
conditions and the final Phase 6 report's "القرارات التنظيمية المطلوبة"
section for what is specifically needed to unblock each.
