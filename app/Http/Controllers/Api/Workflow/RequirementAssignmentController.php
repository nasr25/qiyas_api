<?php

namespace App\Http\Controllers\Api\Workflow;

use App\Exceptions\WorkflowConflictException;
use App\Http\Controllers\Controller;
use App\Models\ComplianceProgram;
use App\Models\Department;
use App\Models\RequirementAssignment;
use App\Models\Standard;
use App\Models\User;
use App\Services\WorkflowService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Program Manager: assign a requirement to a department, reassign,
 * update due date/employee/instructions, view assignment history.
 */
class RequirementAssignmentController extends Controller
{
    public function __construct(private readonly WorkflowService $workflow) {}

    /** GET /api/v1/programs/{program}/assignments */
    public function index(Request $request): JsonResponse
    {
        $program = $this->program($request);
        $this->authorizeManage($request->user(), $program);

        $assignments = RequirementAssignment::forProgram($program)
            ->with(['requirement', 'department', 'employee', 'currentSubmission'])
            ->when($request->cycle_id, fn ($q) => $q->where('program_cycle_id', $request->cycle_id))
            ->when($request->department_id, fn ($q) => $q->where('department_id', $request->department_id))
            ->when($request->status, fn ($q) => $q->where('status', $request->status))
            ->latest('assigned_at')
            ->paginate($request->get('per_page', 20));

        return response()->json([
            'success' => true,
            // getCollection()->map() (not paginator ->through()) so `data` is
            // always a flat JSON array — a paginator object assigned directly
            // to a nested response key serializes with its own pagination
            // envelope, breaking any frontend expecting a plain list.
            'data' => $assignments->getCollection()->map(fn (RequirementAssignment $a) => $this->summarize($a))->values(),
            'meta' => ['current_page' => $assignments->currentPage(), 'last_page' => $assignments->lastPage(), 'total' => $assignments->total()],
        ]);
    }

    /** POST /api/v1/programs/{program}/assignments */
    public function store(Request $request): JsonResponse
    {
        $program = $this->program($request);
        $this->authorizeManage($request->user(), $program);

        $data = $request->validate([
            'requirement_id' => ['required', 'integer'],
            'department_id' => ['required', 'integer', 'exists:departments,id'],
            'employee_id' => ['nullable', 'integer', 'exists:users,id'],
            'due_date' => ['nullable', 'date'],
            'priority' => ['nullable', 'string', 'max:20'],
            'instructions_ar' => ['nullable', 'string', 'max:5000'],
            'instructions_en' => ['nullable', 'string', 'max:5000'],
        ]);

        $requirement = Standard::where('id', $data['requirement_id'])
            ->where('compliance_program_id', $program->id)
            ->first();
        if (! $requirement) {
            return response()->json(['success' => false, 'message' => 'Requirement not found.'], 404);
        }

        $department = Department::findOrFail($data['department_id']);
        $employee = ! empty($data['employee_id']) ? User::find($data['employee_id']) : null;

        try {
            $assignment = $this->workflow->assign(
                $requirement, $program, $request->user(), $department, $employee,
                $data['due_date'] ?? null, $data['priority'] ?? null,
                $data['instructions_ar'] ?? null, $data['instructions_en'] ?? null,
            );
        } catch (WorkflowConflictException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 409);
        }

        return response()->json(['success' => true, 'data' => $this->summarize($assignment)], 201);
    }

    /** GET /api/v1/programs/{program}/assignments/{assignment} */
    public function show(Request $request, string $program, int|string $assignment): JsonResponse
    {
        $resolvedProgram = $this->program($request);
        $model = $this->findScoped($resolvedProgram, $assignment);
        if (! $model) {
            return response()->json(['success' => false, 'message' => 'Assignment not found.'], 404);
        }
        if ($request->user()->cannot('view', $model)) {
            return response()->json(['success' => false, 'message' => 'Forbidden.'], 403);
        }

        return response()->json(['success' => true, 'data' => $this->detail($model)]);
    }

    /** PUT /api/v1/programs/{program}/assignments/{assignment} */
    public function update(Request $request, string $program, int|string $assignment): JsonResponse
    {
        $resolvedProgram = $this->program($request);
        $model = $this->findScoped($resolvedProgram, $assignment);
        if (! $model) {
            return response()->json(['success' => false, 'message' => 'Assignment not found.'], 404);
        }
        $this->authorizeManage($request->user(), $resolvedProgram);

        $data = $request->validate([
            'effective_due_date' => ['sometimes', 'nullable', 'date'],
            'employee_id' => ['sometimes', 'nullable', 'integer', 'exists:users,id'],
            'priority' => ['sometimes', 'nullable', 'string', 'max:20'],
            'instructions_ar' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'instructions_en' => ['sometimes', 'nullable', 'string', 'max:5000'],
        ]);

        try {
            $updated = $this->workflow->updateAssignmentDetails($model, $data, $request->user());
        } catch (WorkflowConflictException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 409);
        }

        return response()->json(['success' => true, 'data' => $this->summarize($updated)]);
    }

    /** POST /api/v1/programs/{program}/assignments/{assignment}/reassign */
    public function reassign(Request $request, string $program, int|string $assignment): JsonResponse
    {
        $resolvedProgram = $this->program($request);
        $model = $this->findScoped($resolvedProgram, $assignment);
        if (! $model) {
            return response()->json(['success' => false, 'message' => 'Assignment not found.'], 404);
        }
        $this->authorizeManage($request->user(), $resolvedProgram);

        $data = $request->validate([
            'department_id' => ['required', 'integer', 'exists:departments,id'],
            'reason' => ['required', 'string', 'max:1000'],
        ]);

        $department = Department::findOrFail($data['department_id']);

        try {
            $new = $this->workflow->reassignDepartment($model, $department, $data['reason'], $request->user());
        } catch (WorkflowConflictException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 409);
        }

        return response()->json(['success' => true, 'data' => $this->summarize($new)]);
    }

    /** GET /api/v1/programs/{program}/assignments/{assignment}/history */
    public function history(Request $request, string $program, int|string $assignment): JsonResponse
    {
        $resolvedProgram = $this->program($request);
        $model = $this->findScoped($resolvedProgram, $assignment);
        if (! $model) {
            return response()->json(['success' => false, 'message' => 'Assignment not found.'], 404);
        }
        if ($request->user()->cannot('view', $model)) {
            return response()->json(['success' => false, 'message' => 'Forbidden.'], 403);
        }

        // Walk the reassignment chain (both directions) to build full lineage.
        $chain = collect([$model]);
        $cursor = $model;
        while ($cursor->previous_assignment_id) {
            $cursor = RequirementAssignment::with('department', 'assignedBy')->find($cursor->previous_assignment_id);
            if (! $cursor) {
                break;
            }
            $chain->push($cursor);
        }
        $next = RequirementAssignment::where('previous_assignment_id', $model->id)->first();
        while ($next) {
            $chain->prepend($next);
            $next = RequirementAssignment::where('previous_assignment_id', $next->id)->first();
        }

        $events = $model->events()->with('user')->get();

        return response()->json([
            'success' => true,
            'data' => [
                'assignments' => $chain->sortBy('assigned_at')->values()->map(fn ($a) => $this->summarize($a)),
                'events' => $events->map(fn ($e) => [
                    'event_type' => $e->event_type, 'user' => $e->user?->name, 'role' => $e->role,
                    'notes' => $e->notes, 'old_status' => $e->old_status, 'new_status' => $e->new_status,
                    'evidence_version' => $e->evidence_version, 'created_at' => $e->created_at->toIso8601String(),
                ]),
            ],
        ]);
    }

    // ─── Helpers ─────────────────────────────────────────────────────────────

    private function program(Request $request): ComplianceProgram
    {
        return $request->attributes->get('compliance_program');
    }

    private function authorizeManage(User $user, ComplianceProgram $program): void
    {
        abort_unless($user->isPlatformSuperAdmin() || $user->hasProgramRole($program, 'program-manager'), 403, 'Only the Program Manager can manage assignments.');
    }

    private function findScoped(ComplianceProgram $program, int|string $id): ?RequirementAssignment
    {
        return RequirementAssignment::where('id', $id)->where('compliance_program_id', $program->id)->first();
    }

    private function summarize(RequirementAssignment $a): array
    {
        return [
            'id' => $a->id,
            'requirement' => ['id' => $a->requirement->id, 'code' => $a->requirement->standard_number, 'name' => $a->requirement->name],
            'department' => ['id' => $a->department->id, 'name' => $a->department->name],
            'employee' => $a->employee ? ['id' => $a->employee->id, 'name' => $a->employee->name] : null,
            'status' => $a->status,
            'display_status' => $a->displayStatus(),
            'original_due_date' => $a->original_due_date?->toDateString(),
            'effective_due_date' => $a->effective_due_date?->toDateString(),
            'priority' => $a->priority,
            'assigned_at' => $a->assigned_at?->toIso8601String(),
        ];
    }

    private function detail(RequirementAssignment $a): array
    {
        $a->load(['requirement', 'department', 'employee', 'assignedBy', 'currentSubmission.files', 'currentSubmission.decisions.reviewer']);

        return array_merge($this->summarize($a), [
            'instructions_ar' => $a->instructions_ar,
            'instructions_en' => $a->instructions_en,
            'assigned_by' => $a->assignedBy?->name,
            'current_submission' => $a->currentSubmission ? [
                'id' => $a->currentSubmission->id,
                'version_number' => $a->currentSubmission->version_number,
                'status' => $a->currentSubmission->status,
                'current_stage' => $a->currentSubmission->current_stage,
                'submitted_at' => $a->currentSubmission->submitted_at?->toIso8601String(),
            ] : null,
        ]);
    }
}
