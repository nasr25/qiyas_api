# NDMO — Scoring Limitations

`features.scoring_enabled = false` for NDMO, set explicitly — **no
approved NDMO scoring formula exists**, and none is invented or assumed.

What IS shown: workflow status counts and completion/approval
percentages (the same neutral counts every other program displays),
overdue and SLA indicators. What is NOT shown: any regulatory compliance
percentage, any weighted score, any reuse of Qiyas/Sumoud/ECC scoring.

## Two additional deferred models specific to NDMO's brief

`features.not_applicable_enabled = false`: the Not-Applicable process is
deferred, identical to ECC's treatment — a disabled flag only.

`features.assessment_result_enabled = false`: the brief's
compliant/partially_compliant/non_compliant/not_assessed/not_applicable
assessment-result model is kept entirely separate from workflow status
(no code conflates them — workflow approval is never automatically
interpreted as a formal regulatory compliance result) and is not enabled,
since no approved assessment business rules exist. No model or table for
it was built this phase (a materially larger, still-undesigned feature),
distinct from the responsibility model which WAS built.

Both flags must remain `false` until an authorized organizational
decision supplies the missing approved definitions — see the final Phase
7 report's "القرارات التنظيمية المطلوبة" section.
