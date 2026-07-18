<?php

namespace App\Http\Controllers\Api\Workflow;

use App\Exceptions\WorkflowConflictException;
use App\Models\ComplianceProgram;
use App\Models\ExtensionRequest;
use App\Services\ExtensionService;
use App\Services\ProgramConfigurationService;
use App\Services\WorkflowService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class AuditorReviewController extends ReviewQueueController
{
    public function __construct(
        WorkflowService $workflow,
        private readonly ExtensionService $extensions,
    ) {
        parent::__construct($workflow);
    }

    protected function stage(): string
    {
        return 'auditor';
    }

    protected function ability(): string
    {
        return 'reviewAsAuditor';
    }

    protected function roleLabel(): string
    {
        return 'auditor';
    }

    protected function scopeToReviewer($query, Request $request, ComplianceProgram $program)
    {
        // Auditors review across the whole program, not scoped to a department.
        return $query;
    }

    protected function authorizeQueueAccess(Request $request, ComplianceProgram $program): bool
    {
        return $request->user()->isPlatformSuperAdmin() || $request->user()->hasProgramRole($program, 'auditor');
    }

    /**
     * GET /api/v1/programs/{program}/reviews/auditor/extension-requests
     *
     * The URL path is fixed to "auditor" (this is Qiyas's own review-queue
     * naming), but the actual access check below reads the configured
     * extension reviewer role rather than hardcoding it — see
     * docs/compliance-engine-known-issues.md for why the route path itself
     * is not yet program-configurable.
     */
    public function extensionRequests(Request $request): JsonResponse
    {
        $program = $this->program($request);
        if (! $request->user()->isPlatformSuperAdmin() && ! $this->isConfiguredExtensionReviewer($request, $program)) {
            return response()->json(['success' => false, 'message' => 'Forbidden.'], 403);
        }

        $extensions = ExtensionRequest::where('compliance_program_id', $program->id)
            ->whereNotNull('requirement_assignment_id')
            ->with(['requester', 'reviewer', 'assignment.requirement', 'assignment.department'])
            ->when($request->status, fn ($q) => $q->where('status', $request->status))
            ->latest('requested_date')
            ->paginate($request->get('per_page', 20));

        return response()->json([
            'success' => true,
            'data' => $extensions->getCollection()->map(fn (ExtensionRequest $e) => [
                'id' => $e->id,
                'requirement' => $e->assignment ? ['id' => $e->assignment->requirement->id, 'code' => $e->assignment->requirement->standard_number, 'name' => $e->assignment->requirement->name] : null,
                'department' => $e->assignment?->department?->name,
                'requested_by' => $e->requester?->name,
                'current_due_date' => $e->current_due_date?->toDateString(),
                'requested_due_date' => $e->requested_due_date?->toDateString(),
                'reason' => $e->reason,
                'status' => $e->status,
            ])->values(),
            'meta' => ['current_page' => $extensions->currentPage(), 'last_page' => $extensions->lastPage(), 'total' => $extensions->total()],
        ]);
    }

    /** POST /api/v1/programs/{program}/reviews/auditor/extension-requests/{extensionRequest}/approve */
    public function approveExtension(Request $request, string $program, int|string $extensionRequest): JsonResponse
    {
        return $this->decideExtension($request, $extensionRequest, 'approved');
    }

    /** POST /api/v1/programs/{program}/reviews/auditor/extension-requests/{extensionRequest}/reject */
    public function rejectExtension(Request $request, string $program, int|string $extensionRequest): JsonResponse
    {
        return $this->decideExtension($request, $extensionRequest, 'rejected');
    }

    private function decideExtension(Request $request, int|string $id, string $decision): JsonResponse
    {
        $program = $this->program($request);

        $model = ExtensionRequest::where('id', $id)->where('compliance_program_id', $program->id)->first();
        if (! $model) {
            return response()->json(['success' => false, 'message' => 'Extension request not found.'], 404);
        }

        // The real, program-configurable authorization check (see
        // ExtensionRequestPolicy::decide() and program config category
        // 'extensions'.'reviewer_role') — not a hardcoded role literal.
        if (Gate::forUser($request->user())->denies('decide', $model)) {
            return response()->json(['success' => false, 'message' => 'Forbidden.'], 403);
        }

        $rules = ['notes' => ['nullable', 'string', 'max:2000']];
        if ($decision === 'rejected') {
            $rules['reason'] = ['required', 'string', 'max:2000'];
        }
        $data = $request->validate($rules);

        try {
            $updated = $this->extensions->decide($model, $request->user(), $decision, $data['reason'] ?? null, $data['notes'] ?? null);
        } catch (WorkflowConflictException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 409);
        }

        return response()->json(['success' => true, 'data' => ['id' => $updated->id, 'status' => $updated->status]]);
    }

    private function isConfiguredExtensionReviewer(Request $request, ComplianceProgram $program): bool
    {
        $config = app(ProgramConfigurationService::class)->get($program, 'extensions', ['reviewer_role' => 'auditor']);

        return $request->user()->hasProgramRole($program, $config['reviewer_role'] ?? 'auditor');
    }
}
