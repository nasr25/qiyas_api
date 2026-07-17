<?php

namespace App\Http\Middleware;

use App\Models\ComplianceProgram;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Resolves the {program} route parameter (matched by code, e.g. "QIYAS") and
 * enforces program-level access before the request reaches the controller:
 *
 *   1. The program must exist.
 *   2. The program must be active, UNLESS the user is a platform Super Admin
 *      (who may manage inactive/draft programs).
 *   3. The user must have access: implicit for Super Admin / Executive
 *      Viewer, otherwise an active program_user_roles row is required.
 *
 * A nonexistent program and an unauthorized/inactive program both return 404
 * (never 403) — this deliberately avoids leaking which program codes exist
 * or their status to a user who has no business knowing, closing a
 * cross-program enumeration / IDOR vector.
 *
 * On success, the resolved ComplianceProgram is bound into the request as
 * `compliance_program` for controllers to read via
 * $request->attributes->get('compliance_program').
 */
class EnsureProgramAccess
{
    public function handle(Request $request, Closure $next): Response
    {
        $code = $request->route('program');
        $user = $request->user();

        $program = ComplianceProgram::where('code', strtoupper((string) $code))->first();

        if (! $program) {
            return $this->notFound();
        }

        $isSuperAdmin = $user?->isPlatformSuperAdmin() ?? false;

        if (! $program->is_active && ! $isSuperAdmin) {
            return $this->notFound();
        }

        if (! $user || ! $user->hasProgramAccess($program)) {
            return $this->notFound();
        }

        $request->attributes->set('compliance_program', $program);

        return $next($request);
    }

    private function notFound(): Response
    {
        return response()->json([
            'success' => false,
            'message' => 'Program not found.',
        ], 404);
    }
}
