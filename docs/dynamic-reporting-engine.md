# Dynamic Reporting Engine

**Status: implemented and tested** — 19 backend tests (`HierarchyReportTest`),
Playwright coverage of dimensions, 4-dimension grouping, cascading filters
and export at 3, 5 and 7 levels on Chromium.

Replaces four fixed reports that could not group by **any** hierarchy level
(audit finding H2).

## Dimensions come from the structure

`GET /programs/{program}/reports/dimensions`

Every level marked `appears_in_reports`, plus the fixed operational
dimensions `department`, `status`, `employee`, `cycle`.

| Program | Hierarchy dimensions | Export columns |
|---|---|---|
| SUMOUD (3) | domain, category, requirement | 13 |
| QIYAS (5) | perspective … evidence_requirement | 17 |
| NDMO (6) | domain … control_activity | 19 |

## Grouping

`GET /programs/{program}/reports/hierarchy?group_by[]=domain&group_by[]=policy&group_by[]=standard`

Up to **four** nested dimensions — the current supported maximum, enforced
by `'group_by' => ['sometimes','array','max:4']`. Group-by keys are validated
against the program's own whitelist; an unknown key is a `422`, never an
interpolated column name. **There is no free-form SQL and no expression
language.** A Program Manager selects from supported dimensions; they cannot
author a query.

Available dimensions = every level marked `appears_in_reports`, plus the
fixed operational four: `department`, `status`, `employee`, `cycle`.

The response nests `{groups: [...]}` while dimensions remain and `{rows: [...]}`
at the leaf, so a client can descend without knowing the depth in advance.

Live NDMO example:

```
domain    المجال تجريبي NDMO-01     n=4
  policy    السياسة تجريبي NDMO-01.1  n=2
    standard  المعيار تجريبي NDMO-01.1.1  n=1  → 1 assignment row
    standard  المعيار تجريبي NDMO-01.1.2  n=1  → 1 assignment row
  policy    السياسة تجريبي NDMO-01.2  n=2
    ...
```

## Cascading filters

`GET /programs/{program}/reports/filter-options/{levelKey}?parent_node_id=`

Options for one level, narrowed to a chosen parent's subtree. Chaining the
calls produces a filter chain of whatever depth the program configured — the
frontend `HierarchyFilter` component does exactly this and knows no level
names. Levels must be marked `appears_in_filters`; anything else is refused.

## Export

`GET /programs/{program}/reports/hierarchy/export` — streamed CSV with a
UTF-8 BOM so Excel opens the Arabic labels correctly. Columns follow the
program's structure and carry its own terminology.

For the XLSX hierarchy export (a different concern — node content rather
than assignment rows) see [`dynamic-xlsx-engine.md`](dynamic-xlsx-engine.md).

## Security — verified, not asserted

**Authorization is applied last and unconditionally**, after every filter has
been constructed. The specific risk is that hierarchy filtering becomes a way
to *widen* visibility, so the tests deliberately ask for more than the caller
is entitled to:

| Attempt | Result |
|---|---|
| Employee filters the report to the **root node** (i.e. everything) | Still sees only their own department's rows |
| Employee sends `department_id=1&department_id=2` | No widening |
| Employee sends `node_id=0`, `node_id=999999` | No widening |
| Employee sends `group_by[]=department&department_id=99999` | No widening |
| Department Manager, unfiltered | Strictly fewer rows than the Program Manager |
| Auditor | Not department-scoped, but **404** on another program |
| Program Manager | **404** on another program's structure, dashboard, reports and hierarchy |
| Executive Viewer | Reads dashboards; `can_manage=false`; **403** on structure writes |

Verified in `tests/e2e/dynamic-hierarchy/security-scope.spec.ts` (19 tests
across the three depth fixtures) and in backend tests.

**Scope is never derived from the chosen hierarchy level.** Department and
program scoping are independent of the filter and cannot be relaxed by one.

## Performance

See [`performance-evidence.md`](performance-evidence.md). On 9,336 nodes,
report generation is **103–210 ms P50 at 10 queries**, and adding grouping
dimensions costs essentially nothing (grouping happens in memory over rows
already fetched): 1-dimension 105 ms, 4-dimension 107 ms on PERF7.

This is after fixing an N+1 that the measurement exposed — reports
previously issued **3,464 queries** and took over a second.

## Saved reports

**Not implemented.** The platform has no saved-report feature today, so
there was nothing to adapt to structure versions. When one is added it must
store `structure_version_id` and flag itself for review when the program's
structure moves on — the mechanism (`ProgramStructureVersion`) already
exists. Recorded as deferred, not done.
