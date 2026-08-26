<?php

namespace App\Services;

use App\Models\AssessmentCycle;
use App\Models\ComplianceNode;
use App\Models\ComplianceProgram;
use App\Models\EvidenceSubmission;
use App\Models\ExtensionRequest;
use App\Models\HierarchyLevelDefinition;
use App\Models\RequirementAssignment;
use App\Models\SlaInstance;
use App\Models\User;

/**
 * Dashboard metrics for the dynamic hierarchy engine, split exactly as the
 * Phase B brief requires:
 *
 *   1. UNIVERSAL metrics — identical for every program at every depth.
 *      They count assignments, submissions, SLA instances and extension
 *      requests, none of which know anything about Perspectives, Domains or
 *      Controls. A three-level Sumoud and a six-level NDMO produce the same
 *      metric set.
 *
 *   2. HIERARCHY drill-down — grouped by whichever level the program marked
 *      `appears_in_dashboard`, with the next drill level resolved from the
 *      structure rather than hard-coded. There is one implementation, not
 *      one per program (audit finding H1, which found no grouping at all).
 *
 * Every query is program-scoped, and department scoping is applied for
 * users limited to their own department, so a hierarchy filter can never
 * widen a user's visibility (brief: "Dashboard and Report Security").
 */
class HierarchyDashboardService
{
    /** Metric keys a Program Manager may choose from. Never free-form SQL. */
    public const SUPPORTED_METRICS = [
        'count_assessable', 'count_assigned', 'count_unassigned',
        'count_draft', 'count_pending_department_manager', 'count_pending_auditor',
        'count_pending_program_manager', 'count_returned', 'count_approved',
        'count_overdue', 'count_due_soon',
        'sla_warning_count', 'sla_breach_count',
        'extension_request_count',
        'completion_percentage', 'workflow_approval_percentage', 'evidence_completion_percentage',
    ];

    private const DUE_SOON_DAYS = 7;

    public function __construct(private readonly HierarchyDefinitionService $structures) {}

    /**
     * Hierarchy-neutral metrics for a program, optionally narrowed to one
     * subtree and/or one cycle.
     *
     * @param  ?int  $rootNodeId  restrict to this node and its descendants
     */
    public function universalMetrics(
        ComplianceProgram $program,
        ?AssessmentCycle $cycle = null,
        ?int $rootNodeId = null,
        ?User $viewer = null,
    ): array {
        $nodeIds = $this->scopedNodeIds($program, $cycle, $rootNodeId);
        $departmentId = $this->departmentScopeFor($viewer, $program);

        $assessableIds = $this->assessableNodeIds($program, $nodeIds);
        $totalAssessable = count($assessableIds);

        $assignments = RequirementAssignment::where('compliance_program_id', $program->id)
            ->whereIn('compliance_node_id', $assessableIds ?: [0])
            ->when($departmentId, fn ($q) => $q->where('department_id', $departmentId))
            ->where('status', '!=', 'reassigned');

        $assignedNodeIds = (clone $assignments)->distinct()->pluck('compliance_node_id');
        $assignmentIds = (clone $assignments)->pluck('id')->all();

        $byStatus = EvidenceSubmission::whereIn('requirement_assignment_id', $assignmentIds ?: [0])
            ->selectRaw('status, COUNT(*) as c')->groupBy('status')->pluck('c', 'status');

        $completed = $this->completedAssignmentIds($assignmentIds);

        $overdue = (clone $assignments)->whereDate('effective_due_date', '<', now())
            ->whereNotIn('id', $completed ?: [0])->count();
        $dueSoon = (clone $assignments)
            ->whereBetween('effective_due_date', [now()->toDateString(), now()->addDays(self::DUE_SOON_DAYS)->toDateString()])
            ->whereNotIn('id', $completed ?: [0])->count();

        $slaWarnings = SlaInstance::whereIn('requirement_assignment_id', $assignmentIds ?: [0])
            ->where('status', 'active')->whereNotNull('due_at')
            ->whereBetween('due_at', [now(), now()->addDays(self::DUE_SOON_DAYS)])->count();
        $slaBreaches = SlaInstance::whereIn('requirement_assignment_id', $assignmentIds ?: [0])
            ->whereIn('status', ['breached', 'completed_after_sla'])->count();

        $extensions = ExtensionRequest::where('compliance_program_id', $program->id)
            ->whereIn('requirement_assignment_id', $assignmentIds ?: [0])->count();

        $approved = (int) ($byStatus['approved'] ?? 0);
        $assignedCount = $assignedNodeIds->count();
        $withEvidence = EvidenceSubmission::whereIn('requirement_assignment_id', $assignmentIds ?: [0])
            ->distinct()->count('requirement_assignment_id');

        return [
            'count_assessable' => $totalAssessable,
            'count_assigned' => $assignedCount,
            'count_unassigned' => max(0, $totalAssessable - $assignedCount),
            'count_draft' => (int) ($byStatus['draft'] ?? 0),
            'count_pending_department_manager' => (int) ($byStatus['pending_department_manager'] ?? 0),
            'count_pending_auditor' => (int) ($byStatus['pending_auditor'] ?? 0),
            'count_pending_program_manager' => (int) ($byStatus['pending_program_manager'] ?? 0),
            'count_returned' => (int) ($byStatus['returned_for_revision'] ?? 0),
            'count_approved' => $approved,
            'count_overdue' => $overdue,
            'count_due_soon' => $dueSoon,
            'sla_warning_count' => $slaWarnings,
            'sla_breach_count' => $slaBreaches,
            'extension_request_count' => $extensions,
            'completion_percentage' => $this->percentage($approved, $totalAssessable),
            'workflow_approval_percentage' => $this->percentage($approved, $assignedCount),
            'evidence_completion_percentage' => $this->percentage($withEvidence, $assignedCount),
        ];
    }

    /**
     * Progress grouped by one hierarchy level — "Progress by Perspective",
     * "Progress by Domain", whatever the program calls it.
     *
     * @param  ?int  $parentNodeId  drill into this node's subtree only
     * @return array{level:array,rows:array,next_level:?array}
     */
    public function groupByLevel(
        ComplianceProgram $program,
        HierarchyLevelDefinition $level,
        ?AssessmentCycle $cycle = null,
        ?int $parentNodeId = null,
        ?User $viewer = null,
    ): array {
        $scopeIds = $this->scopedNodeIds($program, $cycle, $parentNodeId);

        $groupNodes = ComplianceNode::where('compliance_program_id', $program->id)
            ->where('hierarchy_level_id', $level->id)
            ->when($scopeIds !== null, fn ($q) => $q->whereIn('id', $scopeIds ?: [0]))
            ->orderBy('sort_order')->orderBy('code')
            ->get();

        // Each group's metrics are the universal metrics of its own subtree,
        // so one code path answers "progress by Perspective" and "progress by
        // Subrequirement" alike, with no per-level branch.
        $rows = $groupNodes->map(fn (ComplianceNode $node) => [
            'node' => [
                'id' => $node->id,
                'code' => $node->code,
                'name' => $node->name,
                'level_key' => $node->hierarchyLevel?->key,
            ],
            'metrics' => $this->universalMetrics($program, $cycle, $node->id, $viewer),
        ])->values()->all();

        $next = $this->nextDashboardLevel($program, $level);

        return [
            'level' => [
                'key' => $level->key,
                'name' => $level->name,
                'plural_name' => $level->plural_name,
                'level_order' => $level->level_order,
            ],
            'rows' => $rows,
            'next_level' => $next ? ['key' => $next->key, 'name' => $next->name] : null,
        ];
    }

    /** Levels the program chose to expose in its dashboard, shallowest first. */
    public function dashboardLevels(ComplianceProgram $program): array
    {
        return collect($this->structures->levels($program))
            ->where('appears_in_dashboard', true)->values()->all();
    }

    /** The next dashboard-visible level below $level, or null at the bottom. */
    public function nextDashboardLevel(ComplianceProgram $program, HierarchyLevelDefinition $level): ?HierarchyLevelDefinition
    {
        return collect($this->dashboardLevels($program))
            ->firstWhere(fn (HierarchyLevelDefinition $l) => $l->level_order > $level->level_order);
    }

    // ─── Internals ───────────────────────────────────────────────────────────

    /**
     * Node ids in scope: the whole program, or one subtree when drilling.
     * Returns null for "no node restriction" so callers can skip the clause.
     *
     * @return array<int,int>|null
     */
    private function scopedNodeIds(ComplianceProgram $program, ?AssessmentCycle $cycle, ?int $rootNodeId): ?array
    {
        if ($rootNodeId === null && $cycle === null) {
            return null;
        }

        if ($rootNodeId !== null) {
            $ids = ComplianceNode::subtreeIds($rootNodeId);

            // Re-scoped by program: a node id from another program must never
            // widen what this call can see.
            return ComplianceNode::where('compliance_program_id', $program->id)
                ->whereIn('id', $ids ?: [0])
                ->when($cycle, fn ($q) => $q->where('program_cycle_id', $cycle->id))
                ->pluck('id')->all();
        }

        return ComplianceNode::where('compliance_program_id', $program->id)
            ->where('program_cycle_id', $cycle->id)->pluck('id')->all();
    }

    /** @return array<int,int> */
    private function assessableNodeIds(ComplianceProgram $program, ?array $nodeIds): array
    {
        $assessableLevelIds = collect($this->structures->levels($program))
            ->where('is_assessable', true)->pluck('id');

        return ComplianceNode::where('compliance_program_id', $program->id)
            ->whereIn('hierarchy_level_id', $assessableLevelIds ?: [0])
            ->when($nodeIds !== null, fn ($q) => $q->whereIn('id', $nodeIds ?: [0]))
            ->pluck('id')->all();
    }

    /**
     * Department restriction for the viewer, or null when they may see the
     * whole program. Applied to every metric so a hierarchy filter cannot be
     * used to reach another department's data.
     */
    private function departmentScopeFor(?User $viewer, ComplianceProgram $program): ?int
    {
        if (! $viewer || $viewer->isPlatformSuperAdmin()) {
            return null;
        }

        foreach (['program-manager', 'auditor'] as $wideRole) {
            if ($viewer->hasProgramRole($program, $wideRole)) {
                return null;
            }
        }

        if ($viewer->hasRole('executive')) {
            return null;
        }

        return $viewer->department_id;
    }

    /** Assignments whose evidence already reached `approved`. */
    private function completedAssignmentIds(array $assignmentIds): array
    {
        return EvidenceSubmission::whereIn('requirement_assignment_id', $assignmentIds ?: [0])
            ->where('status', 'approved')
            ->distinct()->pluck('requirement_assignment_id')->all();
    }

    private function percentage(int $part, int $whole): float
    {
        return $whole === 0 ? 0.0 : round(($part / $whole) * 100, 1);
    }
}
