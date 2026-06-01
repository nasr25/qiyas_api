<?php

namespace App\Http\Controllers\Api\Reports;

use App\Http\Controllers\Controller;
use App\Models\AssessmentCycle;
use App\Models\Department;
use App\Models\Document;
use App\Models\Standard;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

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
            'documents as total'        => fn($q) => $q->where('cycle_id', $cycle->id),
            'documents as approved'     => fn($q) => $q->where('cycle_id', $cycle->id)->where('status', 'approved'),
            'documents as under_review' => fn($q) => $q->where('cycle_id', $cycle->id)->where('status', 'under_review'),
            'documents as rejected'     => fn($q) => $q->where('cycle_id', $cycle->id)->where('status', 'rejected'),
            'documents as draft'        => fn($q) => $q->where('cycle_id', $cycle->id)->where('status', 'draft'),
            'documents as overdue'      => fn($q) => $q->where('cycle_id', $cycle->id)->where('status', 'overdue'),
        ])->get()->map(fn($dept) => [
            'id'              => $dept->id,
            'name_ar'         => $dept->name_ar,
            'name_en'         => $dept->name_en,
            'total'           => $dept->total,
            'approved'        => $dept->approved,
            'under_review'    => $dept->under_review,
            'rejected'        => $dept->rejected,
            'draft'           => $dept->draft,
            'overdue'         => $dept->overdue,
            'completion_rate' => $dept->total > 0 ? round(($dept->approved / $dept->total) * 100, 1) : 0,
        ]);

        return response()->json([
            'success' => true,
            'data'    => [
                'cycle'       => ['id' => $cycle->id, 'name' => $cycle->name, 'year' => $cycle->year],
                'departments' => $data,
            ],
        ]);
    }

    /**
     * Standard-level completion report.
     * GET /api/v1/reports/by-standard
     */
    public function byStandard(Request $request): JsonResponse
    {
        $request->validate([
            'cycle_id'      => ['required', 'exists:assessment_cycles,id'],
            'department_id' => ['nullable', 'exists:departments,id'],
        ]);

        $cycle = AssessmentCycle::findOrFail($request->cycle_id);

        $standards = Standard::with('evidenceRequirements')
            ->where('cycle_id', $cycle->id)
            ->when($request->department_id, fn($q) => $q->whereHas('departments', fn($dq) =>
                $dq->where('departments.id', $request->department_id)
            ))
            ->get()
            ->map(function ($standard) use ($cycle, $request) {
                $reqIds = $standard->evidenceRequirements->pluck('id');

                $docStats = Document::whereIn('requirement_id', $reqIds)
                    ->where('cycle_id', $cycle->id)
                    ->when($request->department_id, fn($q) => $q->where('department_id', $request->department_id))
                    ->selectRaw('status, count(*) as count')
                    ->groupBy('status')
                    ->pluck('count', 'status')
                    ->toArray();

                $total    = array_sum($docStats);
                $approved = $docStats['approved'] ?? 0;

                return [
                    'id'              => $standard->id,
                    'number'          => $standard->standard_number,
                    'name_ar'         => $standard->name_ar,
                    'name_en'         => $standard->name_en,
                    'requirements'    => $standard->evidenceRequirements->count(),
                    'total_docs'      => $total,
                    'approved'        => $approved,
                    'under_review'    => $docStats['under_review'] ?? 0,
                    'rejected'        => $docStats['rejected'] ?? 0,
                    'draft'           => $docStats['draft'] ?? 0,
                    'completion_rate' => $total > 0 ? round(($approved / $total) * 100, 1) : 0,
                ];
            });

        return response()->json([
            'success' => true,
            'data'    => [
                'cycle'     => ['id' => $cycle->id, 'name' => $cycle->name],
                'standards' => $standards,
            ],
        ]);
    }

    /**
     * Status-filtered document list for export.
     * GET /api/v1/reports/by-status
     */
    public function byStatus(Request $request): JsonResponse
    {
        $request->validate([
            'cycle_id'      => ['required', 'exists:assessment_cycles,id'],
            'status'        => ['required', 'in:draft,under_review,approved,rejected,overdue'],
            'department_id' => ['nullable', 'exists:departments,id'],
        ]);

        $documents = Document::with(['requirement.standard', 'department', 'submitter', 'reviewer'])
            ->where('cycle_id', $request->cycle_id)
            ->where('status', $request->status)
            ->when($request->department_id, fn($q) => $q->where('department_id', $request->department_id))
            ->latest()
            ->paginate($request->get('per_page', 50));

        return response()->json([
            'success' => true,
            'data'    => $documents->map(fn($d) => [
                'id'              => $d->id,
                'title'           => $d->title,
                'status'          => $d->status,
                'department'      => $d->department?->name_ar,
                'standard'        => $d->requirement?->standard?->name_ar,
                'requirement'     => $d->requirement?->title_ar,
                'submitter'       => $d->submitter?->name,
                'reviewer'        => $d->reviewer?->name,
                'submitted_at'    => $d->submitted_at?->toDateString(),
                'reviewed_at'     => $d->reviewed_at?->toDateString(),
                'rejection_reason' => $d->rejection_reason,
            ]),
            'meta'    => [
                'current_page' => $documents->currentPage(),
                'last_page'    => $documents->lastPage(),
                'total'        => $documents->total(),
            ],
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

        $docCounts = Document::where('cycle_id', $cycle->id)
            ->selectRaw('status, count(*) as count')
            ->groupBy('status')
            ->pluck('count', 'status')
            ->toArray();

        $total    = array_sum($docCounts);
        $approved = $docCounts['approved'] ?? 0;

        return response()->json([
            'success' => true,
            'data'    => [
                'cycle'           => [
                    'id'          => $cycle->id,
                    'name'        => $cycle->name,
                    'year'        => $cycle->year,
                    'status'      => $cycle->status,
                    'start_date'  => $cycle->start_date?->toDateString(),
                    'end_date'    => $cycle->end_date?->toDateString(),
                    'final_score' => $cycle->final_score,
                ],
                'standards_count'    => $cycle->standards->count(),
                'departments_count'  => Department::whereHas('documents', fn($q) => $q->where('cycle_id', $cycle->id))->count(),
                'document_stats'     => array_merge($docCounts, ['total' => $total]),
                'completion_rate'    => $total > 0 ? round(($approved / $total) * 100, 1) : 0,
            ],
        ]);
    }
}
