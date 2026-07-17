<?php

namespace App\Http\Controllers\Api\Workflow;

use App\Http\Controllers\Controller;
use App\Models\ComplianceProgram;
use App\Models\ExtensionRequest;
use App\Models\RequirementAssignment;
use App\Models\SlaInstance;
use App\Models\WorkflowEvent;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Core Phase 2 report set. Every query is scoped to the resolved program
 * and, for non-privileged roles, further scoped by department — see
 * docs/qiyas-role-permissions.md.
 */
class WorkflowReportController extends Controller
{
    /** GET /api/v1/programs/{program}/reports/overdue-requirements */
    public function overdueRequirements(Request $request): JsonResponse
    {
        $program = $this->program($request);
        $departmentId = $this->scopedDepartment($request, $program);

        $assignments = RequirementAssignment::forProgram($program)->active()
            ->whereDate('effective_due_date', '<', now()->toDateString())
            ->when($departmentId, fn ($q) => $q->where('department_id', $departmentId))
            ->when($request->department_id && $request->user()->isPlatformSuperAdmin(), fn ($q) => $q->where('department_id', $request->department_id))
            ->with('requirement', 'department', 'employee', 'currentSubmission')
            ->get();

        return response()->json(['success' => true, 'data' => $assignments->map(fn ($a) => [
            'requirement' => ['code' => $a->requirement->standard_number, 'name' => $a->requirement->name],
            'department' => $a->department->name,
            'employee' => $a->employee?->name,
            'status' => $a->displayStatus(),
            'effective_due_date' => $a->effective_due_date?->toDateString(),
            'days_overdue' => $a->effective_due_date ? now()->diffInDays($a->effective_due_date) : null,
        ])]);
    }

    /** GET /api/v1/programs/{program}/reports/sla-breaches */
    public function slaBreaches(Request $request): JsonResponse
    {
        $program = $this->program($request);
        $this->requireProgramLevel($request, $program);

        $breaches = SlaInstance::where('compliance_program_id', $program->id)->where('status', 'breached')
            ->with('assignment.requirement', 'assignment.department')
            ->when($request->stage, fn ($q) => $q->where('stage', $request->stage))
            ->latest('breached_at')
            ->get();

        return response()->json(['success' => true, 'data' => $breaches->map(fn ($s) => [
            'requirement' => $s->assignment ? $s->assignment->requirement->standard_number : null,
            'department' => $s->assignment?->department?->name,
            'stage' => $s->stage,
            'started_at' => $s->started_at->toIso8601String(),
            'due_at' => $s->due_at?->toIso8601String(),
            'breached_at' => $s->breached_at?->toIso8601String(),
        ])]);
    }

    /** GET /api/v1/programs/{program}/reports/extension-requests */
    public function extensionRequests(Request $request): JsonResponse
    {
        $program = $this->program($request);
        $this->requireProgramLevel($request, $program);

        $extensions = ExtensionRequest::where('compliance_program_id', $program->id)
            ->whereNotNull('requirement_assignment_id')
            ->with('assignment.requirement', 'assignment.department', 'requester', 'reviewer')
            ->when($request->status, fn ($q) => $q->where('status', $request->status))
            ->latest('requested_at')->get();

        return response()->json(['success' => true, 'data' => $extensions->map(fn ($e) => [
            'requirement' => $e->assignment?->requirement?->standard_number,
            'department' => $e->assignment?->department?->name,
            'requested_by' => $e->requester?->name,
            'current_due_date' => $e->current_due_date?->toDateString(),
            'requested_due_date' => $e->requested_due_date?->toDateString(),
            'status' => $e->status,
            'reviewed_by' => $e->reviewer?->name,
        ])]);
    }

    /** GET /api/v1/programs/{program}/reports/rejection-frequency */
    public function rejectionFrequency(Request $request): JsonResponse
    {
        $program = $this->program($request);
        $this->requireProgramLevel($request, $program);

        $counts = WorkflowEvent::where('compliance_program_id', $program->id)
            ->whereIn('event_type', ['department_manager_rejected', 'auditor_rejected', 'program_manager_rejected'])
            ->selectRaw('event_type, count(*) as count')
            ->groupBy('event_type')
            ->pluck('count', 'event_type');

        return response()->json(['success' => true, 'data' => $counts]);
    }

    /** GET /api/v1/programs/{program}/reports/employee-performance */
    public function employeePerformance(Request $request): JsonResponse
    {
        $program = $this->program($request);
        $user = $request->user();
        $departmentId = $user->isPlatformSuperAdmin() ? $request->integer('department_id') : $user->managedDepartmentId($program);

        if (! $departmentId) {
            return response()->json(['success' => false, 'message' => 'Forbidden.'], 403);
        }

        $assignments = RequirementAssignment::forProgram($program)->where('department_id', $departmentId)
            ->with('employee', 'currentSubmission')->get()
            ->groupBy(fn ($a) => $a->employee_id ?? 0);

        $data = $assignments->map(function ($group) {
            $employee = $group->first()->employee;
            $submissions = $group->pluck('currentSubmission')->filter();

            return [
                'employee' => $employee?->name ?? '—',
                'assigned' => $group->count(),
                'approved' => $submissions->where('status', 'approved')->count(),
                'returned' => $submissions->where('status', 'returned_for_revision')->count(),
                'overdue' => $group->filter(fn ($a) => $a->effective_due_date?->isPast() && $a->displayStatus() !== 'approved')->count(),
            ];
        })->values();

        return response()->json(['success' => true, 'data' => $data]);
    }

    private function program(Request $request): ComplianceProgram
    {
        return $request->attributes->get('compliance_program');
    }

    private function scopedDepartment(Request $request, ComplianceProgram $program): ?int
    {
        $user = $request->user();
        if ($user->isPlatformSuperAdmin() || $user->hasProgramRole($program, 'program-manager') || $user->hasProgramRole($program, 'auditor')) {
            return null;
        }

        return $user->managedDepartmentId($program) ?? $user->employeeDepartmentId($program);
    }

    private function requireProgramLevel(Request $request, ComplianceProgram $program): void
    {
        $user = $request->user();
        abort_unless(
            $user->isPlatformSuperAdmin() || $user->isPlatformExecutiveViewer()
                || $user->hasProgramRole($program, 'program-manager') || $user->hasProgramRole($program, 'auditor'),
            403,
            'Forbidden.',
        );
    }
}
