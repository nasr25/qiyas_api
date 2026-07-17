<?php

namespace App\Http\Controllers\Api\Programs;

use App\Http\Controllers\Controller;
use App\Http\Resources\AssessmentCycleResource;
use App\Models\AssessmentCycle;
use App\Models\ComplianceProgram;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Program-scoped Program Cycles (AssessmentCycle rows) listing. A cycle ID
 * that belongs to a different program returns 404, not the record —
 * defends against cross-program IDOR by URL-guessing a cycle id.
 */
class ProgramCycleController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        /** @var ComplianceProgram $program */
        $program = $request->attributes->get('compliance_program');

        $cycles = AssessmentCycle::forProgram($program)
            ->withCount('standards')
            ->with('creator')
            ->when($request->status, fn ($q) => $q->where('status', $request->status))
            ->latest()
            ->paginate($request->get('per_page', 10));

        return response()->json([
            'success' => true,
            'data' => AssessmentCycleResource::collection($cycles),
            'meta' => [
                'current_page' => $cycles->currentPage(),
                'last_page' => $cycles->lastPage(),
                'total' => $cycles->total(),
            ],
        ]);
    }

    public function show(Request $request, string $program, int|string $cycle): JsonResponse
    {
        /** @var ComplianceProgram $resolvedProgram */
        $resolvedProgram = $request->attributes->get('compliance_program');

        $cycleModel = AssessmentCycle::where('id', $cycle)
            ->where('compliance_program_id', $resolvedProgram->id)
            ->first();

        if (! $cycleModel) {
            return response()->json(['success' => false, 'message' => 'Cycle not found.'], 404);
        }

        return response()->json([
            'success' => true,
            'data' => new AssessmentCycleResource($cycleModel->loadCount('standards')->load('creator')),
        ]);
    }
}
