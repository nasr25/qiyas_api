<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Applies baseline security headers to every response. This is a JSON API
 * consumed by a separate Vue SPA (not server-rendered HTML), so the policy
 * here is deliberately tight — there is no inline script/style to allow for
 * and no reason to permit framing or third-party origins.
 */
class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        /** @var Response $response */
        $response = $next($request);

        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $response->headers->set('X-Frame-Options', 'DENY');
        $response->headers->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=(), payment=()');
        // API responses are JSON, never HTML — default-src 'none' plus
        // frame-ancestors 'none' covers clickjacking without needing any
        // script/style/font allowances a real page would require.
        $response->headers->set('Content-Security-Policy', "default-src 'none'; frame-ancestors 'none'");

        if ($request->secure()) {
            $response->headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        }

        return $response;
    }
}
