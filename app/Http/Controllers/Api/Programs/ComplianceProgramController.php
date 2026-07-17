<?php

namespace App\Http\Controllers\Api\Programs;

use App\Http\Controllers\Controller;
use App\Http\Resources\ComplianceProgramResource;
use App\Models\ComplianceProgram;
use App\Models\Document;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Program Selection endpoints. Only returns programs the current user is
 * authorized to see:
 *  - Super Admin: every active program (platform-wide access).
 *  - Executive Viewer: every active program (read-only, executive scope).
 *  - Everyone else: only programs with an active program_user_roles row.
 *
 * This mirrors the access rule enforced by EnsureProgramAccess for
 * individual program-scoped requests, so what a user sees on the Program
 * Selection page always matches what they can actually open.
 */
class ComplianceProgramController extends Controller
{
    /**
     * GET /api/v1/programs
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        $programs = ComplianceProgram::active()
            ->with('currentCycle')
            ->orderBy('sort_order')
            ->get()
            ->filter(fn (ComplianceProgram $program) => $user->hasProgramAccess($program))
            ->values()
            ->map(function (ComplianceProgram $program) {
                $program->summary = $this->summaryFor($program);

                return $program;
            });

        return response()->json([
            'success' => true,
            'data' => ComplianceProgramResource::collection($programs),
        ]);
    }

    /**
     * GET /api/v1/programs/{program}
     * The {program} parameter is already resolved + access-checked by the
     * EnsureProgramAccess middleware before this method runs.
     */
    public function show(Request $request): JsonResponse
    {
        $program = $request->attributes->get('compliance_program');
        $program->load('currentCycle');
        $program->summary = $this->summaryFor($program);

        return response()->json([
            'success' => true,
            'data' => new ComplianceProgramResource($program),
        ]);
    }

    /** Lightweight document-status summary for the program card / detail header. */
    private function summaryFor(ComplianceProgram $program): array
    {
        $counts = Document::where('compliance_program_id', $program->id)
            ->selectRaw('status, count(*) as count')
            ->groupBy('status')
            ->pluck('count', 'status');

        $total = (int) $counts->sum();
        $approved = (int) ($counts['approved'] ?? 0);

        return [
            'total_documents' => $total,
            'approved_documents' => $approved,
            'completion_rate' => $total > 0 ? round(($approved / $total) * 100, 1) : 0,
        ];
    }
}
