<?php

namespace App\Http\Controllers\Api\Cycles;

use App\Http\Controllers\Controller;
use App\Http\Resources\AssessmentCycleResource;
use App\Models\AssessmentCycle;
use App\Services\CycleService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Manages the full lifecycle of assessment cycles.
 */
class AssessmentCycleController extends Controller
{
    public function __construct(private readonly CycleService $cycleService) {}

    /**
     * Lists all cycles.
     * GET /api/v1/cycles
     */
    public function index(Request $request): JsonResponse
    {
        $cycles = AssessmentCycle::withCount('standards')
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

    /**
     * Creates a new draft cycle.
     * POST /api/v1/cycles
     */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'year' => ['required', 'integer', 'min:2020', 'max:2100'],
            'start_date' => ['required', 'date'],
            'end_date' => ['required', 'date', 'after:start_date'],
            'copy_from_cycle' => ['nullable', 'exists:assessment_cycles,id'],
        ]);

        $cycle = $this->cycleService->create($data, $request->user());

        if (! empty($data['copy_from_cycle'])) {
            $source = AssessmentCycle::findOrFail($data['copy_from_cycle']);
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

    /**
     * Shows a cycle with its standards count.
     * GET /api/v1/cycles/{cycle}
     */
    public function show(AssessmentCycle $cycle): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => new AssessmentCycleResource($cycle->loadCount('standards')->load('creator')),
        ]);
    }

    /**
     * Updates a draft cycle.
     * PUT /api/v1/cycles/{cycle}
     */
    public function update(Request $request, AssessmentCycle $cycle): JsonResponse
    {
        if ($cycle->status !== 'draft') {
            return response()->json(['success' => false, 'message' => 'Only draft cycles can be edited.'], 422);
        }

        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'year' => ['sometimes', 'integer', 'min:2020', 'max:2100'],
            'start_date' => ['sometimes', 'date'],
            'end_date' => ['sometimes', 'date', 'after:start_date'],
        ]);

        $cycle->update($data);

        return response()->json([
            'success' => true,
            'data' => new AssessmentCycleResource($cycle->fresh()),
        ]);
    }

    /**
     * Activates a draft cycle.
     * POST /api/v1/cycles/{cycle}/activate
     */
    public function activate(AssessmentCycle $cycle): JsonResponse
    {
        try {
            $cycle = $this->cycleService->activate($cycle);
        } catch (\RuntimeException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }

        return response()->json([
            'success' => true,
            'data' => new AssessmentCycleResource($cycle),
            'message' => 'Cycle activated successfully.',
        ]);
    }

    /**
     * Closes an active cycle.
     * POST /api/v1/cycles/{cycle}/close
     */
    public function close(Request $request, AssessmentCycle $cycle): JsonResponse
    {
        $data = $request->validate([
            'final_score' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'closing_notes' => ['nullable', 'string', 'max:2000'],
        ]);

        try {
            $cycle = $this->cycleService->close($cycle, $data['final_score'] ?? null, $data['closing_notes'] ?? null);
        } catch (\RuntimeException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }

        return response()->json([
            'success' => true,
            'data' => new AssessmentCycleResource($cycle),
            'message' => 'Cycle closed successfully.',
        ]);
    }

    /**
     * Archives a closed cycle.
     * POST /api/v1/cycles/{cycle}/archive
     */
    public function archive(AssessmentCycle $cycle): JsonResponse
    {
        try {
            $cycle = $this->cycleService->archive($cycle);
        } catch (\RuntimeException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }

        return response()->json([
            'success' => true,
            'data' => new AssessmentCycleResource($cycle),
            'message' => 'Cycle archived.',
        ]);
    }
}
