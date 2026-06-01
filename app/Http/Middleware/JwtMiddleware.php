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
 * Validates JWT tokens on protected API routes.
 */
class JwtMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        try {
            $user = JWTAuth::parseToken()->authenticate();

            if (!$user) {
                return response()->json(['success' => false, 'message' => 'User not found.'], 401);
            }

            if (!$user->is_active) {
                return response()->json(['success' => false, 'message' => 'Account is deactivated.'], 401);
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
}
