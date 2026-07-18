# NDMO — Terminology

Configured entirely through the `terminology` program-configuration
category — never hardcoded in any shared Vue component.

| Concept | Arabic | English |
|---|---|---|
| Program | البرنامج | Program |
| Cycle | دورة التقييم | Assessment Cycle |
| Domain | المجال | Domain |
| Policy (Category) | السياسة | Policy |
| Requirement | المتطلب | Requirement |
| Evidence | مستند الإثبات | Evidence Document |

Per the brief, `Standard`/`Subrequirement`/`Assessment`/`Compliance
Status`/`Data Owner`/`Data Steward` are additional recommended terms —
`Standard` and `Subrequirement` are represented as `hierarchy` level
labels; `Data Owner`/`Data Steward` are represented as `responsibilities`
type labels (مالك البيانات / Data Owner, مسؤول البيانات / Data Steward).
`Assessment`/`Compliance Status` are not yet surfaced as distinct UI
labels this phase — the existing workflow-stage labels are used instead,
matching the pattern already established for Qiyas/Sumoud/ECC.

**These are configurable defaults only, not confirmed official
terminology** — a Super Admin can update any of them through the Program
Configuration Engine without touching generic backend entity names.
