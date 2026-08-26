<?php

namespace App\Http\Controllers\Api\Programs;

use App\Http\Controllers\Controller;
use App\Models\AssessmentCycle;
use App\Models\ComplianceNode;
use App\Models\ComplianceProgram;
use App\Services\HierarchyDefinitionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * "Requirement" is the generic name for the assessable item a program
 * assigns and collects evidence against — a Qiyas Criterion, an ECC
 * Control, an NDMO Requirement.
 *
 * Repointed from `standards` to `compliance_nodes` during mirror removal.
 * The old implementation listed Standards and exposed a fixed
 * `domain`/`category` pair taken from the free-text perspective/axis
 * columns, which meant (a) an assignment UI built on this list handed the
 * assignment endpoint a standard id that is no longer assignable, and
 * (b) anything deeper than two levels was invisible. Each row now carries
 * its full `path` at whatever depth the program configured.
 *
 * Only nodes on assessable levels are listed: a grouping node is not a
 * requirement and must never appear in an assignment picker.
 */
class ProgramRequirementController extends Controller
{
    public function __construct(private readonly HierarchyDefinitionService $structures) {}

    public function index(Request $request): JsonResponse
    {
        /** @var ComplianceProgram $program */
        $program = $request->attributes->get('compliance_program');
        $cycle = $this->resolveCycle($request, $program);

        $assessableLevelIds = collect($this->structures->levels($program))
            ->where('is_assessable', true)->pluck('id');

        $requirements = ComplianceNode::where('compliance_program_id', $program->id)
            ->whereIn('hierarchy_level_id', $assessableLevelIds ?: [0])
            ->when($cycle, fn ($q) => $q->where('program_cycle_id', $cycle->id))
            // Depth-agnostic replacement for the old domain/category filter:
            // narrow to any ancestor's subtree, at any level.
            ->when($request->ancestor_id, fn ($q) => $q->whereIn(
                'id', ComplianceNode::subtreeIds((int) $request->ancestor_id),
            ))
            ->with('hierarchyLevel')
            ->orderBy('level')->orderBy('code')
            ->paginate($request->get('per_page', 20));

        return response()->json([
            'success' => true,
            // NEVER assign a paginator's ->through()/map() result directly to
            // a nested JSON key — Laravel serializes the paginator itself
            // (current_page/data/last_page/...) as the value, not a flat
            // array, breaking any frontend consumer expecting a plain list.
            'data' => $requirements->getCollection()->map(fn (ComplianceNode $node) => [
                'id' => $node->id,
                'number' => $node->code,
                'code' => $node->code,
                'name' => $node->name,
                'name_ar' => $node->name_ar,
                'name_en' => $node->name_en,
                'level_key' => $node->hierarchyLevel?->key,
                'level_name' => $node->hierarchyLevel?->name,
                'path' => $node->breadcrumb(),
                'status' => $node->status,
                'weight' => $node->weight,
                'due_date' => $node->default_due_date?->toDateString(),
                'is_assignable' => $node->isAssignable(),
                'accepts_evidence' => $node->acceptsEvidence(),
                'is_active' => $node->status === 'active',
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

        $node = ComplianceNode::where('id', $requirement)
            ->where('compliance_program_id', $resolvedProgram->id)
            ->with('hierarchyLevel')
            ->first();

        if (! $node) {
            return response()->json(['success' => false, 'message' => 'Requirement not found.'], 404);
        }

        return response()->json([
            'success' => true,
            'data' => [
                'id' => $node->id,
                'code' => $node->code,
                'name' => $node->name,
                'name_ar' => $node->name_ar,
                'name_en' => $node->name_en,
                'description_ar' => $node->description_ar,
                'objective_ar' => $node->objective_ar,
                'guidance_ar' => $node->guidance_ar,
                'weight' => $node->weight,
                'due_date' => $node->default_due_date?->toDateString(),
                'level_key' => $node->hierarchyLevel?->key,
                'level_name' => $node->hierarchyLevel?->name,
                'path' => $node->breadcrumb(),
                'is_assignable' => $node->isAssignable(),
                'is_assessable' => $node->isAssessable(),
                'accepts_evidence' => $node->acceptsEvidence(),
                'status' => $node->status,
            ],
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
