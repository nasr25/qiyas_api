# Performance Evidence

Every number below was **measured**, not estimated. Reproduce with:

```bash
php artisan db:seed --class=PerformanceFixtureSeeder --force --env=perf
php artisan compliance:measure-performance --env=perf --iterations=20 --plans
```

Measurement date: 2026-08-26 · MySQL 8.4.10 · PHP 8.5.4 · single local machine,
no concurrent load. **These are single-host development numbers and are not a
production capacity statement.**

## Dataset

| | |
|---|---|
| Compliance nodes | **9,336** |
| Programs | 3 (PERF3 / PERF5 / PERF7) |
| Structure depths | 3, 5, 7 levels |
| Cycles | 2 per program (1 active, 1 closed) |
| Departments | 4 |
| Assignments | 3,400 |
| Evidence submissions | 3,384 |
| SLA instances | 3,400 |

## Results (20 samples per operation)

Times in milliseconds. "Queries" is the SQL statement count for one call.

| Operation | PERF3 (3 lvl) | PERF5 (5 lvl) | PERF7 (7 lvl) | Queries |
|---|---|---|---|---|
| Hierarchy root load | 0.8 / 0.8 | 0.6 / 0.6 | 0.5 / 0.6 | 1 |
| Child-node load | 0.6 / 0.7 | 0.5 / 0.6 | 0.5 / 0.5 | 1 |
| Breadcrumb (full depth) | 1.7 / 2.3 | 3.9 / 4.5 | 3.9 / 4.5 | 6 / 10 / 14 |
| Subtree ids (recursive CTE) | 0.4 / 0.5 | 0.7 / 0.8 | 0.6 / 0.7 | 1 |
| Cascading filters (full chain) | 4.1 / 4.2 | 5.4 / 7.4 | 7.3 / 8.0 | 5 / 9 / 13 |
| Search (code/name contains) | 1.1 / 1.2 | 1.1 / 1.2 | 1.1 / 1.2 | 1 |
| Dashboard universal metrics | 87.8 / 103.9 | 75.7 / 81.7 | 47.8 / 49.1 | 15 |
| Dashboard drill-down (level 1) | 239.1 / 270.6 | 179.4 / 192.5 | 109.1 / 124.7 | 209 / 107 / 73 |
| Report, no grouping | 199.9 / 210.5 | 163.2 / 169.8 | 103.6 / 109.5 | 10 |
| Report, 1-dimension | 202.5 / 209.7 | 162.9 / 172.5 | 105.1 / 112.1 | 10 |
| Report, 2-dimension | 204.5 / 214.8 | 163.9 / 171.5 | 105.8 / 114.7 | 10 |
| Report, 3-dimension | 210.3 / 216.8 | 169.1 / 174.0 | 106.0 / 112.0 | 10 |
| Report, 4-dimension | — (3 levels) | 167.1 / 174.1 | 106.7 / 113.2 | 10 |
| XLSX template generation | 8.2 / 8.3 | 10.1 / 11.9 | 11.8 / 13.8 | 1 |
| XLSX hierarchy export | 889.2 / 907.1 | 894.1 / 903.3 | 658.5 / 677.8 | 3 |
| XLSX import validation | 5.7 / 6.0 | 6.8 / 6.9 | 7.8 / 7.8 | 4 |

Format: **P50 / P95**. P99 and max are in the command's own output; they sit
within ~10% of P95 for every operation, so the distribution has no long tail
at this dataset size.

Peak process memory across the whole run: **92.5 MB**. Per-operation peak
delta was 0–2 MB for everything except dashboard drill-down (2 MB).

### Depth does not cost what you would expect

PERF7 is consistently **faster** than PERF3 despite being deeper. That is not
a paradox: PERF3 has 3,768 nodes to PERF7's 2,264, and cost tracks node and
row count, not depth. Depth adds a handful of queries to breadcrumbs and
filters (one per level) and nothing measurable elsewhere. **This is the
central performance finding: the engine's cost scales with data volume, not
with hierarchy depth.**

## Two N+1 defects found and fixed by this measurement

The first run exposed problems no unit test would have caught:

| Operation | Before | After | Change |
|---|---|---|---|
| Report generation (PERF7) | 3,464 queries, 1,075 ms | **10 queries, 104 ms** | 346× fewer queries, 10.3× faster |
| XLSX hierarchy export (PERF7) | 6,914 queries, 2,930 ms | **3 queries, 658 ms** | 2,300× fewer queries, 4.5× faster |
| XLSX hierarchy export (PERF5) | 8,642 queries, 3,717 ms | **3 queries, 894 ms** | 2,880× fewer queries, 4.2× faster |

**Cause.** `ComplianceNode::ancestors()` walks `->parent` lazily — correct,
but one query per hop per node. A report over 1,700 rows on a 7-level tree
therefore issued thousands of statements.

**Fix.** `App\Services\HierarchyPathResolver` loads a program's nodes once and
resolves every ancestor chain in memory. Reads now scale with node count
rather than row count × depth. `ancestors()` is unchanged and still correct
for single-node use.

## Execution plans

Captured with real bindings (`EXPLAIN` with the command's `--plans` flag
substitutes NULL placeholders and is not meaningful; these were run
directly). All access paths use an index — no full table scans:

| Query | type | key | rows |
|---|---|---|---|
| Program + cycle tree scan (path resolver, export) | `ref` | `compliance_nodes_program_cycle_id_foreign` | 1,132 |
| Children of a node (hierarchy browse) | `ref` | `compliance_nodes_parent_id_foreign` | 3 |
| Nodes on one level (dashboard grouping) | `ref` | `compliance_nodes_hierarchy_level_id_foreign` | 8 |
| Recursive CTE subtree (recursive leg) | `ref` | `compliance_nodes_parent_id_foreign` | 3 |
| Assignments by cycle (report) | `ref` | `requirement_assignments_program_cycle_id_foreign` | 288 |

The program+cycle scan reports `Using filesort` for its `ORDER BY level, code`.
At ~1,100 rows this is not worth a covering index; revisit if a single
program's node count reaches six figures.

## Known remaining cost

**Dashboard drill-down issues one metrics query set per group** (209 queries
for PERF3's 12 first-level groups). At 239 ms P50 this is acceptable, but it
is O(groups) and would degrade with a very wide first level — a program with
100 top-level nodes would issue ~1,500 queries. Fixing it means aggregating
all groups in one grouped query rather than looping `universalMetrics()`.
**Not done; recorded as known technical debt rather than left implicit.**

## What is NOT covered

- No concurrency or load testing. Every measurement is single-threaded.
- No network latency; API and database are on the same host.
- No measurement above 9,336 nodes.
- Frontend rendering time is not measured — these are backend numbers only.
