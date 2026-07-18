<?php

namespace App\Http\Controllers\Api\Workflow;

use App\Exceptions\WorkflowConflictException;
use App\Http\Controllers\Controller;
use App\Models\ComplianceProgram;
use App\Models\ComplianceResponsibility;
use App\Models\Department;
use App\Models\RequirementAssignment;
use App\Models\User;
use App\Services\ResponsibilityService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Generic responsibility-label management (Data Owner, Data Steward, ...)
 * — available to any program that enables specific types through its
 * `responsibilities` configuration; not NDMO-specific in code. See
 * ComplianceResponsibility's class doc for why this never grants workflow
 * authority.
 */
class ResponsibilityController extends Controller
{
    public function __construct(private readonly ResponsibilityService $responsibilities) {}

    /** GET /api/v1/programs/{program}/responsibility-types */
    public function types(Request $request): JsonResponse
    {
        $program = $this->program($request);

        return response()->json(['success' => true, 'data' => $this->responsibilities->enabledTypes($program)]);
    }

    /**
     * GET /api/v1/programs/{program}/departments/{department}/users
     * Minimal, low-sensitivity lookup (active users' id/name only) so the
     * assignment form can offer a Data Owner/Data Steward picker scoped to
     * the assignment's own department — not a general user directory.
     */
    public function departmentUsers(Request $request, string $program, int|string $department): JsonResponse
    {
        $this->program($request);
        $departmentModel = Department::findOrFail($department);

        return response()->json([
            'success' => true,
            'data' => $departmentModel->users()->where('is_active', true)->orderBy('name')
                ->get(['id', 'name'])->values(),
        ]);
    }

    /** GET /api/v1/programs/{program}/assignments/{assignment}/responsibilities */
    public function index(Request $request, string $program, int|string $assignment): JsonResponse
    {
        $resolvedProgram = $this->program($request);
        $assignmentModel = $this->findScoped($resolvedProgram, $assignment);

        return response()->json([
            'success' => true,
            'data' => $assignmentModel->responsibilities()->active()->with(['user', 'department'])->get()
                ->map(fn (ComplianceResponsibility $r) => $this->summarize($r))->values(),
        ]);
    }

    /** POST /api/v1/programs/{program}/assignments/{assignment}/responsibilities */
    public function store(Request $request, string $program, int|string $assignment): JsonResponse
    {
        $resolvedProgram = $this->program($request);
        $this->authorizeManage($request->user(), $resolvedProgram);
        $assignmentModel = $this->findScoped($resolvedProgram, $assignment);

        $data = $request->validate([
            'responsibility_type' => ['required', 'string', 'max:50'],
            'user_id' => ['nullable', 'integer', 'exists:users,id'],
            'department_id' => ['nullable', 'integer', 'exists:departments,id'],
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        $user = ! empty($data['user_id']) ? User::find($data['user_id']) : null;
        $department = ! empty($data['department_id']) ? Department::find($data['department_id']) : null;

        try {
            $responsibility = $this->responsibilities->assign(
                $assignmentModel, $data['responsibility_type'], $request->user(), $user, $department, $data['reason'] ?? null,
            );
        } catch (WorkflowConflictException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }

        return response()->json(['success' => true, 'data' => $this->summarize($responsibility)], 201);
    }

    /** DELETE /api/v1/programs/{program}/responsibilities/{responsibility} */
    public function destroy(Request $request, string $program, int|string $responsibility): JsonResponse
    {
        $resolvedProgram = $this->program($request);
        $this->authorizeManage($request->user(), $resolvedProgram);

        $model = ComplianceResponsibility::where('id', $responsibility)
            ->where('compliance_program_id', $resolvedProgram->id)->first();
        abort_unless($model, 404, 'Responsibility not found.');

        $data = $request->validate(['reason' => ['nullable', 'string', 'max:500']]);
        $this->responsibilities->revoke($model, $request->user(), $data['reason'] ?? null);

        return response()->json(['success' => true, 'message' => 'Responsibility revoked.']);
    }

    private function summarize(ComplianceResponsibility $r): array
    {
        return [
            'id' => $r->id,
            'responsibility_type' => $r->responsibility_type,
            'user' => $r->user ? ['id' => $r->user->id, 'name' => $r->user->name] : null,
            'department' => $r->department ? ['id' => $r->department->id, 'name' => $r->department->name_ar] : null,
            'is_active' => $r->is_active,
            'start_date' => $r->start_date?->toDateString(),
            'end_date' => $r->end_date?->toDateString(),
        ];
    }

    private function program(Request $request): ComplianceProgram
    {
        return $request->attributes->get('compliance_program');
    }

    private function authorizeManage(User $user, ComplianceProgram $program): void
    {
        abort_unless($user->isPlatformSuperAdmin() || $user->hasProgramRole($program, 'program-manager'), 403, 'Only the Program Manager can manage responsibilities.');
    }

    private function findScoped(ComplianceProgram $program, int|string $id): RequirementAssignment
    {
        $assignment = RequirementAssignment::where('id', $id)->where('compliance_program_id', $program->id)->first();
        abort_unless($assignment, 404, 'Assignment not found.');

        return $assignment;
    }
}
