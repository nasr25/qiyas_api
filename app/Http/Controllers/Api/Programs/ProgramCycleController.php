<?php

namespace App\Http\Controllers\Api\Programs;

use App\Http\Controllers\Controller;
use App\Http\Resources\AssessmentCycleResource;
use App\Models\AssessmentCycle;
use App\Models\ComplianceProgram;
use App\Models\User;
use App\Services\CycleService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Program-scoped Program Cycles (AssessmentCycle rows). A cycle ID that
 * belongs to a different program returns 404, not the record — defends
 * against cross-program IDOR by URL-guessing a cycle id.
 *
 * The write actions here (store/update/activate/close/archive) exist
 * because the pre-Phase-5 engine only exposed cycle lifecycle writes
 * through the legacy, non-program-scoped `/cycles` routes, whose service
 * call defaulted to QIYAS when no program was supplied
 * (CycleService::create()'s `$program ??= ...QIYAS...` fallback). That
 * fallback is harmless for the legacy Qiyas-only routes it still serves,
 * but it meant no other program could ever create a cycle through the
 * API — a genuine blocking engine gap, fixed here by reusing the exact
 * same CycleService with an explicitly resolved $program, never the
 * fallback. See docs/cross-program-isolation.md.
 */
class ProgramCycleController extends Controller
{
    public function __construct(private readonly CycleService $cycleService) {}

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

    /** POST /api/v1/programs/{program}/cycles */
    public function store(Request $request): JsonResponse
    {
        $program = $this->program($request);
        $this->authorizeManage($request->user(), $program);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'name_ar' => ['nullable', 'string', 'max:255'],
            'name_en' => ['nullable', 'string', 'max:255'],
            'year' => ['required', 'integer', 'min:2020', 'max:2100'],
            'start_date' => ['required', 'date'],
            'end_date' => ['required', 'date', 'after:start_date'],
            'copy_from_cycle' => ['nullable', 'integer', 'exists:assessment_cycles,id'],
        ]);

        $cycle = $this->cycleService->create($data, $request->user(), $program);

        if (! empty($data['copy_from_cycle'])) {
            $source = $this->findScoped($program, $data['copy_from_cycle']);
            $count = $this->cycleService->copyHierarchy($source, $cycle);

            return response()->json([
                'success' => true,
                'data' => new AssessmentCycleResource($cycle),
                'message' => "Cycle created with {$count} hierarchy node(s) copied.",
            ], 201);
        }

        return response()->json([
            'success' => true,
            'data' => new AssessmentCycleResource($cycle),
            'message' => 'Cycle created successfully.',
        ], 201);
    }

    /** PUT /api/v1/programs/{program}/cycles/{cycle} */
    public function update(Request $request, string $program, int|string $cycle): JsonResponse
    {
        $resolvedProgram = $this->program($request);
        $this->authorizeManage($request->user(), $resolvedProgram);
        $cycleModel = $this->findScoped($resolvedProgram, $cycle);

        if ($cycleModel->status !== 'draft') {
            return response()->json(['success' => false, 'message' => 'Only draft cycles can be edited.'], 422);
        }

        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'name_ar' => ['sometimes', 'nullable', 'string', 'max:255'],
            'name_en' => ['sometimes', 'nullable', 'string', 'max:255'],
            'year' => ['sometimes', 'integer', 'min:2020', 'max:2100'],
            'start_date' => ['sometimes', 'date'],
            'end_date' => ['sometimes', 'date', 'after:start_date'],
        ]);

        $cycleModel->update($data);

        return response()->json(['success' => true, 'data' => new AssessmentCycleResource($cycleModel->fresh())]);
    }

    /** POST /api/v1/programs/{program}/cycles/{cycle}/activate */
    public function activate(Request $request, string $program, int|string $cycle): JsonResponse
    {
        $resolvedProgram = $this->program($request);
        $this->authorizeManage($request->user(), $resolvedProgram);
        $cycleModel = $this->findScoped($resolvedProgram, $cycle);

        try {
            $cycleModel = $this->cycleService->activate($cycleModel);
        } catch (\RuntimeException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }

        return response()->json(['success' => true, 'data' => new AssessmentCycleResource($cycleModel), 'message' => 'Cycle activated successfully.']);
    }

    /** POST /api/v1/programs/{program}/cycles/{cycle}/close */
    public function close(Request $request, string $program, int|string $cycle): JsonResponse
    {
        $resolvedProgram = $this->program($request);
        $this->authorizeManage($request->user(), $resolvedProgram);
        $cycleModel = $this->findScoped($resolvedProgram, $cycle);

        $data = $request->validate([
            'final_score' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'closing_notes' => ['nullable', 'string', 'max:2000'],
        ]);

        try {
            $cycleModel = $this->cycleService->close($cycleModel, $data['final_score'] ?? null, $data['closing_notes'] ?? null, $request->user());
        } catch (\RuntimeException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }

        return response()->json(['success' => true, 'data' => new AssessmentCycleResource($cycleModel), 'message' => 'Cycle closed successfully.']);
    }

    /** POST /api/v1/programs/{program}/cycles/{cycle}/archive */
    public function archive(Request $request, string $program, int|string $cycle): JsonResponse
    {
        $resolvedProgram = $this->program($request);
        $this->authorizeManage($request->user(), $resolvedProgram);
        $cycleModel = $this->findScoped($resolvedProgram, $cycle);

        try {
            $cycleModel = $this->cycleService->archive($cycleModel);
        } catch (\RuntimeException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }

        return response()->json(['success' => true, 'data' => new AssessmentCycleResource($cycleModel), 'message' => 'Cycle archived.']);
    }

    // ─── Helpers ─────────────────────────────────────────────────────────────

    private function program(Request $request): ComplianceProgram
    {
        return $request->attributes->get('compliance_program');
    }

    private function authorizeManage(User $user, ComplianceProgram $program): void
    {
        abort_unless($user->isPlatformSuperAdmin() || $user->hasProgramRole($program, 'program-manager'), 403, 'Only the Program Manager can manage cycles.');
    }

    private function findScoped(ComplianceProgram $program, int|string $id): AssessmentCycle
    {
        $cycle = AssessmentCycle::where('id', $id)->where('compliance_program_id', $program->id)->first();
        abort_unless($cycle, 404, 'Cycle not found.');

        return $cycle;
    }
}
