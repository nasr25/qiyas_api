# ECC — Terminology

Configured entirely through the `terminology` program-configuration
category — never hardcoded in any shared Vue component (confirmed by grep;
`HierarchyExplorerView.vue` renders level labels from
`hierarchyService.levels()`, itself sourced from the `hierarchy` category's
`label_ar`/`label_en`, not from `terminology` — the two categories are
deliberately separate: `terminology` names the generic concepts
platform-wide UI strings reference (nav labels, breadcrum33 "root" label),
`hierarchy.levels[].label_ar/en` names each specific configured level).

| Concept | Arabic | English |
|---|---|---|
| Program | البرنامج | Program |
| Cycle | دورة التقييم | Assessment Cycle |
| Domain | المجال الرئيسي | Main Domain |
| Category (Subdomain) | المجال الفرعي | Subdomain |
| Requirement (Control) | الضابط | Control |
| Evidence | مستند الإثبات | Evidence Document |

Per the brief, `Subcontrol`/`Implementation Requirement`/`Assessment`/
`Compliance Status` are additional recommended terms — `Subcontrol` is
represented as a `hierarchy` level label (`الضابط الفرعي` / `Subcontrol`);
`Assessment`/`Compliance Status` are not yet surfaced as distinct UI
labels this phase (the workflow stage labels — "بانتظار مدير الإدارة" etc.
— are used instead, matching Qiyas/Sumoud's existing pattern). A Super
Admin can update any of these values through the Program Configuration
Engine without touching generic backend entity names.
