<?php

namespace App\Http\Controllers\Api\Programs;

use App\Http\Controllers\Controller;
use App\Models\AssessmentCycle;
use App\Models\ComplianceNode;
use App\Models\ComplianceProgram;
use App\Services\HierarchyDashboardService;
use App\Services\HierarchyDefinitionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Hierarchy-driven dashboard: universal metrics plus metadata-driven
 * drill-down. Replaces the previous program dashboard, which grouped only
 * by department and status and offered no hierarchy view at all (audit
 * finding H1).
 *
 * There is deliberately no per-program endpoint and no per-level endpoint —
 * the level is a parameter, resolved from the program's own structure.
 */
class HierarchyDashboardController extends Controller
{
    public function __construct(
        private readonly HierarchyDashboardService $dashboard,
        private readonly HierarchyDefinitionService $structures,
    ) {}

    /**
     * GET /programs/{program}/dashboard/metrics
     *
     * Universal metrics. Identical shape for a three-level and a six-level
     * program — nothing in the response names a hierarchy level.
     */
    public function metrics(Request $request): JsonResponse
    {
        $program = $this->program($request);
        $cycle = $this->resolveCycle($program, $request);
        $rootNodeId = $this->resolveNodeId($program, $request->query('node_id'));

        return response()->json([
            'success' => true,
            'data' => [
                'cycle' => $cycle ? ['id' => $cycle->id, 'name' => $cycle->name] : null,
                'metrics' => $this->dashboard->universalMetrics($program, $cycle, $rootNodeId, $request->user()),
                'supported_metrics' => HierarchyDashboardService::SUPPORTED_METRICS,
            ],
        ]);
    }

    /**
     * GET /programs/{program}/dashboard/levels
     *
     * Which levels this program exposes in its dashboard, in order. The
     * frontend builds its drill-down path from this, not from a constant.
     */
    public function levels(Request $request): JsonResponse
    {
        $program = $this->program($request);

        $levels = collect($this->dashboard->dashboardLevels($program))->map(fn ($l) => [
            'key' => $l->key,
            'name' => $l->name,
            'plural_name' => $l->plural_name,
            'level_order' => $l->level_order,
        ])->values();

        return response()->json(['success' => true, 'data' => $levels]);
    }

    /**
     * GET /programs/{program}/dashboard/by-level/{levelKey}?node_id=&cycle_id=
     *
     * Progress grouped by one level, optionally within one parent node's
     * subtree. `next_level` tells the client where a click drills to, so
     * drill-down needs no client-side knowledge of the hierarchy.
     */
    public function byLevel(Request $request, string $program, string $levelKey): JsonResponse
    {
        $resolved = $this->program($request);

        $level = $this->structures->levelByKey($resolved, $levelKey);
        if (! $level) {
            return response()->json(['success' => false, 'message' => 'Hierarchy level not found.'], 404);
        }
        if (! $level->appears_in_dashboard) {
            return response()->json([
                'success' => false,
                'message' => "Level '{$levelKey}' is not enabled for this program's dashboard.",
            ], 422);
        }

        $cycle = $this->resolveCycle($resolved, $request);
        $parentNodeId = $this->resolveNodeId($resolved, $request->query('node_id'));

        return response()->json([
            'success' => true,
            'data' => $this->dashboard->groupByLevel($resolved, $level, $cycle, $parentNodeId, $request->user()),
        ]);
    }

    // ─── Helpers ─────────────────────────────────────────────────────────────

    private function resolveCycle(ComplianceProgram $program, Request $request): ?AssessmentCycle
    {
        // A cycle id from another program must not be readable through this
        // program's route (the same IDOR guard ProgramReportController uses).
        if ($request->filled('cycle_id')) {
            return AssessmentCycle::where('id', $request->query('cycle_id'))
                ->where('compliance_program_id', $program->id)->first();
        }

        return AssessmentCycle::where('compliance_program_id', $program->id)
            ->where('is_current', true)->first();
    }

    /** Null unless the node exists AND belongs to this program. */
    private function resolveNodeId(ComplianceProgram $program, mixed $nodeId): ?int
    {
        if (! $nodeId) {
            return null;
        }

        return ComplianceNode::where('id', $nodeId)
            ->where('compliance_program_id', $program->id)
            ->value('id');
    }

    private function program(Request $request): ComplianceProgram
    {
        return $request->attributes->get('compliance_program');
    }
}
