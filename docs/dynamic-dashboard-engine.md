# Dynamic Dashboard Engine

**Status: implemented and tested** — 16 backend tests
(`HierarchyDashboardTest`), Playwright drill-down coverage at 3, 5 and 7
levels on Chromium, performance measured on 9,336 nodes.

Replaces a program dashboard that grouped only by department and status and
had **no hierarchy view at all** (audit finding H1).

## Two categories, deliberately separate

### 1. Universal metrics — identical at every depth

`GET /programs/{program}/dashboard/metrics`

Seventeen metrics that count assignments, submissions, SLA instances and
extension requests. None of them knows what a Perspective, Domain or Control
is:

```
count_assessable · count_assigned · count_unassigned
count_draft · count_pending_department_manager · count_pending_auditor
count_pending_program_manager · count_returned · count_approved
count_overdue · count_due_soon
sla_warning_count · sla_breach_count · extension_request_count
completion_percentage · workflow_approval_percentage · evidence_completion_percentage
```

A test asserts the metric **set** is byte-identical at 3, 5, 7 and 8 levels,
and another asserts no metric key contains a hierarchy term. That is what
makes the Executive dashboard able to compare programs of different shapes.

### 2. Hierarchy drill-down — follows the program's own levels

```
GET /programs/{program}/dashboard/levels
GET /programs/{program}/dashboard/by-level/{levelKey}?node_id=&cycle_id=
```

`by-level` returns rows for that level plus a `next_level` pointer. The
client drills by following the pointer — it never needs to know the level
names or how many there are. Each group's numbers are simply the universal
metrics of its own subtree, so one code path answers "progress by
Perspective" and "progress by Subrequirement" alike.

Live NDMO example (6 levels, 4 dashboard-visible):

```
[المجال]     2 groups   assessable=12
  [السياسة]             assessable=6
    [المعيار]           assessable=3
      [المتطلب]         deepest dashboard level
```

Counts narrow correctly at each hop — 12 → 6 → 3.

## Program Manager configuration

Which levels appear is `appears_in_dashboard` on each level definition,
edited in Structure Settings. Disabling a mid-level removes it from the drill
path immediately and the chain closes over it — a test asserts that hiding
level 2 of a 4-level program yields the path `level_1 → level_3 → level_4`.

Managers select from `HierarchyDashboardService::SUPPORTED_METRICS`. **No
custom SQL or formulas**, per the brief.

## Executive dashboard stays hierarchy-neutral

`ExecutiveDashboardView` contains no hierarchy term. It compares programs on
**universal metrics only**.

This is a deliberate correctness constraint, not a simplification. A Qiyas
Perspective and an NDMO Policy are **not** semantically equivalent: one is a
first-level grouping in a five-level structure, the other a second-level
grouping in a six-level one, and they differ in count, scope and meaning.
Placing them side by side would invite a comparison that is not valid.

The executive view therefore compares programs on normalised metrics
(completion, approval, overdue) and offers **drill-down into an individual
program**, where that program's own terminology and depth apply.

## Security

Every query is program-scoped, and department scoping is applied
unconditionally for users limited to their own department. A node id from
another program scopes to **nothing**, never to everything — tested
explicitly. A hierarchy filter therefore cannot be used to widen visibility.

## Performance

See [`performance-evidence.md`](performance-evidence.md). On 9,336 nodes:
universal metrics 48–104 ms P95, drill-down 125–271 ms P95.

**Known cost:** drill-down issues one metrics query set per group (209
queries for 12 groups). Acceptable at current widths, O(groups), and
recorded as technical debt rather than left implicit.
