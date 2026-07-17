<?php

namespace App\Http\Controllers\Api\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\ChangePasswordRequest;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Services\AuthService;
use Database\Seeders\TestUsersSeeder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

/**
 * Handles all authentication endpoints: login, logout, refresh, and password change.
 */
class AuthController extends Controller
{
    public function __construct(private readonly AuthService $authService) {}

    /**
     * Authenticates a user and returns a JWT token.
     *
     * POST /api/v1/auth/login
     */
    public function login(LoginRequest $request): JsonResponse
    {
        $result = $this->authService->attempt(
            $request->username,
            $request->password
        );

        if (! $result) {
            return response()->json([
                'success' => false,
                'message' => __('auth.failed'),
            ], 401);
        }

        return response()->json([
            'success' => true,
            'data' => [
                'token' => $result['token'],
                'token_type' => 'bearer',
                'expires_in' => config('jwt.ttl') * 60,
                'user' => new UserResource($result['user']->load('department')),
            ],
        ]);
    }

    /**
     * Dev-only quick login: authenticates a fixed test account WITHOUT a
     * password. Available only when APP_ENV=local or APP_DEBUG=true.
     *
     * POST /api/v1/auth/quick-login
     */
    public function quickLogin(Request $request): JsonResponse
    {
        if (! $this->quickLoginEnabled()) {
            return response()->json(['success' => false, 'message' => 'Quick login is disabled.'], 403);
        }

        $request->validate(['username' => ['required', 'string']]);

        if (! in_array($request->username, TestUsersSeeder::usernames(), true)) {
            return response()->json(['success' => false, 'message' => 'Not a test account.'], 422);
        }

        $result = $this->authService->issueTokenFor($request->username);
        if (! $result) {
            return response()->json(['success' => false, 'message' => 'Test user not found. Run: php artisan system:demo-data'], 404);
        }

        return response()->json([
            'success' => true,
            'data' => [
                'token' => $result['token'],
                'token_type' => 'bearer',
                'expires_in' => config('jwt.ttl') * 60,
                'user' => new UserResource($result['user']->load('department')),
            ],
        ]);
    }

    /** Quick login is allowed only in local/debug environments. */
    public static function quickLoginEnabled(): bool
    {
        return app()->environment('local') || (bool) config('app.debug');
    }

    /**
     * Dev-only list of test accounts (name/role/department) for the quick-login
     * panel. Returns an empty list outside local/debug.
     *
     * GET /api/v1/auth/dev-users
     */
    public function devUsers(): JsonResponse
    {
        if (! self::quickLoginEnabled()) {
            return response()->json(['success' => true, 'data' => []]);
        }

        $users = User::whereIn('username', TestUsersSeeder::usernames())
            ->with(['department', 'programRoles.program'])->orderBy('id')->get()
            ->map(fn ($u) => [
                'username' => $u->username,
                'name' => $u->name,
                'role' => $u->getRoleNames()->first(),
                'department' => $u->department?->name_ar,
                'programs' => $u->programRoles->where('is_active', true)->map(fn ($pr) => [
                    'program' => $pr->program?->code,
                    'role_key' => $pr->role_key,
                ])->values(),
            ]);

        return response()->json(['success' => true, 'data' => $users]);
    }

    /**
     * Logs out the authenticated user and invalidates the JWT token.
     *
     * POST /api/v1/auth/logout
     */
    public function logout(): JsonResponse
    {
        $this->authService->logout();

        return response()->json(['success' => true, 'message' => 'Logged out successfully.']);
    }

    /**
     * Refreshes the JWT token.
     *
     * POST /api/v1/auth/refresh
     */
    public function refresh(): JsonResponse
    {
        $token = $this->authService->refreshToken();

        return response()->json([
            'success' => true,
            'data' => [
                'token' => $token,
                'token_type' => 'bearer',
                'expires_in' => config('jwt.ttl') * 60,
            ],
        ]);
    }

    /**
     * Returns the current authenticated user's profile.
     *
     * GET /api/v1/auth/me
     */
    public function me(Request $request): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => new UserResource($request->user()->load('department')),
        ]);
    }

    /**
     * Changes the password for the authenticated user.
     * Clears the must_change_password flag on success.
     *
     * POST /api/v1/auth/change-password
     */
    public function changePassword(ChangePasswordRequest $request): JsonResponse
    {
        $user = $request->user();

        if (! Hash::check($request->current_password, $user->password)) {
            return response()->json([
                'success' => false,
                'message' => __('auth.password_incorrect'),
            ], 422);
        }

        $user->update([
            'password' => $request->new_password,
            'must_change_password' => false,
        ]);

        return response()->json(['success' => true, 'message' => 'Password changed successfully.']);
    }
}
