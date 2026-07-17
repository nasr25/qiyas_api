<?php

namespace App\Http\Controllers\Api\Programs;

use App\Http\Controllers\Controller;
use App\Models\AssessmentCycle;
use App\Models\ComplianceProgram;
use App\Models\Standard;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Requirement is the generic name for what Qiyas calls a "Standard" (see
 * program terminology settings). Read-only in Phase 1 — full CRUD for
 * requirements continues to live at the legacy nested route
 * /api/v1/cycles/{cycle}/standards, which is unaffected by this addition.
 * Defaults to the program's current cycle when ?cycle_id is not given.
 */
class ProgramRequirementController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        /** @var ComplianceProgram $program */
        $program = $request->attributes->get('compliance_program');
        $cycle = $this->resolveCycle($request, $program);

        $requirements = Standard::where('compliance_program_id', $program->id)
            ->when($cycle, fn ($q) => $q->where('cycle_id', $cycle->id))
            ->when($request->domain, fn ($q) => $q->where('perspective', $request->domain))
            ->when($request->category, fn ($q) => $q->where('axis', $request->category))
            ->orderBy('standard_number')
            ->paginate($request->get('per_page', 20));

        return response()->json([
            'success' => true,
            // NEVER assign a paginator's ->through()/map() result directly to
            // a nested JSON key — Laravel serializes the paginator itself
            // (current_page/data/last_page/...) as the value, not a flat
            // array, breaking any frontend consumer expecting a plain list.
            // See docs/qiyas-workflow.md §6 for the same defect class fixed
            // elsewhere in Phase 2/3; this controller was missed then.
            'data' => $requirements->getCollection()->map(fn (Standard $s) => [
                'id' => $s->id,
                'number' => $s->standard_number,
                'name' => $s->name,
                'name_ar' => $s->name_ar,
                'name_en' => $s->name_en,
                'domain' => $s->perspective,
                'category' => $s->axis,
                'status' => $s->status,
                'weight' => $s->weight,
                'due_date' => $s->due_date?->toDateString(),
                'is_active' => $s->is_active,
            ])->values(),
            'meta' => [
                'current_page' => $requirements->currentPage(),
                'last_page' => $requirements->lastPage(),
                'total' => $requirements->total(),
            ],
        ]);
    }

    public function show(Request $request, string $program, int|string $requirement): JsonResponse
    {
        /** @var ComplianceProgram $resolvedProgram */
        $resolvedProgram = $request->attributes->get('compliance_program');

        $standard = Standard::where('id', $requirement)
            ->where('compliance_program_id', $resolvedProgram->id)
            ->first();

        if (! $standard) {
            return response()->json(['success' => false, 'message' => 'Requirement not found.'], 404);
        }

        return response()->json([
            'success' => true,
            'data' => $standard->load('evidenceRequirements', 'departments'),
        ]);
    }

    private function resolveCycle(Request $request, ComplianceProgram $program): ?AssessmentCycle
    {
        if ($request->cycle_id) {
            return AssessmentCycle::where('compliance_program_id', $program->id)->find($request->cycle_id);
        }

        return AssessmentCycle::where('compliance_program_id', $program->id)->where('is_current', true)->first();
    }
}
