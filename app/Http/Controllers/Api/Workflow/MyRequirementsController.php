<?php

namespace App\Http\Controllers\Api\Workflow;

use App\Http\Controllers\Controller;
use App\Models\ComplianceNode;
use App\Models\RequirementAssignment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * "My Requirements" (متطلباتي) — the unified Employee page. Strictly
 * scoped to requirements assigned to the employee's own department (or
 * specifically to the employee), across whatever programs the user is
 * authorized for — never hard-coded to Qiyas.
 */
class MyRequirementsController extends Controller
{
    /** GET /api/v1/programs/{program}/my-requirements */
    public function index(Request $request): JsonResponse
    {
        $program = $request->attributes->get('compliance_program');
        $user = $request->user();

        $departmentId = $user->employeeDepartmentId($program);
        if (! $departmentId && ! $user->isPlatformSuperAdmin()) {
            return response()->json(['success' => true, 'data' => [], 'meta' => ['total' => 0]]);
        }

        $query = RequirementAssignment::forProgram($program)
            ->active()
            ->with(['requirement', 'department', 'currentSubmission'])
            ->when(! $user->isPlatformSuperAdmin(), fn ($q) => $q->where('department_id', $departmentId))
            ->when($request->boolean('mine_only'), fn ($q) => $q->where('employee_id', $user->id));

        $query->when($request->cycle_id, fn ($q) => $q->where('program_cycle_id', $request->cycle_id))
            ->when($request->ancestor_id, fn ($q) => $q->whereIn(
                'compliance_node_id',
                ComplianceNode::subtreeIds((int) $request->ancestor_id),
            ))
            ->when($request->due_date, fn ($q) => $q->whereDate('effective_due_date', $request->due_date))
            ->when($request->boolean('overdue'), fn ($q) => $q->whereDate('effective_due_date', '<', now()->toDateString())
                ->whereDoesntHave('currentSubmission', fn ($s) => $s->where('status', 'approved')));

        $assignments = $query->latest('assigned_at')->paginate($request->get('per_page', 20));

        $filtered = $assignments->getCollection()->filter(function (RequirementAssignment $a) use ($request) {
            $status = $a->displayStatus();
            if ($request->status && $status !== $request->status) {
                return false;
            }
            if ($request->boolean('returned_for_revision') && $status !== 'returned_for_revision') {
                return false;
            }

            return true;
        })->values();

        return response()->json([
            'success' => true,
            'data' => $filtered->map(fn (RequirementAssignment $a) => [
                'id' => $a->id,
                // `path` replaces the old fixed perspective/axis pair: it is
                // the node's full ancestor chain at whatever depth this
                // program configured, so no consumer may assume a length.
                'requirement' => [
                    'id' => $a->requirement->id,
                    'code' => $a->requirement->code,
                    'name' => $a->requirement->name,
                    'level_key' => $a->requirement->hierarchyLevel?->key,
                    'level_name' => $a->requirement->hierarchyLevel?->name,
                    'path' => $a->requirement->breadcrumb(),
                ],
                'department' => $a->department->name,
                'status' => $a->displayStatus(),
                'effective_due_date' => $a->effective_due_date?->toDateString(),
                'is_overdue' => $a->effective_due_date && $a->effective_due_date->isPast() && $a->displayStatus() !== 'approved',
                'version_number' => $a->currentSubmission?->version_number,
            ]),
            'meta' => [
                'current_page' => $assignments->currentPage(),
                'last_page' => $assignments->lastPage(),
                'total' => $filtered->count(),
            ],
        ]);
    }
}
