<?php

namespace App\Http\Controllers\Api\Workflow;

use App\Exceptions\WorkflowConflictException;
use App\Http\Controllers\Controller;
use App\Models\ComplianceProgram;
use App\Models\EvidenceFile;
use App\Models\EvidenceSubmission;
use App\Models\RequirementAssignment;
use App\Services\AuditService;
use App\Services\EvidenceUploadValidator;
use App\Services\WorkflowService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;

/**
 * Employee-facing evidence submission actions: open/create a draft, upload
 * and remove files, submit for review, view the version timeline. Secure
 * download is exposed here too (authorized for anyone who can view the
 * submission, not only the owning employee).
 */
class EvidenceSubmissionController extends Controller
{
    public function __construct(
        private readonly WorkflowService $workflow,
        private readonly EvidenceUploadValidator $uploadValidator,
    ) {}

    /** POST /api/v1/programs/{program}/assignments/{assignment}/draft */
    public function openDraft(Request $request, string $program, int|string $assignment): JsonResponse
    {
        $resolvedProgram = $this->program($request);
        $model = RequirementAssignment::where('id', $assignment)->where('compliance_program_id', $resolvedProgram->id)->first();
        if (! $model) {
            return response()->json(['success' => false, 'message' => 'Assignment not found.'], 404);
        }
        if (Gate::forUser($request->user())->denies('view', $model)) {
            return response()->json(['success' => false, 'message' => 'Forbidden.'], 403);
        }

        $submission = $this->workflow->getOrCreateDraft($model, $request->user());

        return response()->json(['success' => true, 'data' => $this->detail($submission)]);
    }

    /** GET /api/v1/programs/{program}/evidence-submissions/{submission} */
    public function show(Request $request, string $program, int|string $submission): JsonResponse
    {
        $model = $this->findScoped($this->program($request), $submission);
        if (! $model) {
            return response()->json(['success' => false, 'message' => 'Submission not found.'], 404);
        }
        if ($request->user()->cannot('view', $model)) {
            return response()->json(['success' => false, 'message' => 'Forbidden.'], 403);
        }

        return response()->json(['success' => true, 'data' => $this->detail($model)]);
    }

    /** GET /api/v1/programs/{program}/evidence-submissions/{submission}/timeline */
    public function timeline(Request $request, string $program, int|string $submission): JsonResponse
    {
        $model = $this->findScoped($this->program($request), $submission);
        if (! $model) {
            return response()->json(['success' => false, 'message' => 'Submission not found.'], 404);
        }
        if ($request->user()->cannot('view', $model)) {
            return response()->json(['success' => false, 'message' => 'Forbidden.'], 403);
        }

        $events = $model->assignment->events()->with('user')->get();

        return response()->json([
            'success' => true,
            'data' => $events->map(fn ($e) => [
                'event_type' => $e->event_type,
                'user' => $e->user?->name,
                'role' => $e->role,
                'notes' => $e->notes,
                'old_status' => $e->old_status,
                'new_status' => $e->new_status,
                'evidence_version' => $e->evidence_version,
                'created_at' => $e->created_at->toIso8601String(),
            ]),
        ]);
    }

    /** POST /api/v1/programs/{program}/evidence-submissions/{submission}/files */
    public function uploadFile(Request $request, string $program, int|string $submission): JsonResponse
    {
        $model = $this->findScoped($this->program($request), $submission);
        if (! $model) {
            return response()->json(['success' => false, 'message' => 'Submission not found.'], 404);
        }
        if ($request->user()->cannot('edit', $model)) {
            return response()->json(['success' => false, 'message' => 'Forbidden.'], 403);
        }

        $request->validate(['file' => ['required', 'file']]);
        $file = $request->file('file');
        $locale = app()->getLocale();

        if ($error = $this->uploadValidator->validateFile($file)) {
            return response()->json(['success' => false, 'message' => $error[$locale] ?? $error['en']], 422);
        }
        if ($error = $this->uploadValidator->validateSubmissionLimits($model, $file)) {
            return response()->json(['success' => false, 'message' => $error[$locale] ?? $error['en']], 422);
        }

        try {
            $evidenceFile = $this->workflow->addFile($model, $file, $request->user());
        } catch (WorkflowConflictException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 409);
        }

        return response()->json([
            'success' => true,
            'data' => [
                'id' => $evidenceFile->id, 'original_name' => $evidenceFile->original_name,
                'file_size' => $evidenceFile->file_size_human, 'uploaded_at' => $evidenceFile->uploaded_at->toIso8601String(),
            ],
        ], 201);
    }

    /** DELETE /api/v1/programs/{program}/evidence-files/{file} */
    public function removeFile(Request $request, string $program, int|string $file): JsonResponse
    {
        $evidenceFile = EvidenceFile::with('submission')->find($file);
        if (! $evidenceFile || $evidenceFile->submission->compliance_program_id !== $this->program($request)->id) {
            return response()->json(['success' => false, 'message' => 'File not found.'], 404);
        }
        if ($request->user()->cannot('edit', $evidenceFile->submission)) {
            return response()->json(['success' => false, 'message' => 'Forbidden.'], 403);
        }

        try {
            $this->workflow->removeFile($evidenceFile, $request->user());
        } catch (WorkflowConflictException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 409);
        }

        return response()->json(['success' => true, 'message' => 'File removed.']);
    }

    /** GET /api/v1/programs/{program}/evidence-files/{file}/download */
    public function downloadFile(Request $request, string $program, int|string $file)
    {
        $evidenceFile = EvidenceFile::with('submission')->find($file);
        if (! $evidenceFile || $evidenceFile->submission->compliance_program_id !== $this->program($request)->id) {
            return response()->json(['success' => false, 'message' => 'File not found.'], 404);
        }
        if ($request->user()->cannot('downloadFile', $evidenceFile->submission)) {
            return response()->json(['success' => false, 'message' => 'Forbidden.'], 403);
        }

        AuditService::log(
            'evidence.downloaded',
            "Downloaded {$evidenceFile->original_name} from submission #{$evidenceFile->evidence_submission_id}",
            $evidenceFile->submission,
            complianceProgramId: $evidenceFile->submission->compliance_program_id,
        );

        return Storage::disk('private')->download($evidenceFile->storage_path, $evidenceFile->original_name);
    }

    /** POST /api/v1/programs/{program}/evidence-submissions/{submission}/submit */
    public function submit(Request $request, string $program, int|string $submission): JsonResponse
    {
        $model = $this->findScoped($this->program($request), $submission);
        if (! $model) {
            return response()->json(['success' => false, 'message' => 'Submission not found.'], 404);
        }
        if ($request->user()->cannot('edit', $model)) {
            return response()->json(['success' => false, 'message' => 'Forbidden.'], 403);
        }

        $data = $request->validate(['comment' => ['nullable', 'string', 'max:2000']]);

        try {
            $updated = $this->workflow->submit($model, $request->user(), $data['comment'] ?? null);
        } catch (WorkflowConflictException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 409);
        }

        return response()->json(['success' => true, 'data' => $this->detail($updated), 'message' => 'Submitted for review.']);
    }

    // ─── Helpers ─────────────────────────────────────────────────────────────

    private function program(Request $request): ComplianceProgram
    {
        return $request->attributes->get('compliance_program');
    }

    private function findScoped(ComplianceProgram $program, int|string $id): ?EvidenceSubmission
    {
        return EvidenceSubmission::where('id', $id)->where('compliance_program_id', $program->id)->first();
    }

    private function detail(EvidenceSubmission $submission): array
    {
        $submission->load(['activeFiles', 'decisions.reviewer', 'assignment.requirement', 'assignment.department']);

        return [
            'id' => $submission->id,
            'version_number' => $submission->version_number,
            'status' => $submission->status,
            'current_stage' => $submission->current_stage,
            'employee_comment' => $submission->employee_comment,
            'submitted_at' => $submission->submitted_at?->toIso8601String(),
            'returned_at' => $submission->returned_at?->toIso8601String(),
            'approved_at' => $submission->approved_at?->toIso8601String(),
            'files' => $submission->activeFiles->map(fn ($f) => [
                'id' => $f->id, 'original_name' => $f->original_name,
                'file_size' => $f->file_size_human, 'uploaded_at' => $f->uploaded_at->toIso8601String(),
            ]),
            'decisions' => $submission->decisions->map(fn ($d) => [
                'stage' => $d->stage, 'decision' => $d->decision, 'reviewer' => $d->reviewer?->name,
                'notes' => $d->notes, 'rejection_reason' => $d->rejection_reason, 'decided_at' => $d->decided_at->toIso8601String(),
            ]),
            'requirement' => ['id' => $submission->assignment->requirement->id, 'code' => $submission->assignment->requirement->standard_number, 'name' => $submission->assignment->requirement->name],
            'department' => $submission->assignment->department->name,
        ];
    }
}
