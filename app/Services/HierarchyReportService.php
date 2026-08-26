<?php

namespace App\Services;

use App\Models\AssessmentCycle;
use App\Models\ComplianceNode;
use App\Models\ComplianceProgram;
use App\Models\Department;
use App\Models\HierarchyLevelDefinition;
use App\Models\RequirementAssignment;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Generic hierarchy report builder.
 *
 * A Program Manager picks dimensions from a whitelist and the builder
 * groups by them. There is deliberately no free-form SQL, no arbitrary
 * column names and no expression language — the brief is explicit that a
 * Program Manager may select supported dimensions but never execute custom
 * queries.
 *
 * Replaces a report layer that could not group by ANY hierarchy level
 * (audit finding H2): the four legacy reports grouped only by department,
 * standard, status or cycle.
 *
 * Dimensions available to a program are derived from its own structure:
 * every level marked `appears_in_reports`, plus the fixed operational
 * dimensions below. Nesting one level inside another is what produces
 * "group by Domain, then Policy, then Standard" without a per-program
 * implementation.
 */
class HierarchyReportService
{
    /** Non-hierarchy dimensions every program shares. */
    public const OPERATIONAL_DIMENSIONS = ['department', 'status', 'employee', 'cycle'];

    public function __construct(
        private readonly HierarchyDefinitionService $structures,
        private readonly HierarchyDashboardService $dashboard,
    ) {}

    /**
     * Everything this program may group or filter by — the whitelist the API
     * validates against.
     *
     * @return array<int, array{key:string,name:string,type:string,level_order:?int}>
     */
    public function availableDimensions(ComplianceProgram $program): array
    {
        $levels = collect($this->structures->levels($program))
            ->where('appears_in_reports', true)
            ->map(fn (HierarchyLevelDefinition $l) => [
                'key' => $l->key,
                'name' => $l->name,
                'plural_name' => $l->plural_name,
                'type' => 'hierarchy',
                'level_order' => $l->level_order,
                'filterable' => (bool) $l->appears_in_filters,
            ])->values();

        $operational = collect(self::OPERATIONAL_DIMENSIONS)->map(fn (string $key) => [
            'key' => $key,
            'name' => $key,
            'plural_name' => $key,
            'type' => 'operational',
            'level_order' => null,
            'filterable' => true,
        ]);

        return $levels->concat($operational)->all();
    }

    public function isValidDimension(ComplianceProgram $program, string $key): bool
    {
        return collect($this->availableDimensions($program))->contains('key', $key);
    }

    /**
     * Cascading filter options for one hierarchy level, optionally narrowed
     * to a parent node's subtree.
     *
     * This is the whole of "cascading filters": the client asks for the
     * options of level N given a chosen node at level N-1, and the same code
     * answers for Qiyas's Axis and NDMO's Subrequirement alike.
     *
     * @return array<int, array{id:int,code:string,name:string}>
     */
    public function filterOptions(
        ComplianceProgram $program,
        HierarchyLevelDefinition $level,
        ?int $parentNodeId = null,
        ?AssessmentCycle $cycle = null,
    ): array {
        $scope = $parentNodeId !== null ? ComplianceNode::subtreeIds($parentNodeId) : null;

        return ComplianceNode::where('compliance_program_id', $program->id)
            ->where('hierarchy_level_id', $level->id)
            ->when($scope !== null, fn ($q) => $q->whereIn('id', $scope ?: [0]))
            ->when($cycle, fn ($q) => $q->where('program_cycle_id', $cycle->id))
            ->orderBy('sort_order')->orderBy('code')
            ->get()
            ->map(fn (ComplianceNode $n) => ['id' => $n->id, 'code' => $n->code, 'name' => $n->name])
            ->all();
    }

    /**
     * The report itself: assignment rows expanded with their full hierarchy
     * path, grouped by the requested dimensions in order.
     *
     * @param  array<int,string>  $groupBy  dimension keys, outermost first
     * @param  array<string,mixed>  $filters  node_id / department_id / status
     * @return array{columns:array,groups:array,totals:array,row_count:int}
     */
    public function build(
        ComplianceProgram $program,
        array $groupBy,
        array $filters = [],
        ?AssessmentCycle $cycle = null,
        ?User $viewer = null,
    ): array {
        $rows = $this->rows($program, $filters, $cycle, $viewer);

        return [
            'columns' => $this->columns($program),
            // Either {groups: [...]} when grouping, or {rows: [...]} when not.
            'grouping' => $this->group($rows, $groupBy, 0),
            'totals' => $this->totals($rows),
            'row_count' => $rows->count(),
        ];
    }

    /**
     * Export column definitions: one column per reportable level, in order,
     * labelled with the program's own terminology, followed by the fixed
     * operational columns. A three-level program yields three hierarchy
     * columns; a six-level program yields six. Nothing here is hard-coded
     * (audit findings H2, C3).
     *
     * @return array<int, array{key:string,label:string,type:string}>
     */
    public function columns(ComplianceProgram $program): array
    {
        $columns = [];

        foreach ($this->structures->levels($program) as $level) {
            if (! $level->appears_in_reports) {
                continue;
            }
            $columns[] = ['key' => "{$level->key}_code", 'label' => "{$level->name} — Code", 'type' => 'hierarchy'];
            $columns[] = ['key' => "{$level->key}_name", 'label' => $level->name, 'type' => 'hierarchy'];
        }

        foreach ([
            'department' => 'Department', 'employee' => 'Assigned Employee',
            'status' => 'Status', 'due_date' => 'Due Date',
            'sla' => 'SLA', 'evidence' => 'Evidence', 'approval' => 'Approval',
        ] as $key => $label) {
            $columns[] = ['key' => $key, 'label' => $label, 'type' => 'operational'];
        }

        return $columns;
    }

    /**
     * One flat row per assignment, carrying its whole ancestor chain keyed by
     * level. Grouping then reads a key off the row rather than re-querying.
     */
    private function rows(ComplianceProgram $program, array $filters, ?AssessmentCycle $cycle, ?User $viewer): Collection
    {
        $query = RequirementAssignment::where('compliance_program_id', $program->id)
            ->with(['node.hierarchyLevel', 'department', 'employee', 'submissions'])
            ->where('status', '!=', 'reassigned');

        if ($cycle) {
            $query->where('program_cycle_id', $cycle->id);
        }

        // Hierarchy filter: any node, any level, whole subtree.
        if (! empty($filters['node_id'])) {
            $subtree = ComplianceNode::subtreeIds((int) $filters['node_id']);
            $scoped = ComplianceNode::where('compliance_program_id', $program->id)
                ->whereIn('id', $subtree ?: [0])->pluck('id');
            $query->whereIn('compliance_node_id', $scoped ?: [0]);
        }
        if (! empty($filters['department_id'])) {
            $query->where('department_id', $filters['department_id']);
        }

        // Authorization is applied last and unconditionally, so no filter
        // combination can widen what the viewer may see.
        if ($departmentScope = $this->viewerDepartmentScope($viewer, $program)) {
            $query->where('department_id', $departmentScope);
        }

        // One query for every ancestor in the program, instead of one per
        // hop per row (measured: ~3,500 queries -> a handful).
        $paths = HierarchyPathResolver::forProgram($program, $cycle?->id);

        return $query->get()->map(function (RequirementAssignment $a) use ($paths) {
            $node = $a->node;
            $submission = $a->submissions->sortByDesc('version_number')->first();
            $status = $submission?->status ?? 'unassigned_evidence';

            $row = [
                '_assignment_id' => $a->id,
                'department' => $a->department?->name ?? '—',
                'employee' => $a->employee?->name ?? '—',
                'status' => $status,
                'due_date' => $a->effective_due_date?->toDateString() ?? '—',
                'sla' => $a->effective_due_date && $a->effective_due_date->isPast() ? 'overdue' : 'within',
                'evidence' => $submission ? 'yes' : 'no',
                'approval' => $status === 'approved' ? 'approved' : 'pending',
                'cycle' => $a->program_cycle_id,
            ];

            foreach ($node ? $paths->pathLabels($node->id) : [] as $entry) {
                $row["{$entry['level_key']}_code"] = $entry['code'];
                $row["{$entry['level_key']}_name"] = $entry['name'];
                $row[$entry['level_key']] = $entry['name'];
            }

            return $row;
        })->when(! empty($filters['status']), fn (Collection $c) => $c->where('status', $filters['status'])->values());
    }

    /**
     * Recursive grouping, one dimension per level of nesting. Always returns
     * a single-key array — `groups` while dimensions remain, `rows` at the
     * leaf — so a consumer can descend without knowing the nesting depth in
     * advance, which is the point when depth is per-program configuration.
     */
    private function group(Collection $rows, array $groupBy, int $depth): array
    {
        if ($depth >= count($groupBy)) {
            return ['rows' => $rows->values()->all()];
        }

        $dimension = $groupBy[$depth];

        $groups = $rows->groupBy(fn (array $row) => $row[$dimension] ?? '—')
            ->map(fn (Collection $group, $key) => [
                'key' => (string) $key,
                'dimension' => $dimension,
                'count' => $group->count(),
                'totals' => $this->totals($group),
            ] + $this->group($group, $groupBy, $depth + 1))
            ->values()->all();

        return ['groups' => $groups];
    }

    private function totals(Collection $rows): array
    {
        $total = $rows->count();
        $approved = $rows->where('approval', 'approved')->count();

        return [
            'total' => $total,
            'approved' => $approved,
            'overdue' => $rows->where('sla', 'overdue')->count(),
            'with_evidence' => $rows->where('evidence', 'yes')->count(),
            'completion_percentage' => $total === 0 ? 0.0 : round(($approved / $total) * 100, 1),
        ];
    }

    private function viewerDepartmentScope(?User $viewer, ComplianceProgram $program): ?int
    {
        if (! $viewer || $viewer->isPlatformSuperAdmin() || $viewer->hasRole('executive')) {
            return null;
        }
        foreach (['program-manager', 'auditor'] as $wide) {
            if ($viewer->hasProgramRole($program, $wide)) {
                return null;
            }
        }

        return $viewer->department_id;
    }

    /** Flat rows for CSV/XLSX export, in declared column order. */
    public function exportRows(ComplianceProgram $program, array $filters = [], ?AssessmentCycle $cycle = null, ?User $viewer = null): array
    {
        $columns = $this->columns($program);

        return $this->rows($program, $filters, $cycle, $viewer)
            ->map(fn (array $row) => collect($columns)->map(fn ($c) => $row[$c['key']] ?? '')->all())
            ->all();
    }

    public function departments(): Collection
    {
        return Department::active()->orderBy('name_en')->get();
    }
}
