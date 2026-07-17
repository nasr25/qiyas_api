<?php

namespace App\Http\Controllers\Api\Departments;

use App\Http\Controllers\Controller;
use App\Http\Resources\DepartmentResource;
use App\Models\Department;
use App\Services\AuditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Department CRUD operations.
 */
class DepartmentController extends Controller
{
    /**
     * Lists all departments.
     * GET /api/v1/departments
     */
    public function index(Request $request): JsonResponse
    {
        $departments = Department::withCount('users')
            ->when($request->search, fn ($q) => $q
                ->where('name_ar', 'like', "%{$request->search}%")
                ->orWhere('name_en', 'like', "%{$request->search}%"))
            ->when(isset($request->is_active), fn ($q) => $q->where('is_active', $request->boolean('is_active')))
            ->latest()
            ->paginate($request->get('per_page', 15));

        return response()->json([
            'success' => true,
            'data' => DepartmentResource::collection($departments),
            'meta' => [
                'current_page' => $departments->currentPage(),
                'last_page' => $departments->lastPage(),
                'total' => $departments->total(),
            ],
        ]);
    }

    /**
     * Creates a department.
     * POST /api/v1/departments
     */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name_ar' => ['required', 'string', 'max:255', 'unique:departments'],
            'name_en' => ['required', 'string', 'max:255', 'unique:departments'],
            'description' => ['nullable', 'string'],
            'is_active' => ['boolean'],
        ]);

        $department = Department::create($data);
        AuditService::log('department.created', "Department '{$department->name_en}' created", $department);

        return response()->json([
            'success' => true,
            'data' => new DepartmentResource($department),
        ], 201);
    }

    /**
     * Shows a department.
     * GET /api/v1/departments/{department}
     */
    public function show(Department $department): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => new DepartmentResource($department->loadCount('users')),
        ]);
    }

    /**
     * Updates a department.
     * PUT /api/v1/departments/{department}
     */
    public function update(Request $request, Department $department): JsonResponse
    {
        $data = $request->validate([
            'name_ar' => ['sometimes', 'string', 'max:255', Rule::unique('departments')->ignore($department->id)],
            'name_en' => ['sometimes', 'string', 'max:255', Rule::unique('departments')->ignore($department->id)],
            'description' => ['nullable', 'string'],
            'is_active' => ['boolean'],
        ]);

        $old = $department->only(['name_ar', 'name_en', 'is_active']);
        $department->update($data);

        AuditService::log('department.updated', "Department '{$department->name_en}' updated", $department, $old);

        return response()->json([
            'success' => true,
            'data' => new DepartmentResource($department->fresh()),
        ]);
    }

    /**
     * Deletes a department (only if no users or documents assigned).
     * DELETE /api/v1/departments/{department}
     */
    public function destroy(Department $department): JsonResponse
    {
        if ($department->users()->exists()) {
            return response()->json(['success' => false, 'message' => 'Cannot delete: department has users.'], 422);
        }
        if ($department->documents()->exists()) {
            return response()->json(['success' => false, 'message' => 'Cannot delete: department has documents.'], 422);
        }

        AuditService::log('department.deleted', "Department '{$department->name_en}' deleted", $department);
        $department->delete();

        return response()->json(['success' => true, 'message' => 'Department deleted.']);
    }
}
