<?php

namespace App\Http\Middleware;

use Closure;
use Exception;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Tymon\JWTAuth\Exceptions\TokenExpiredException;
use Tymon\JWTAuth\Exceptions\TokenInvalidException;
use Tymon\JWTAuth\Facades\JWTAuth;

/**
 * Validates JWT tokens on protected API routes, and enforces the two
 * account states that must hold for every authenticated request:
 * the account is still active, and any pending forced password change has
 * been completed.
 *
 * The forced-change check lives here rather than in the SPA because it was
 * previously advisory only — the flag was returned to the client and the
 * Vue router acted on it, so a caller talking to the API directly kept full
 * access indefinitely. That matters for any account provisioned with a
 * known initial password.
 */
class JwtMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        try {
            $user = JWTAuth::parseToken()->authenticate();

            if (! $user) {
                return response()->json(['success' => false, 'message' => 'User not found.'], 401);
            }

            if (! $user->is_active) {
                return response()->json(['success' => false, 'message' => 'Account is deactivated.'], 401);
            }

            if ($user->must_change_password && ! $this->isPasswordChangeFlow($request)) {
                return response()->json([
                    'success' => false,
                    'message' => 'A password change is required before continuing.',
                    'code' => 'PASSWORD_CHANGE_REQUIRED',
                ], 403);
            }
        } catch (TokenExpiredException) {
            return response()->json(['success' => false, 'message' => 'Token expired.', 'code' => 'TOKEN_EXPIRED'], 401);
        } catch (TokenInvalidException) {
            return response()->json(['success' => false, 'message' => 'Token invalid.'], 401);
        } catch (Exception) {
            return response()->json(['success' => false, 'message' => 'Authentication required.'], 401);
        }

        return $next($request);
    }

    /**
     * The only routes reachable while a password change is outstanding:
     * read your own identity, change the password, or sign out. Anything
     * else is refused until the flag clears.
     */
    private function isPasswordChangeFlow(Request $request): bool
    {
        return $request->is(
            'api/*/auth/change-password',
            'api/*/auth/me',
            'api/*/auth/logout',
            'api/*/auth/refresh',
        );
    }
}
