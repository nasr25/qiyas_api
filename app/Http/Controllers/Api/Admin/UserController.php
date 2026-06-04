<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Services\AuditService;
use App\Services\AuthService;
use App\Services\LdapService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Admin user management: create local users, add AD users, manage roles.
 */
class UserController extends Controller
{
    public function __construct(
        private readonly AuthService $authService,
        private readonly LdapService $ldapService
    ) {}

    /**
     * Lists all users with optional filters.
     * GET /api/v1/admin/users
     */
    public function index(Request $request): JsonResponse
    {
        $users = User::with('department')
            ->withCount(['documents'])
            ->when($request->search, fn($q) => $q->where('name', 'like', "%{$request->search}%")
                ->orWhere('username', 'like', "%{$request->search}%"))
            ->when($request->department_id, fn($q) => $q->where('department_id', $request->department_id))
            ->when($request->role, fn($q) => $q->role($request->role))
            ->when(isset($request->is_active), fn($q) => $q->where('is_active', $request->boolean('is_active')))
            ->latest()
            ->paginate($request->get('per_page', 15));

        return response()->json([
            'success' => true,
            'data'    => UserResource::collection($users),
            'meta'    => [
                'current_page' => $users->currentPage(),
                'last_page'    => $users->lastPage(),
                'total'        => $users->total(),
            ],
        ]);
    }

    /**
     * Creates a new local user account.
     * POST /api/v1/admin/users
     */
    public function store(Request $request): JsonResponse
    {
        // Empty department select ("None") arrives as "" — treat as null so a
        // department-less user (e.g. an auditor) passes the nullable rule.
        $request->merge(['department_id' => $request->input('department_id') ?: null]);

        $data = $request->validate([
            'name'          => ['required', 'string', 'max:255'],
            'username'      => ['required', 'string', 'max:100', 'unique:users'],
            'email'         => ['nullable', 'email', 'unique:users'],
            'password'      => ['required', 'string', 'min:8'],
            'department_id' => ['nullable', 'exists:departments,id'],
            'roles'         => ['required', 'array'],
            'roles.*'       => ['exists:roles,name'],
            'locale'        => ['nullable', 'in:ar,en'],
        ]);

        $user = $this->authService->createLocalUser($data);
        $user->syncRoles($data['roles']);

        AuditService::log('user.created', "Local user '{$user->username}' created", $user);

        return response()->json([
            'success' => true,
            'data'    => new UserResource($user->load('department')),
            'message' => 'User created successfully.',
        ], 201);
    }

    /**
     * Searches Active Directory for users.
     * GET /api/v1/admin/users/ldap-search
     */
    public function ldapSearch(Request $request): JsonResponse
    {
        $request->validate(['query' => ['required', 'string', 'min:2']]);

        $users = $this->ldapService->searchUsers($request->query('query'));

        return response()->json(['success' => true, 'data' => $users]);
    }

    /**
     * Imports an AD user into the platform.
     * POST /api/v1/admin/users/import-ldap
     */
    public function importLdap(Request $request): JsonResponse
    {
        $request->merge(['department_id' => $request->input('department_id') ?: null]);

        $data = $request->validate([
            'username'      => ['required', 'string', 'unique:users,username'],
            'display_name'  => ['required', 'string'],
            'email'         => ['nullable', 'email'],
            'department_id' => ['nullable', 'exists:departments,id'],
            'roles'         => ['required', 'array'],
            'roles.*'       => ['exists:roles,name'],
        ]);

        $user = $this->authService->upsertLdapUser([
            'username'     => $data['username'],
            'display_name' => $data['display_name'],
            'email'        => $data['email'] ?? null,
        ], [
            'department_id' => $data['department_id'] ?? null,
        ]);

        $user->syncRoles($data['roles']);

        AuditService::log('user.imported', "AD user '{$user->username}' imported", $user);

        return response()->json([
            'success' => true,
            'data'    => new UserResource($user->load('department')),
            'message' => 'AD user imported successfully.',
        ], 201);
    }

    /**
     * Shows a specific user.
     * GET /api/v1/admin/users/{user}
     */
    public function show(User $user): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data'    => new UserResource($user->load('department')),
        ]);
    }

    /**
     * Updates a user.
     * PUT /api/v1/admin/users/{user}
     */
    public function update(Request $request, User $user): JsonResponse
    {
        $request->merge(['department_id' => $request->input('department_id') ?: null]);

        $data = $request->validate([
            'name'          => ['sometimes', 'string', 'max:255'],
            'email'         => ['nullable', 'email', Rule::unique('users')->ignore($user->id)],
            'department_id' => ['nullable', 'exists:departments,id'],
            'is_active'     => ['sometimes', 'boolean'],
            'roles'         => ['sometimes', 'array'],
            'roles.*'       => ['exists:roles,name'],
            'locale'        => ['nullable', 'in:ar,en'],
        ]);

        $oldValues = $user->only(['name', 'email', 'department_id', 'is_active']);
        $user->update($data);

        if (isset($data['roles'])) {
            $user->syncRoles($data['roles']);
        }

        AuditService::log('user.updated', "User '{$user->username}' updated", $user, $oldValues, $user->fresh()->toArray());

        return response()->json([
            'success' => true,
            'data'    => new UserResource($user->fresh()->load('department')),
        ]);
    }

    /**
     * Resets a local user's password.
     * POST /api/v1/admin/users/{user}/reset-password
     */
    public function resetPassword(Request $request, User $user): JsonResponse
    {
        if ($user->auth_type !== 'local') {
            return response()->json(['success' => false, 'message' => 'Cannot reset password for AD users.'], 422);
        }

        $data = $request->validate(['password' => ['required', 'string', 'min:8']]);

        $user->update([
            'password'             => $data['password'],
            'must_change_password' => true,
        ]);

        AuditService::log('user.password_reset', "Password reset for user '{$user->username}'", $user);

        return response()->json(['success' => true, 'message' => 'Password reset. User must change on next login.']);
    }

    /**
     * Toggles the user's active status.
     * POST /api/v1/admin/users/{user}/toggle-active
     */
    public function toggleActive(User $user): JsonResponse
    {
        $user->update(['is_active' => !$user->is_active]);

        $action = $user->is_active ? 'activated' : 'deactivated';
        AuditService::log("user.{$action}", "User '{$user->username}' {$action}", $user);

        return response()->json([
            'success' => true,
            'data'    => ['is_active' => $user->is_active],
        ]);
    }
}
