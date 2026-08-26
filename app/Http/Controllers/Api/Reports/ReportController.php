<?php

namespace App\Http\Controllers\Api\Reports;

use App\Http\Controllers\Controller;
use App\Models\AssessmentCycle;
use App\Models\Department;
use App\Models\RequirementAssignment;
use App\Services\EvidenceStatusCounts;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Report generation endpoints — returns data for export (Excel/PDF) and charts.
 */
class ReportController extends Controller
{
    /**
     * Department-level completion report.
     * GET /api/v1/reports/by-department
     */
    public function byDepartment(Request $request): JsonResponse
    {
        $request->validate([
            'cycle_id' => ['required', 'exists:assessment_cycles,id'],
        ]);

        $cycle = AssessmentCycle::findOrFail($request->cycle_id);

        $data = Department::withCount([
            'documents as total' => fn ($q) => $q->where('cycle_id', $cycle->id),
            'documents as approved' => fn ($q) => $q->where('cycle_id', $cycle->id)->where('status', 'approved'),
            'documents as under_review' => fn ($q) => $q->where('cycle_id', $cycle->id)->where('status', 'under_review'),
            'documents as rejected' => fn ($q) => $q->where('cycle_id', $cycle->id)->where('status', 'rejected'),
            'documents as draft' => fn ($q) => $q->where('cycle_id', $cycle->id)->where('status', 'draft'),
            'documents as overdue' => fn ($q) => $q->where('cycle_id', $cycle->id)->where('status', 'overdue'),
        ])->get()->map(fn ($dept) => [
            'id' => $dept->id,
            'name_ar' => $dept->name_ar,
            'name_en' => $dept->name_en,
            'total' => $dept->total,
            'approved' => $dept->approved,
            'under_review' => $dept->under_review,
            'rejected' => $dept->rejected,
            'draft' => $dept->draft,
            'overdue' => $dept->overdue,
            'completion_rate' => $dept->total > 0 ? round(($dept->approved / $dept->total) * 100, 1) : 0,
        ]);

        return response()->json([
            'success' => true,
            'data' => [
                'cycle' => ['id' => $cycle->id, 'name' => $cycle->name, 'year' => $cycle->year],
                'departments' => $data,
            ],
        ]);
    }

    /**
     * Per-requirement completion report.
     *
     * Rewritten onto ComplianceNode + EvidenceSubmission when the legacy
     * Standard authoring path was retired. The route name `by-standard` is
     * kept for API compatibility, but "standard" here means the program's
     * own assessable level — Criterion for Qiyas, Control for ECC — and each
     * row carries its full hierarchy path instead of a fixed two-column
     * projection.
     *
     * GET /api/v1/reports/by-standard
     */
    public function byStandard(Request $request): JsonResponse
    {
        $request->validate([
            'cycle_id' => ['required', 'exists:assessment_cycles,id'],
            'department_id' => ['nullable', 'exists:departments,id'],
        ]);

        $cycle = AssessmentCycle::findOrFail($request->cycle_id);

        $assignments = RequirementAssignment::where('program_cycle_id', $cycle->id)
            ->when($request->department_id, fn ($q) => $q->where('department_id', $request->department_id))
            ->where('status', '!=', 'reassigned')
            ->with(['node.hierarchyLevel', 'submissions'])
            ->get();

        $rows = $assignments->groupBy('compliance_node_id')->map(function ($group) {
            $node = $group->first()->node;
            $submissions = $group->flatMap->submissions;

            $byStatus = $submissions->groupBy('status')->map->count();
            $total = $submissions->count();
            $approved = (int) ($byStatus['approved'] ?? 0);

            return [
                'id' => $node?->id,
                'number' => $node?->code,
                'name_ar' => $node?->name_ar,
                'name_en' => $node?->name_en,
                'level_key' => $node?->hierarchyLevel?->key,
                'level_name' => $node?->hierarchyLevel?->name,
                'requirements' => $group->count(),
                'total_docs' => $total,
                'approved' => $approved,
                'under_review' => (int) ($byStatus['pending_department_manager'] ?? 0)
                    + (int) ($byStatus['pending_auditor'] ?? 0)
                    + (int) ($byStatus['pending_program_manager'] ?? 0),
                'rejected' => (int) ($byStatus['returned_for_revision'] ?? 0),
                'completion_rate' => $total > 0 ? round(($approved / $total) * 100, 1) : 0,
            ];
        })->values();

        return response()->json([
            'success' => true,
            'data' => ['cycle' => $cycle->name, 'standards' => $rows],
        ]);
    }

    public function byStatus(Request $request): JsonResponse
    {
        $request->validate([
            'cycle_id' => ['nullable', 'exists:assessment_cycles,id'],
            'department_id' => ['nullable', 'exists:departments,id'],
        ]);

        // Distribution of evidence across all statuses (counts + percentages).
        // Status names are the legacy five the report has always published;
        // EvidenceStatusCounts maps the per-stage vocabulary onto them.
        $counts = app(EvidenceStatusCounts::class)->for([
            'cycle_id' => $request->cycle_id,
            'department_id' => $request->department_id,
        ]);

        $total = $counts['total'];

        $statuses = collect(['draft', 'under_review', 'approved', 'rejected', 'overdue'])
            ->map(fn ($s) => [
                'status' => $s,
                'count' => (int) ($counts[$s] ?? 0),
                'percentage' => $total > 0 ? round((($counts[$s] ?? 0) / $total) * 100, 1) : 0,
            ])->values();

        return response()->json([
            'success' => true,
            'data' => ['statuses' => $statuses, 'total' => $total],
        ]);
    }

    /**
     * Summary statistics for a cycle.
     * GET /api/v1/reports/cycle-summary
     */
    public function cycleSummary(Request $request): JsonResponse
    {
        $request->validate(['cycle_id' => ['required', 'exists:assessment_cycles,id']]);

        $cycle = AssessmentCycle::with('standards')->findOrFail($request->cycle_id);

        $docCounts = app(EvidenceStatusCounts::class)->for(['cycle_id' => $cycle->id]);

        $total = $docCounts['total'];
        $approved = $docCounts['approved'];

        return response()->json([
            'success' => true,
            'data' => [
                'cycle' => [
                    'id' => $cycle->id,
                    'name' => $cycle->name,
                    'year' => $cycle->year,
                    'status' => $cycle->status,
                    'start_date' => $cycle->start_date?->toDateString(),
                    'end_date' => $cycle->end_date?->toDateString(),
                    'final_score' => $cycle->final_score,
                ],
                'standards_count' => $cycle->standards->count(),
                'departments_count' => Department::whereHas('documents', fn ($q) => $q->where('cycle_id', $cycle->id))->count(),
                'document_stats' => array_merge($docCounts, ['total' => $total]),
                'completion_rate' => $total > 0 ? round(($approved / $total) * 100, 1) : 0,
            ],
        ]);
    }
}
