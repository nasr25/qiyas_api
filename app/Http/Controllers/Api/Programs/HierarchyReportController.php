<?php

namespace App\Http\Controllers\Api\Programs;

use App\Http\Controllers\Controller;
use App\Models\AssessmentCycle;
use App\Models\ComplianceNode;
use App\Models\ComplianceProgram;
use App\Services\HierarchyDefinitionService;
use App\Services\HierarchyReportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Generic hierarchy reporting. One controller serves every program at every
 * depth: group-by dimensions, cascading filter options and export columns
 * are all derived from the program's own structure.
 *
 * Group-by keys are validated against the program's whitelist, so a caller
 * cannot smuggle an arbitrary column name into the grouping.
 */
class HierarchyReportController extends Controller
{
    public function __construct(
        private readonly HierarchyReportService $reports,
        private readonly HierarchyDefinitionService $structures,
    ) {}

    /** GET /programs/{program}/reports/dimensions */
    public function dimensions(Request $request): JsonResponse
    {
        $program = $this->program($request);

        return response()->json([
            'success' => true,
            'data' => [
                'dimensions' => $this->reports->availableDimensions($program),
                'columns' => $this->reports->columns($program),
            ],
        ]);
    }

    /**
     * GET /programs/{program}/reports/filter-options/{levelKey}?parent_node_id=
     *
     * The cascading part: options for one level, narrowed to a chosen
     * parent's subtree. Chaining these calls produces a filter chain of
     * whatever depth the program configured.
     */
    public function filterOptions(Request $request, string $program, string $levelKey): JsonResponse
    {
        $resolved = $this->program($request);

        $level = $this->structures->levelByKey($resolved, $levelKey);
        if (! $level) {
            return response()->json(['success' => false, 'message' => 'Hierarchy level not found.'], 404);
        }
        if (! $level->appears_in_filters) {
            return response()->json([
                'success' => false,
                'message' => "Level '{$levelKey}' is not enabled as a filter for this program.",
            ], 422);
        }

        return response()->json([
            'success' => true,
            'data' => $this->reports->filterOptions(
                $resolved,
                $level,
                $this->resolveNodeId($resolved, $request->query('parent_node_id')),
                $this->resolveCycle($resolved, $request),
            ),
        ]);
    }

    /** GET /programs/{program}/reports/hierarchy?group_by[]=&node_id=&department_id=&status= */
    public function hierarchy(Request $request): JsonResponse
    {
        $program = $this->program($request);

        $data = $request->validate([
            'group_by' => ['sometimes', 'array', 'max:4'],
            'group_by.*' => ['string', 'max:50'],
            'node_id' => ['sometimes', 'integer'],
            'department_id' => ['sometimes', 'integer'],
            'status' => ['sometimes', 'string', 'max:40'],
        ]);

        $groupBy = $data['group_by'] ?? [];
        foreach ($groupBy as $key) {
            if (! $this->reports->isValidDimension($program, $key)) {
                return response()->json([
                    'success' => false,
                    'message' => "'{$key}' is not a reportable dimension for this program.",
                ], 422);
            }
        }

        return response()->json([
            'success' => true,
            'data' => $this->reports->build(
                $program,
                $groupBy,
                [
                    // Re-resolved through the program so a foreign node id
                    // cannot be used to reach another program's rows.
                    'node_id' => $this->resolveNodeId($program, $data['node_id'] ?? null),
                    'department_id' => $data['department_id'] ?? null,
                    'status' => $data['status'] ?? null,
                ],
                $this->resolveCycle($program, $request),
                $request->user(),
            ),
        ]);
    }

    /**
     * GET /programs/{program}/reports/hierarchy/export
     *
     * CSV, streamed. Columns follow the program's structure, so the export
     * of a six-level program has six hierarchy column pairs without any
     * per-program exporter (audit finding C3).
     */
    public function export(Request $request): StreamedResponse
    {
        $program = $this->program($request);
        $columns = $this->reports->columns($program);
        $rows = $this->reports->exportRows(
            $program,
            ['node_id' => $this->resolveNodeId($program, $request->query('node_id'))],
            $this->resolveCycle($program, $request),
            $request->user(),
        );

        $filename = strtolower($program->code).'-hierarchy-report.csv';

        return response()->streamDownload(function () use ($columns, $rows) {
            $out = fopen('php://output', 'w');
            // BOM so Excel opens the Arabic labels as UTF-8.
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, array_column($columns, 'label'));
            foreach ($rows as $row) {
                fputcsv($out, $row);
            }
            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    // ─── Helpers ─────────────────────────────────────────────────────────────

    private function resolveCycle(ComplianceProgram $program, Request $request): ?AssessmentCycle
    {
        if ($request->filled('cycle_id')) {
            return AssessmentCycle::where('id', $request->query('cycle_id'))
                ->where('compliance_program_id', $program->id)->first();
        }

        return AssessmentCycle::where('compliance_program_id', $program->id)
            ->where('is_current', true)->first();
    }

    private function resolveNodeId(ComplianceProgram $program, mixed $nodeId): ?int
    {
        if (! $nodeId) {
            return null;
        }

        return ComplianceNode::where('id', $nodeId)
            ->where('compliance_program_id', $program->id)->value('id');
    }

    private function program(Request $request): ComplianceProgram
    {
        return $request->attributes->get('compliance_program');
    }
}
