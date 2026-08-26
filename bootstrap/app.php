<?php

use App\Exceptions\InvalidHierarchyException;
use App\Exceptions\InvalidProgramConfigurationException;
use App\Exceptions\WorkflowConflictException;
use App\Http\Middleware\EnsureProgramAccess;
use App\Http\Middleware\JwtMiddleware;
use App\Http\Middleware\SecurityHeaders;
use App\Http\Middleware\SetLocale;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\HandleCors;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Middleware\PermissionMiddleware;
use Spatie\Permission\Middleware\RoleMiddleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        /**
         * Liveness, registered with NO middleware group.
         *
         * Deliberately not `health: '/up'`: Laravel's built-in health route
         * renders an HTML page that pulls webfonts from fonts.bunny.net and
         * a stylesheet from cdn.jsdelivr.net — outbound requests this
         * platform must not make, being deployed on-premises with no
         * Internet dependency.
         *
         * And deliberately not in the `web` group: SESSION_DRIVER=database
         * means the session middleware opens a database connection, so with
         * the database down this route answered 500 and reported the process
         * as dead while it was serving fine. A liveness probe must be able
         * to say "the application is up, its database is not" — that is the
         * distinction an operator needs during an incident.
         *
         * Readiness (database, cache, queue, storage, scheduler heartbeat)
         * is the authenticated Super-Admin-only GET /api/v1/admin/health.
         */
        then: function () {
            Route::get('/up', fn () => response()->json(['status' => 'ok'], 200));
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            // NOT 'jwt.auth': tymon/jwt-auth's service provider registers
            // that alias itself and its registration wins, which silently
            // replaced this middleware with the package's bare Authenticate
            // and disabled every check below it (is_active, forced password
            // change, the TOKEN_EXPIRED code the SPA refreshes on). Aliased
            // distinctly so it cannot be shadowed again.
            'jwt.session' => JwtMiddleware::class,
            'set.locale' => SetLocale::class,
            'role' => RoleMiddleware::class,
            'permission' => PermissionMiddleware::class,
            'program.access' => EnsureProgramAccess::class,
        ]);

        $middleware->api(prepend: [
            HandleCors::class,
        ]);

        $middleware->append(SecurityHeaders::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*'),
        );
        $exceptions->render(function (ValidationException $e, Request $request) {
            if ($request->is('api/*')) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed.',
                    'errors' => $e->errors(),
                ], 422);
            }
        });
        $exceptions->render(function (AuthenticationException $e, Request $request) {
            if ($request->is('api/*')) {
                return response()->json(['success' => false, 'message' => 'Unauthenticated.'], 401);
            }
        });
        $exceptions->render(function (WorkflowConflictException $e, Request $request) {
            if ($request->is('api/*')) {
                return response()->json(['success' => false, 'message' => $e->getMessage()], 409);
            }
        });
        $exceptions->render(function (InvalidProgramConfigurationException $e, Request $request) {
            if ($request->is('api/*')) {
                return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
            }
        });
        $exceptions->render(function (InvalidHierarchyException $e, Request $request) {
            if ($request->is('api/*')) {
                return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
            }
        });
    })->create();
