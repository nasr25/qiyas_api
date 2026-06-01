<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Sets the application locale from the Accept-Language header or authenticated user preference.
 */
class SetLocale
{
    public function handle(Request $request, Closure $next): Response
    {
        $locale = 'ar'; // Default to Arabic for government systems

        // Check user preference from JWT
        if ($user = auth('api')->user()) {
            $locale = $user->locale ?? 'ar';
        }

        // Allow override via header
        if ($request->hasHeader('Accept-Language')) {
            $lang = substr($request->header('Accept-Language'), 0, 2);
            if (in_array($lang, ['ar', 'en'])) {
                $locale = $lang;
            }
        }

        app()->setLocale($locale);

        return $next($request);
    }
}
