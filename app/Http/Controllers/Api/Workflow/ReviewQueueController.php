<?php

namespace App\Http\Controllers\Api\Workflow;

use App\Exceptions\WorkflowConflictException;
use App\Http\Controllers\Controller;
use App\Models\ComplianceNode;
use App\Models\ComplianceProgram;
use App\Models\EvidenceSubmission;
use App\Services\WorkflowService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Shared logic for the three review queues (Department Manager, Auditor,
 * Program Manager). Each concrete controller only supplies its stage key,
 * ability name, and role label — the queue listing and approve/reject
 * mechanics are identical, so they live here once rather than being
 * duplicated three times.
 */
abstract class ReviewQueueController extends Controller
{
    public function __construct(protected readonly WorkflowService $workflow) {}

    abstract protected function stage(): string;

    abstract protected function ability(): string;

    abstract protected function roleLabel(): string;

    /** Whether the caller may view this stage's queue at all (role-level, not tied to one submission). */
    abstract protected function authorizeQueueAccess(Request $request, ComplianceProgram $program): bool;

    /** GET .../reviews/{stage} */
    public function index(Request $request): JsonResponse
    {
        $program = $this->program($request);

        if (! $this->authorizeQueueAccess($request, $program)) {
            return response()->json(['success' => false, 'message' => 'Forbidden.'], 403);
        }

        $status = 'pending_'.$this->stage();

        $query = EvidenceSubmission::where('compliance_program_id', $program->id)
            ->where('status', $status)
            ->with(['assignment.requirement', 'assignment.department', 'department']);

        $query = $this->scopeToReviewer($query, $request, $program);

        $query->when($request->cycle_id, fn ($q) => $q->where('program_cycle_id', $request->cycle_id))
            ->when($request->department_id, fn ($q) => $q->where('department_id', $request->department_id))
            // Depth-agnostic replacement for the old perspective/axis pair:
            // filter by ANY ancestor node and get its whole subtree.
            ->when($request->ancestor_id, fn ($q) => $q->whereIn(
                'compliance_node_id',
                ComplianceNode::subtreeIds((int) $request->ancestor_id),
            ));

        $submissions = $query->latest('submitted_at')->paginate($request->get('per_page', 20));

        return response()->json([
            'success' => true,
            // getCollection()->map() (not paginator ->through()) — see the
            // note in RequirementAssignmentController::index().
            'data' => $submissions->getCollection()->map(fn (EvidenceSubmission $s) => [
                'id' => $s->id,
                'version_number' => $s->version_number,
                'requirement' => ['id' => $s->assignment->requirement->id, 'code' => $s->assignment->requirement->code, 'name' => $s->assignment->requirement->name],
                'department' => $s->department->name,
                'submitted_at' => $s->submitted_at?->toIso8601String(),
                'effective_due_date' => $s->assignment->effective_due_date?->toDateString(),
            ])->values(),
            'meta' => ['current_page' => $submissions->currentPage(), 'last_page' => $submissions->lastPage(), 'total' => $submissions->total()],
        ]);
    }

    /** POST .../reviews/{stage}/{submission}/approve */
    public function approve(Request $request, string $program, int|string $submission): JsonResponse
    {
        return $this->decide($request, $submission, 'approved');
    }

    /** POST .../reviews/{stage}/{submission}/reject */
    public function reject(Request $request, string $program, int|string $submission): JsonResponse
    {
        return $this->decide($request, $submission, 'rejected');
    }

    private function decide(Request $request, int|string $submissionId, string $decision): JsonResponse
    {
        $program = $this->program($request);
        $model = EvidenceSubmission::where('id', $submissionId)->where('compliance_program_id', $program->id)->first();
        if (! $model) {
            return response()->json(['success' => false, 'message' => 'Submission not found.'], 404);
        }
        if ($request->user()->cannot($this->ability(), $model)) {
            return response()->json(['success' => false, 'message' => 'Forbidden.'], 403);
        }

        $rules = ['notes' => ['nullable', 'string', 'max:2000']];
        if ($decision === 'rejected') {
            $rules['reason'] = ['required', 'string', 'max:2000'];
        }
        $data = $request->validate($rules);

        try {
            $updated = $decision === 'approved'
                ? $this->workflow->approve($model, $request->user(), $this->stage(), $this->roleLabel(), $data['notes'] ?? null)
                : $this->workflow->reject($model, $request->user(), $this->stage(), $this->roleLabel(), $data['reason'], $data['notes'] ?? null);
        } catch (WorkflowConflictException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 409);
        }

        return response()->json(['success' => true, 'data' => ['id' => $updated->id, 'status' => $updated->status]]);
    }

    protected function program(Request $request): ComplianceProgram
    {
        return $request->attributes->get('compliance_program');
    }

    /** Narrows the queue to what this reviewer role may see (department for managers, program-wide for auditor/program manager). */
    abstract protected function scopeToReviewer($query, Request $request, ComplianceProgram $program);
}
