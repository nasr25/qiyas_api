<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AssessmentCycle;
use App\Models\Department;
use App\Models\Document;
use App\Models\EvidenceRequirement;
use App\Models\ExtensionRequest;
use App\Models\Standard;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Dashboard data aggregation for all user roles.
 */
class DashboardController extends Controller
{
    /**
     * Returns dashboard metrics for the authenticated user based on their role.
     * GET /api/v1/dashboard
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        if ($user->hasRole('super-admin') || $user->hasRole('executive')) {
            return $this->executiveDashboard($request);
        }

        if ($user->hasRole('auditor')) {
            return $this->auditorDashboard($request);
        }

        if ($user->hasRole('coordinator')) {
            return $this->coordinatorDashboard($request, $user);
        }

        return $this->employeeDashboard($request, $user);
    }

    /**
     * Executive/Admin dashboard: overall progress across all departments.
     */
    private function executiveDashboard(Request $request): JsonResponse
    {
        $cycle = $this->getActiveCycle($request);

        $stats = $this->getDocumentStats($cycle?->id);
        $departments = $this->getDepartmentProgress($cycle?->id);

        return response()->json([
            'success' => true,
            'data'    => [
                'cycle'              => $cycle ? [
                    'id'         => $cycle->id,
                    'name'       => $cycle->name,
                    'year'       => $cycle->year,
                    'status'     => $cycle->status,
                    'end_date'   => $cycle->end_date?->toDateString(),
                    'final_score' => $cycle->final_score,
                ] : null,
                'stats'             => $stats,
                'departments'       => $departments,
                'completion_rate'   => $this->calcCompletionRate($stats),
                'recent_activity'   => $this->getRecentActivity(10),
            ],
        ]);
    }

    /**
     * Auditor dashboard: review queue and overdue items.
     */
    private function auditorDashboard(Request $request): JsonResponse
    {
        $cycle = $this->getActiveCycle($request);

        return response()->json([
            'success' => true,
            'data'    => [
                'cycle'                  => $cycle ? ['id' => $cycle->id, 'name' => $cycle->name] : null,
                'pending_reviews'        => Document::where('status', 'under_review')
                    ->when($cycle, fn($q) => $q->where('cycle_id', $cycle->id))
                    ->count(),
                'rejected_count'         => Document::where('status', 'rejected')
                    ->when($cycle, fn($q) => $q->where('cycle_id', $cycle->id))
                    ->where('reviewed_by', auth()->id())
                    ->count(),
                'extension_requests'     => ExtensionRequest::where('status', 'pending')->count(),
                'overdue_count'          => Document::where('status', 'overdue')
                    ->when($cycle, fn($q) => $q->where('cycle_id', $cycle->id))
                    ->count(),
                'approved_today'         => Document::where('status', 'approved')
                    ->where('reviewed_by', auth()->id())
                    ->whereDate('reviewed_at', today())
                    ->count(),
            ],
        ]);
    }

    /**
     * Coordinator dashboard: department-level progress.
     */
    private function coordinatorDashboard(Request $request, $user): JsonResponse
    {
        $cycle = $this->getActiveCycle($request);
        $stats = $this->getDocumentStats($cycle?->id, $user->department_id);
        $catalog = $this->getCatalogCounts($cycle?->id, $user->department_id);

        return response()->json([
            'success' => true,
            'data'    => [
                'cycle'           => $cycle ? ['id' => $cycle->id, 'name' => $cycle->name] : null,
                'stats'           => $stats,
                'completion_rate' => $this->calcCompletionRate($stats),
                'standards_count'    => $catalog['standards'],
                'requirements_count' => $catalog['requirements'],
                'recent_documents' => Document::with(['requirement', 'submitter'])
                    ->where('department_id', $user->department_id)
                    ->when($cycle, fn($q) => $q->where('cycle_id', $cycle->id))
                    ->latest()->take(5)->get()->map(fn($d) => [
                        'id'     => $d->id,
                        'title'  => $d->title,
                        'status' => $d->status,
                    ]),
            ],
        ]);
    }

    /**
     * Employee dashboard: personal submission progress.
     */
    private function employeeDashboard(Request $request, $user): JsonResponse
    {
        $cycle = $this->getActiveCycle($request);
        $stats = $this->getDocumentStats($cycle?->id, $user->department_id);
        $catalog = $this->getCatalogCounts($cycle?->id, $user->department_id);

        return response()->json([
            'success' => true,
            'data'    => [
                'cycle'           => $cycle ? ['id' => $cycle->id, 'name' => $cycle->name] : null,
                'stats'           => $stats,
                'completion_rate' => $this->calcCompletionRate($stats),
                'standards_count'    => $catalog['standards'],
                'requirements_count' => $catalog['requirements'],
                'my_documents'    => Document::where('department_id', $user->department_id)
                    ->when($cycle, fn($q) => $q->where('cycle_id', $cycle->id))
                    ->where('submitted_by', $user->id)
                    ->count(),
            ],
        ]);
    }

    /**
     * Counts standards assigned to a department in a cycle, and the evidence
     * requirements under those standards. Used for the at-a-glance "assigned"
     * cards on the employee/coordinator dashboards.
     */
    private function getCatalogCounts(?int $cycleId, ?int $departmentId): array
    {
        $standardIds = Standard::query()
            ->when($cycleId, fn ($q) => $q->where('cycle_id', $cycleId))
            ->when($departmentId, fn ($q) => $q->whereHas(
                'departments',
                fn ($d) => $d->where('departments.id', $departmentId)
            ))
            ->pluck('id');

        return [
            'standards'    => $standardIds->count(),
            'requirements' => EvidenceRequirement::whereIn('standard_id', $standardIds)->count(),
        ];
    }

    /** Gets document status counts, optionally scoped to a cycle and department. */
    private function getDocumentStats(?int $cycleId, ?int $departmentId = null): array
    {
        $query = Document::query()
            ->when($cycleId, fn($q) => $q->where('cycle_id', $cycleId))
            ->when($departmentId, fn($q) => $q->where('department_id', $departmentId));

        $counts = $query->selectRaw('status, count(*) as count')
            ->groupBy('status')
            ->pluck('count', 'status')
            ->toArray();

        return [
            'total'        => array_sum($counts),
            'draft'        => $counts['draft'] ?? 0,
            'under_review' => $counts['under_review'] ?? 0,
            'approved'     => $counts['approved'] ?? 0,
            'rejected'     => $counts['rejected'] ?? 0,
            'overdue'      => $counts['overdue'] ?? 0,
        ];
    }

    /** Gets per-department progress for executive view. */
    private function getDepartmentProgress(?int $cycleId): array
    {
        $departments = Department::active()->get();

        return $departments->map(function ($dept) use ($cycleId) {
            $stats = $this->getDocumentStats($cycleId, $dept->id);
            return [
                'id'              => $dept->id,
                'name_ar'         => $dept->name_ar,
                'name_en'         => $dept->name_en,
                'stats'           => $stats,
                'completion_rate' => $this->calcCompletionRate($stats),
            ];
        })->toArray();
    }

    /** Calculates the completion rate (approved / total). */
    private function calcCompletionRate(array $stats): float
    {
        if ($stats['total'] === 0) return 0;
        return round(($stats['approved'] / $stats['total']) * 100, 1);
    }

    /** Gets the active or most recent cycle. */
    private function getActiveCycle(Request $request): ?AssessmentCycle
    {
        if ($request->cycle_id) {
            return AssessmentCycle::find($request->cycle_id);
        }
        return AssessmentCycle::active()->first()
            ?? AssessmentCycle::latest()->first();
    }

    /** Returns recently updated documents for activity feed. */
    private function getRecentActivity(int $limit): array
    {
        return Document::with(['department', 'submitter'])
            ->latest('updated_at')
            ->take($limit)
            ->get()
            ->map(fn($d) => [
                'id'            => $d->id,
                'title'         => $d->title,
                'status'        => $d->status,
                'department'    => $d->department?->name_ar,
                'updated_at'    => $d->updated_at->diffForHumans(),
            ])
            ->toArray();
    }
}
