<?php

use Illuminate\Support\Facades\Route;

/**
 * The web client is a separately built Vue SPA (see frontend/); this API
 * host does not serve application pages. Replaces the Laravel default
 * scaffold page (which embedded a large third-party CSS framework blob)
 * with a minimal, safe root response — no application internals, stack
 * traces, or dependency details exposed. See docs/operations/health-checks.md
 * for the actual liveness/readiness endpoints.
 */
Route::get('/', fn () => response()->json(['service' => 'api'], 200));
