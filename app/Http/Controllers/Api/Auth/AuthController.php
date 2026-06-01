<?php

namespace App\Http\Controllers\Api\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Requests\Auth\ChangePasswordRequest;
use App\Http\Resources\UserResource;
use App\Services\AuthService;
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
     *
     * @param LoginRequest $request
     * @return JsonResponse
     */
    public function login(LoginRequest $request): JsonResponse
    {
        $result = $this->authService->attempt(
            $request->username,
            $request->password
        );

        if (!$result) {
            return response()->json([
                'success' => false,
                'message' => __('auth.failed'),
            ], 401);
        }

        return response()->json([
            'success' => true,
            'data'    => [
                'token'      => $result['token'],
                'token_type' => 'bearer',
                'expires_in' => config('jwt.ttl') * 60,
                'user'       => new UserResource($result['user']),
            ],
        ]);
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
            'data'    => [
                'token'      => $token,
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
            'data'    => new UserResource($request->user()),
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

        if (!Hash::check($request->current_password, $user->password)) {
            return response()->json([
                'success' => false,
                'message' => __('auth.password_incorrect'),
            ], 422);
        }

        $user->update([
            'password'             => $request->new_password,
            'must_change_password' => false,
        ]);

        return response()->json(['success' => true, 'message' => 'Password changed successfully.']);
    }
}
