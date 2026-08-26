<?php

namespace App\Http\Controllers\Api\Workflow;

use App\Exceptions\WorkflowConflictException;
use App\Http\Controllers\Controller;
use App\Models\ComplianceProgram;
use App\Models\ExtensionRequest;
use App\Models\RequirementAssignment;
use App\Services\ExtensionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ExtensionRequestController extends Controller
{
    public function __construct(private readonly ExtensionService $extensions) {}

    /** POST /api/v1/programs/{program}/assignments/{assignment}/extension-requests */
    public function store(Request $request, string $program, int|string $assignment): JsonResponse
    {
        $resolvedProgram = $this->program($request);
        $model = RequirementAssignment::where('id', $assignment)->where('compliance_program_id', $resolvedProgram->id)->first();
        if (! $model) {
            return response()->json(['success' => false, 'message' => 'Assignment not found.'], 404);
        }
        if ($request->user()->cannot('view', $model)) {
            return response()->json(['success' => false, 'message' => 'Forbidden.'], 403);
        }

        $data = $request->validate([
            'requested_due_date' => ['required', 'date', 'after:today'],
            'reason' => ['required', 'string', 'max:2000'],
        ]);

        try {
            $extension = $this->extensions->request($model, $request->user(), $data['requested_due_date'], $data['reason']);
        } catch (WorkflowConflictException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 409);
        }

        return response()->json(['success' => true, 'data' => $this->summarize($extension)], 201);
    }

    /** POST /api/v1/programs/{program}/extension-requests/{extensionRequest}/cancel */
    public function cancel(Request $request, string $program, int|string $extensionRequest): JsonResponse
    {
        $model = $this->findScoped($this->program($request), $extensionRequest);
        if (! $model) {
            return response()->json(['success' => false, 'message' => 'Extension request not found.'], 404);
        }

        try {
            $updated = $this->extensions->cancel($model, $request->user());
        } catch (WorkflowConflictException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 409);
        }

        return response()->json(['success' => true, 'data' => $this->summarize($updated)]);
    }

    /** GET /api/v1/programs/{program}/assignments/{assignment}/extension-requests */
    public function forAssignment(Request $request, string $program, int|string $assignment): JsonResponse
    {
        $resolvedProgram = $this->program($request);
        $model = RequirementAssignment::where('id', $assignment)->where('compliance_program_id', $resolvedProgram->id)->first();
        if (! $model) {
            return response()->json(['success' => false, 'message' => 'Assignment not found.'], 404);
        }
        if ($request->user()->cannot('view', $model)) {
            return response()->json(['success' => false, 'message' => 'Forbidden.'], 403);
        }

        $extensions = ExtensionRequest::where('requirement_assignment_id', $model->id)->with('requester', 'reviewer')->latest('requested_at')->get();

        return response()->json(['success' => true, 'data' => $extensions->map(fn ($e) => $this->summarize($e))]);
    }

    private function program(Request $request): ComplianceProgram
    {
        return $request->attributes->get('compliance_program');
    }

    private function findScoped(ComplianceProgram $program, int|string $id): ?ExtensionRequest
    {
        return ExtensionRequest::where('id', $id)->where('compliance_program_id', $program->id)->first();
    }

    private function summarize(ExtensionRequest $e): array
    {
        return [
            'id' => $e->id,
            'requested_by' => $e->requester?->name,
            'requested_date' => $e->requested_date?->toDateString(),
            'current_due_date' => $e->current_due_date?->toDateString(),
            'requested_due_date' => $e->requested_due_date?->toDateString(),
            'reason' => $e->reason,
            'status' => $e->status,
            'reviewed_by' => $e->reviewer?->name,
            'reviewed_at' => $e->reviewed_at?->toIso8601String(),
            'reviewer_notes' => $e->reviewer_notes,
        ];
    }
}
