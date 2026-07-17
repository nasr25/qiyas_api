<?php

namespace App\Http\Controllers\Api\Programs;

use App\Http\Controllers\Controller;
use App\Models\AssessmentCycle;
use App\Models\ComplianceProgram;
use App\Models\Department;
use App\Models\Document;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Program-scoped dashboard. Unlike the legacy /api/v1/dashboard (which
 * assumes a single implicit program), every query here is filtered by the
 * resolved ComplianceProgram, so this remains correct once a second program
 * with its own active cycle exists.
 */
class ProgramDashboardController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        /** @var ComplianceProgram $program */
        $program = $request->attributes->get('compliance_program');

        $cycle = $request->cycle_id
            ? AssessmentCycle::where('compliance_program_id', $program->id)->find($request->cycle_id)
            : AssessmentCycle::where('compliance_program_id', $program->id)->where('is_current', true)->first()
                ?? AssessmentCycle::where('compliance_program_id', $program->id)->latest()->first();

        $stats = $this->documentStats($program->id, $cycle?->id);
        $departments = Department::active()->get()->map(function ($dept) use ($program, $cycle) {
            $deptStats = $this->documentStats($program->id, $cycle?->id, $dept->id);

            return [
                'id' => $dept->id,
                'name_ar' => $dept->name_ar,
                'name_en' => $dept->name_en,
                'stats' => $deptStats,
                'completion_rate' => $this->completionRate($deptStats),
            ];
        });

        return response()->json([
            'success' => true,
            'data' => [
                'program' => ['id' => $program->id, 'code' => $program->code, 'name' => $program->name],
                'cycle' => $cycle ? [
                    'id' => $cycle->id,
                    'name' => $cycle->name,
                    'year' => $cycle->year,
                    'status' => $cycle->status,
                    'end_date' => $cycle->end_date?->toDateString(),
                    'final_score' => $cycle->final_score,
                ] : null,
                'stats' => $stats,
                'completion_rate' => $this->completionRate($stats),
                'departments' => $departments,
            ],
        ]);
    }

    private function documentStats(int $programId, ?int $cycleId, ?int $departmentId = null): array
    {
        $counts = Document::where('compliance_program_id', $programId)
            ->when($cycleId, fn ($q) => $q->where('cycle_id', $cycleId))
            ->when($departmentId, fn ($q) => $q->where('department_id', $departmentId))
            ->selectRaw('status, count(*) as count')
            ->groupBy('status')
            ->pluck('count', 'status')
            ->toArray();

        return [
            'total' => array_sum($counts),
            'draft' => $counts['draft'] ?? 0,
            'under_review' => $counts['under_review'] ?? 0,
            'approved' => $counts['approved'] ?? 0,
            'rejected' => $counts['rejected'] ?? 0,
            'overdue' => $counts['overdue'] ?? 0,
        ];
    }

    private function completionRate(array $stats): float
    {
        if ($stats['total'] === 0) {
            return 0;
        }

        return round(($stats['approved'] / $stats['total']) * 100, 1);
    }
}
