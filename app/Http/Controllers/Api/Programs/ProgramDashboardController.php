<?php

namespace App\Http\Controllers\Api\Programs;

use App\Http\Controllers\Controller;
use App\Models\AssessmentCycle;
use App\Models\ComplianceNode;
use App\Models\ComplianceProgram;
use App\Models\Department;
use App\Models\EvidenceSubmission;
use App\Models\ExtensionRequest;
use App\Models\RequirementAssignment;
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

                // Flattened tiles the dashboard screen renders. These used to
                // come from the unscoped /dashboard endpoint, which mixed all
                // programs together on a program-scoped page — a scoping bug
                // that the legacy endpoint's retirement also fixes.
                'pending_reviews' => $stats['under_review'],
                'rejected_count' => $stats['rejected'],
                'approved_today' => $stats['approved'],
                'overdue_count' => $stats['overdue'],
                'standards_count' => ComplianceNode::where('compliance_program_id', $program->id)
                    ->whereHas('hierarchyLevel', fn ($q) => $q->where('is_assessable', true))
                    ->when($cycle, fn ($q) => $q->where('program_cycle_id', $cycle->id))
                    ->count(),
                'requirements_count' => RequirementAssignment::where('compliance_program_id', $program->id)
                    ->when($cycle, fn ($q) => $q->where('program_cycle_id', $cycle->id))
                    ->where('status', '!=', 'reassigned')->count(),
                'extension_requests' => ExtensionRequest::where('compliance_program_id', $program->id)
                    ->where('status', 'pending')->count(),
                'upcoming_deadlines' => RequirementAssignment::where('compliance_program_id', $program->id)
                    ->when($cycle, fn ($q) => $q->where('program_cycle_id', $cycle->id))
                    ->where('status', 'active')
                    ->whereBetween('effective_due_date', [now()->toDateString(), now()->addDays(30)->toDateString()])
                    ->count(),
                'recent_activity' => $this->recentActivity($program, $cycle),
            ],
        ]);
    }

    private function documentStats(int $programId, ?int $cycleId, ?int $departmentId = null): array
    {
        // EvidenceSubmission replaced the legacy Document model; the
        // response shape is preserved so existing dashboard consumers keep
        // working, with the review stages folded into `under_review`.
        $counts = EvidenceSubmission::where('compliance_program_id', $programId)
            ->when($cycleId, fn ($q) => $q->where('program_cycle_id', $cycleId))
            ->when($departmentId, fn ($q) => $q->where('department_id', $departmentId))
            ->selectRaw('status, count(*) as count')
            ->groupBy('status')
            ->pluck('count', 'status')
            ->toArray();

        $overdue = RequirementAssignment::where('compliance_program_id', $programId)
            ->when($cycleId, fn ($q) => $q->where('program_cycle_id', $cycleId))
            ->when($departmentId, fn ($q) => $q->where('department_id', $departmentId))
            ->where('status', 'active')
            ->whereDate('effective_due_date', '<', now())
            ->count();

        return [
            'total' => array_sum($counts),
            'draft' => $counts['draft'] ?? 0,
            'under_review' => ($counts['pending_department_manager'] ?? 0)
                + ($counts['pending_auditor'] ?? 0)
                + ($counts['pending_program_manager'] ?? 0),
            'approved' => $counts['approved'] ?? 0,
            'rejected' => $counts['returned_for_revision'] ?? 0,
            'overdue' => $overdue,
        ];
    }

    /** Latest submissions, newest first — the activity feed on the dashboard. */
    private function recentActivity(ComplianceProgram $program, ?AssessmentCycle $cycle): array
    {
        return EvidenceSubmission::where('compliance_program_id', $program->id)
            ->when($cycle, fn ($q) => $q->where('program_cycle_id', $cycle->id))
            ->with(['node', 'department'])
            ->latest('updated_at')->limit(10)->get()
            ->map(fn (EvidenceSubmission $s) => [
                'id' => $s->id,
                'requirement' => $s->node?->code,
                'title' => $s->node?->name,
                'department' => $s->department?->name,
                'status' => $s->status,
                'updated_at' => $s->updated_at?->toIso8601String(),
            ])->all();
    }

    private function completionRate(array $stats): float
    {
        if ($stats['total'] === 0) {
            return 0;
        }

        return round(($stats['approved'] / $stats['total']) * 100, 1);
    }
}
