<?php

namespace App\Http\Controllers\Api\Programs;

use App\Http\Controllers\Controller;
use App\Models\AssessmentCycle;
use App\Models\ComplianceProgram;
use App\Models\Standard;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Domains and Categories are NOT yet normalized tables in Phase 1 — Standard
 * still stores them as free-text `perspective`/`axis` columns (see
 * docs/multi-program-architecture.md, "Deferred technical debt": splitting
 * these into first-class Domain/Category entities is Phase 2 work, since it
 * touches the DGA standards import/export and every report query).
 *
 * These two read-only endpoints satisfy the generic route contract
 * (/programs/{program}/domains, /programs/{program}/categories) today by
 * computing a virtual list from the existing data, so the frontend and any
 * API consumer can already depend on the final shape.
 */
class ProgramTaxonomyController extends Controller
{
    /** GET /api/v1/programs/{program}/domains */
    public function domains(Request $request): JsonResponse
    {
        /** @var ComplianceProgram $program */
        $program = $request->attributes->get('compliance_program');
        $cycle = $this->currentCycle($program);

        $domains = Standard::where('compliance_program_id', $program->id)
            ->when($cycle, fn ($q) => $q->where('cycle_id', $cycle->id))
            ->whereNotNull('perspective')
            ->selectRaw('perspective, count(*) as requirements_count')
            ->groupBy('perspective')
            ->orderBy('perspective')
            ->get()
            ->map(fn ($row) => ['name' => $row->perspective, 'requirements_count' => $row->requirements_count]);

        return response()->json(['success' => true, 'data' => $domains]);
    }

    /** GET /api/v1/programs/{program}/categories */
    public function categories(Request $request): JsonResponse
    {
        /** @var ComplianceProgram $program */
        $program = $request->attributes->get('compliance_program');
        $cycle = $this->currentCycle($program);

        $categories = Standard::where('compliance_program_id', $program->id)
            ->when($cycle, fn ($q) => $q->where('cycle_id', $cycle->id))
            ->when($request->domain, fn ($q) => $q->where('perspective', $request->domain))
            ->whereNotNull('axis')
            ->selectRaw('axis, perspective, count(*) as requirements_count')
            ->groupBy('axis', 'perspective')
            ->orderBy('axis')
            ->get()
            ->map(fn ($row) => [
                'name' => $row->axis,
                'domain' => $row->perspective,
                'requirements_count' => $row->requirements_count,
            ]);

        return response()->json(['success' => true, 'data' => $categories]);
    }

    private function currentCycle(ComplianceProgram $program): ?AssessmentCycle
    {
        return AssessmentCycle::where('compliance_program_id', $program->id)->where('is_current', true)->first();
    }
}
