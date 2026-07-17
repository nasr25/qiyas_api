<?php

namespace App\Http\Controllers\Api\Workflow;

use App\Http\Controllers\Controller;
use App\Models\ComplianceProgram;
use App\Models\Department;
use App\Models\EvidenceSubmission;
use App\Models\ExtensionRequest;
use App\Models\RequirementAssignment;
use App\Models\SlaInstance;
use App\Models\Standard;
use App\Models\WorkflowEvent;
use App\Services\DashboardMetricsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * One dashboard endpoint per role — see docs/qiyas-workflow.md. Each method
 * enforces its own scoping (department for Department Manager/Employee,
 * program-wide for Program Manager/Auditor) independently of the generic
 * program-access check already applied by EnsureProgramAccess. The shared
 * count-builders a future program's dashboard would also need live in
 * DashboardMetricsService — see docs/dashboard-reporting-engine.md.
 */
class WorkflowDashboardController extends Controller
{
    public function __construct(private readonly DashboardMetricsService $metrics) {}

    /** GET /api/v1/programs/{program}/dashboards/program-manager */
    public function programManager(Request $request): JsonResponse
    {
        $program = $this->program($request);
        $this->authorize($request, fn ($u) => $u->isPlatformSuperAdmin() || $u->hasProgramRole($program, 'program-manager'));

        $totalRequirements = Standard::where('compliance_program_id', $program->id)->count();
        $assignedCount = $this->metrics->assignedRequirementIds($program)->count();

        return response()->json(['success' => true, 'data' => [
            'total_requirements' => $totalRequirements,
            'assigned_requirements' => $assignedCount,
            'unassigned_requirements' => max(0, $totalRequirements - $assignedCount),
            'status_counts' => $this->metrics->submissionStatusCounts($program),
            'overdue' => $this->metrics->overdueAssignmentCount($program),
            'extension_requests' => [
                'active' => ExtensionRequest::where('compliance_program_id', $program->id)->pending()->count(),
                'approved' => ExtensionRequest::where('compliance_program_id', $program->id)->where('status', 'approved')->count(),
            ],
            'sla' => [
                'warnings' => 0, // SLA warnings are events, not persisted state — surfaced via workflow_events for now.
                'breaches' => SlaInstance::where('compliance_program_id', $program->id)->where('status', 'breached')->count(),
            ],
            'department_comparison' => $this->departmentComparison($program),
            'upcoming_deadlines' => $this->metrics->upcomingDeadlineCount($program),
        ]]);
    }

    /** GET /api/v1/programs/{program}/dashboards/department-manager */
    public function departmentManager(Request $request): JsonResponse
    {
        $program = $this->program($request);
        $user = $request->user();
        $departmentId = $user->isPlatformSuperAdmin() ? $request->integer('department_id') : $user->managedDepartmentId($program);

        $this->authorize($request, fn ($u) => $u->isPlatformSuperAdmin() || $u->managedDepartmentId($program) !== null);
        if (! $departmentId) {
            return response()->json(['success' => false, 'message' => 'No managed department found.'], 422);
        }

        $assignments = RequirementAssignment::forProgram($program)->active()->where('department_id', $departmentId)
            ->with('currentSubmission', 'employee')->get();

        $employees = $assignments->groupBy(fn ($a) => $a->employee_id ?? 0)->map(function ($group, $employeeId) {
            $employee = $group->first()->employee;
            $submissions = $group->pluck('currentSubmission')->filter();

            return [
                'employee' => $employee ? ['id' => $employee->id, 'name' => $employee->name] : ['id' => null, 'name' => 'Unassigned'],
                'assigned' => $group->count(),
                'completed' => $submissions->where('status', 'approved')->count(),
                'draft' => $submissions->where('status', 'draft')->count(),
                'returned' => $submissions->where('status', 'returned_for_revision')->count(),
                'overdue' => $group->filter(fn ($a) => $a->effective_due_date && $a->effective_due_date->isPast() && $a->displayStatus() !== 'approved')->count(),
            ];
        })->values();

        return response()->json(['success' => true, 'data' => [
            'department_id' => $departmentId,
            'total_requirements' => $assignments->count(),
            'employee_workload' => $employees,
            'pending_manager_review' => EvidenceSubmission::where('compliance_program_id', $program->id)
                ->where('department_id', $departmentId)->where('status', 'pending_department_manager')->count(),
            'returned_submissions' => EvidenceSubmission::where('compliance_program_id', $program->id)
                ->where('department_id', $departmentId)->where('status', 'returned_for_revision')->count(),
            'upcoming_deadlines' => $assignments->filter(fn ($a) => $a->effective_due_date
                && $a->effective_due_date->between(now(), now()->addDays(14)))->count(),
        ]]);
    }

    /** GET /api/v1/programs/{program}/dashboards/auditor */
    public function auditor(Request $request): JsonResponse
    {
        $program = $this->program($request);
        $this->authorize($request, fn ($u) => $u->isPlatformSuperAdmin() || $u->hasProgramRole($program, 'auditor'));

        $pending = EvidenceSubmission::where('compliance_program_id', $program->id)->where('status', 'pending_auditor')->get();
        $slaBreachedIds = SlaInstance::where('compliance_program_id', $program->id)->where('stage', 'auditor')->where('status', 'breached')->pluck('evidence_submission_id');

        return response()->json(['success' => true, 'data' => [
            'pending_reviews' => $pending->count(),
            'within_sla' => $pending->whereNotIn('id', $slaBreachedIds)->count(),
            'outside_sla' => $pending->whereIn('id', $slaBreachedIds)->count(),
            'pending_extensions' => ExtensionRequest::where('compliance_program_id', $program->id)->pending()->count(),
            'returned_by_auditor' => WorkflowEvent::where('compliance_program_id', $program->id)->where('event_type', 'auditor_rejected')->count(),
            'departments_with_rejections' => $this->departmentsWithFrequentRejections($program),
            'upcoming_deadlines' => RequirementAssignment::forProgram($program)->active()
                ->whereBetween('effective_due_date', [now()->toDateString(), now()->addDays(14)->toDateString()])->count(),
        ]]);
    }

    /** GET /api/v1/programs/{program}/dashboards/employee */
    public function employee(Request $request): JsonResponse
    {
        $program = $this->program($request);
        $user = $request->user();
        $departmentId = $user->employeeDepartmentId($program);

        $assignments = RequirementAssignment::forProgram($program)->active()
            ->where('department_id', $departmentId)
            ->with('currentSubmission')->get();

        $submissions = $assignments->pluck('currentSubmission')->filter();

        return response()->json(['success' => true, 'data' => [
            'assigned' => $assignments->count(),
            'drafts' => $submissions->where('status', 'draft')->count(),
            'returned' => $submissions->where('status', 'returned_for_revision')->count(),
            'approved' => $submissions->where('status', 'approved')->count(),
            'overdue' => $assignments->filter(fn ($a) => $a->effective_due_date && $a->effective_due_date->isPast() && $a->displayStatus() !== 'approved')->count(),
            'upcoming_deadlines' => $assignments->filter(fn ($a) => $a->effective_due_date && $a->effective_due_date->between(now(), now()->addDays(14)))->count(),
            'extension_requests' => ExtensionRequest::whereIn('requirement_assignment_id', $assignments->pluck('id'))->where('requested_by', $user->id)->count(),
        ]]);
    }

    private function departmentComparison(ComplianceProgram $program): array
    {
        return Department::active()->get()->map(function (Department $dept) use ($program) {
            $total = RequirementAssignment::forProgram($program)->active()->where('department_id', $dept->id)->count();
            $approved = EvidenceSubmission::where('compliance_program_id', $program->id)->where('department_id', $dept->id)->where('status', 'approved')->count();

            return [
                'id' => $dept->id, 'name' => $dept->name, 'total' => $total, 'approved' => $approved,
                'completion_rate' => $total > 0 ? round(($approved / $total) * 100, 1) : 0,
            ];
        })->all();
    }

    private function departmentsWithFrequentRejections(ComplianceProgram $program): array
    {
        return WorkflowEvent::where('compliance_program_id', $program->id)
            ->whereIn('event_type', ['department_manager_rejected', 'auditor_rejected', 'program_manager_rejected'])
            ->join('requirement_assignments', 'requirement_assignments.id', '=', 'workflow_events.requirement_assignment_id')
            ->join('departments', 'departments.id', '=', 'requirement_assignments.department_id')
            ->select('departments.id', 'departments.name_ar', 'departments.name_en', DB::raw('count(*) as rejection_count'))
            ->groupBy('departments.id', 'departments.name_ar', 'departments.name_en')
            ->orderByDesc('rejection_count')
            ->limit(5)
            ->get()
            ->map(fn ($row) => ['id' => $row->id, 'name' => app()->getLocale() === 'ar' ? $row->name_ar : $row->name_en, 'rejection_count' => $row->rejection_count])
            ->all();
    }

    private function program(Request $request): ComplianceProgram
    {
        return $request->attributes->get('compliance_program');
    }

    private function authorize(Request $request, \Closure $check): void
    {
        abort_unless($check($request->user()), 403, 'Forbidden.');
    }
}
