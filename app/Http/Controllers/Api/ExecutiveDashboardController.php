<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AssessmentCycle;
use App\Models\ComplianceProgram;
use App\Models\Department;
use App\Models\Document;
use App\Models\EvidenceSubmission;
use App\Models\ExtensionRequest;
use App\Models\RequirementAssignment;
use App\Models\SlaInstance;
use App\Models\Standard;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Foundation for the enterprise-level dashboard that will eventually combine
 * every active compliance program. In Phase 1 only QIYAS is active, so this
 * naturally renders a single-program view — but every query here is built
 * around a collection of programs, not one hardcoded program, so adding
 * Sumoud/ECC/NDMO later requires no changes to this controller.
 *
 * Read-only. Access: Super Admin (full) and Executive Viewer (read-only),
 * enforced at the route level.
 */
class ExecutiveDashboardController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $programs = ComplianceProgram::active()->with('currentCycle')->orderBy('sort_order')->get();

        $programCards = $programs->map(function (ComplianceProgram $program) {
            $cycle = $program->currentCycle;
            $stats = $this->documentStats($program->id, $cycle?->id);

            return [
                'program' => ['id' => $program->id, 'code' => $program->code, 'name' => $program->name],
                'cycle' => $cycle ? ['id' => $cycle->id, 'name' => $cycle->name, 'status' => $cycle->status] : null,
                'stats' => $stats,
                'completion_rate' => $this->completionRate($stats),
            ];
        });

        $overall = $this->aggregate($programCards);

        return response()->json([
            'success' => true,
            'data' => [
                'overall_completion_rate' => $overall['completion_rate'],
                'overall_stats' => $overall['stats'],
                'programs' => $programCards,
                'department_comparison' => $this->departmentComparison($programs),
                'upcoming_deadlines' => $this->upcomingDeadlines($programs),
                // Phase 2 operational workflow metrics (RequirementAssignment /
                // EvidenceSubmission) — additive, does not replace the
                // Document-based stats above which remain valid for any
                // legacy data.
                'workflow' => $this->workflowMetrics($programs),
            ],
        ]);
    }

    private function workflowMetrics($programs): array
    {
        $programIds = $programs->pluck('id');

        $totalRequirements = Standard::whereIn('compliance_program_id', $programIds)->count();
        $approved = EvidenceSubmission::whereIn('compliance_program_id', $programIds)->where('status', 'approved')->count();
        $pending = EvidenceSubmission::whereIn('compliance_program_id', $programIds)
            ->whereIn('status', ['pending_department_manager', 'pending_auditor', 'pending_program_manager'])->count();
        $overdue = RequirementAssignment::whereIn('compliance_program_id', $programIds)->where('status', 'active')
            ->whereDate('effective_due_date', '<', now()->toDateString())->count();

        return [
            'compliance_percentage' => $totalRequirements > 0 ? round(($approved / $totalRequirements) * 100, 1) : 0,
            'approved_requirements' => $approved,
            'pending_requirements' => $pending,
            'overdue_requirements' => $overdue,
            'sla_breaches_by_stage' => SlaInstance::whereIn('compliance_program_id', $programIds)
                ->where('status', 'breached')->selectRaw('stage, count(*) as count')->groupBy('stage')->pluck('count', 'stage'),
            'extension_requests' => [
                'pending' => ExtensionRequest::whereIn('compliance_program_id', $programIds)->pending()->count(),
                'approved' => ExtensionRequest::whereIn('compliance_program_id', $programIds)->where('status', 'approved')->count(),
                'rejected' => ExtensionRequest::whereIn('compliance_program_id', $programIds)->where('status', 'rejected')->count(),
            ],
        ];
    }

    private function documentStats(int $programId, ?int $cycleId): array
    {
        $counts = Document::where('compliance_program_id', $programId)
            ->when($cycleId, fn ($q) => $q->where('cycle_id', $cycleId))
            ->selectRaw('status, count(*) as count')
            ->groupBy('status')
            ->pluck('count', 'status')
            ->toArray();

        return [
            'total' => array_sum($counts),
            'approved' => $counts['approved'] ?? 0,
            'under_review' => $counts['under_review'] ?? 0,
            'rejected' => $counts['rejected'] ?? 0,
            'draft' => $counts['draft'] ?? 0,
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

    private function aggregate($programCards): array
    {
        $stats = ['total' => 0, 'approved' => 0, 'under_review' => 0, 'rejected' => 0, 'draft' => 0, 'overdue' => 0];
        foreach ($programCards as $card) {
            foreach ($stats as $key => $value) {
                $stats[$key] += $card['stats'][$key] ?? 0;
            }
        }

        return ['stats' => $stats, 'completion_rate' => $this->completionRate($stats)];
    }

    private function departmentComparison($programs): array
    {
        $programIds = $programs->pluck('id');

        return Department::active()->get()->map(function (Department $dept) use ($programIds) {
            $counts = Document::where('department_id', $dept->id)
                ->whereIn('compliance_program_id', $programIds)
                ->selectRaw('status, count(*) as count')
                ->groupBy('status')
                ->pluck('count', 'status')
                ->toArray();

            $total = array_sum($counts);
            $approved = $counts['approved'] ?? 0;

            return [
                'id' => $dept->id,
                'name_ar' => $dept->name_ar,
                'name_en' => $dept->name_en,
                'total' => $total,
                'approved' => $approved,
                'completion_rate' => $total > 0 ? round(($approved / $total) * 100, 1) : 0,
            ];
        })->toArray();
    }

    private function upcomingDeadlines($programs): int
    {
        $cycleIds = AssessmentCycle::whereIn('compliance_program_id', $programs->pluck('id'))
            ->where('is_current', true)->pluck('id');

        return Standard::whereIn('cycle_id', $cycleIds)
            ->whereNotNull('due_date')
            ->whereBetween('due_date', [now()->toDateString(), now()->addDays(30)->toDateString()])
            ->count();
    }
}
