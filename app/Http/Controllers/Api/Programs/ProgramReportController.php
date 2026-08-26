<?php

namespace App\Http\Controllers\Api\Programs;

use App\Http\Controllers\Api\Reports\ReportController;
use App\Http\Controllers\Controller;
use App\Models\AssessmentCycle;
use App\Models\ComplianceProgram;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Thin program-scoped wrapper around the existing ReportController: defaults
 * cycle_id to the resolved program's current cycle (rather than requiring
 * the caller to know a cycle id), then delegates to the same aggregation
 * logic so report calculations are not duplicated. The underlying queries
 * are already cycle-scoped, and every cycle now belongs to exactly one
 * program, so this delegation is safe without further filtering.
 */
class ProgramReportController extends Controller
{
    public function __construct(private readonly ReportController $reports) {}

    public function byDepartment(Request $request): JsonResponse
    {
        if ($blocked = $this->rejectCrossProgramCycle($request)) {
            return $blocked;
        }

        return $this->reports->byDepartment($this->withDefaultCycle($request));
    }

    public function byStandard(Request $request): JsonResponse
    {
        if ($blocked = $this->rejectCrossProgramCycle($request)) {
            return $blocked;
        }

        return $this->reports->byStandard($this->withDefaultCycle($request));
    }

    public function byStatus(Request $request): JsonResponse
    {
        if ($blocked = $this->rejectCrossProgramCycle($request)) {
            return $blocked;
        }

        return $this->reports->byStatus($this->withDefaultCycle($request));
    }

    public function cycleSummary(Request $request): JsonResponse
    {
        if ($blocked = $this->rejectCrossProgramCycle($request)) {
            return $blocked;
        }

        return $this->reports->cycleSummary($this->withDefaultCycle($request));
    }

    /**
     * If the caller explicitly passed a cycle_id, it must belong to the
     * resolved program — otherwise a user authorized for one program could
     * read another program's report data by guessing a cycle id (IDOR).
     */
    private function rejectCrossProgramCycle(Request $request): ?JsonResponse
    {
        if (! $request->cycle_id) {
            return null;
        }

        /** @var ComplianceProgram $program */
        $program = $request->attributes->get('compliance_program');
        $belongs = AssessmentCycle::where('id', $request->cycle_id)
            ->where('compliance_program_id', $program->id)
            ->exists();

        return $belongs ? null : response()->json(['success' => false, 'message' => 'Cycle not found.'], 404);
    }

    private function withDefaultCycle(Request $request): Request
    {
        if (! $request->cycle_id) {
            /** @var ComplianceProgram $program */
            $program = $request->attributes->get('compliance_program');
            $cycle = AssessmentCycle::where('compliance_program_id', $program->id)
                ->where('is_current', true)->first();
            if ($cycle) {
                $request->merge(['cycle_id' => $cycle->id]);
            }
        }

        return $request;
    }
}
