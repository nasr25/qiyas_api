<?php

namespace App\Http\Controllers\Api\Auditor;

use App\Http\Controllers\Controller;
use App\Http\Resources\DocumentResource;
use App\Http\Resources\ExtensionRequestResource;
use App\Models\Document;
use App\Models\ExtensionRequest;
use App\Services\AuditService;
use App\Services\DocumentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Auditor-specific endpoints: review queue, approve/reject documents and extensions.
 */
class AuditorController extends Controller
{
    public function __construct(private readonly DocumentService $documentService) {}

    /**
     * Returns documents pending auditor review.
     * GET /api/v1/auditor/pending-reviews
     */
    public function pendingReviews(Request $request): JsonResponse
    {
        $documents = Document::with(['requirement.standard', 'department', 'submitter', 'currentVersionFile'])
            ->where('status', 'under_review')
            ->when($request->cycle_id, fn($q) => $q->where('cycle_id', $request->cycle_id))
            ->when($request->department_id, fn($q) => $q->where('department_id', $request->department_id))
            ->latest('submitted_at')
            ->paginate($request->get('per_page', 15));

        return response()->json([
            'success' => true,
            'data'    => DocumentResource::collection($documents),
            'meta'    => [
                'current_page' => $documents->currentPage(),
                'last_page'    => $documents->lastPage(),
                'total'        => $documents->total(),
            ],
            'stats'   => [
                'approved_today' => Document::where('status', 'approved')
                    ->where('reviewed_by', $request->user()->id)
                    ->whereDate('reviewed_at', today())->count(),
                'rejected_today' => Document::where('status', 'rejected')
                    ->where('reviewed_by', $request->user()->id)
                    ->whereDate('reviewed_at', today())->count(),
            ],
        ]);
    }

    /**
     * Approves a document under review.
     * POST /api/v1/auditor/documents/{document}/approve
     */
    public function approve(Document $document, Request $request): JsonResponse
    {
        try {
            $document = $this->documentService->approve($document, $request->user());
        } catch (\RuntimeException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }

        return response()->json([
            'success' => true,
            'data'    => new DocumentResource($document),
            'message' => 'Document approved.',
        ]);
    }

    /**
     * Rejects a document with a mandatory reason.
     * POST /api/v1/auditor/documents/{document}/reject
     */
    public function reject(Request $request, Document $document): JsonResponse
    {
        $request->validate(['reason' => ['required', 'string', 'min:10', 'max:2000']]);

        try {
            $document = $this->documentService->reject($document, $request->user(), $request->reason);
        } catch (\RuntimeException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }

        return response()->json([
            'success' => true,
            'data'    => new DocumentResource($document),
            'message' => 'Document rejected.',
        ]);
    }

    /**
     * Lists pending extension requests.
     * GET /api/v1/auditor/extension-requests
     */
    public function extensionRequests(Request $request): JsonResponse
    {
        $requests = ExtensionRequest::with(['document.requirement', 'document.department', 'requester'])
            ->when($request->status, fn($q) => $q->where('status', $request->status))
            ->latest()
            ->paginate($request->get('per_page', 15));

        return response()->json([
            'success' => true,
            'data'    => ExtensionRequestResource::collection($requests),
            'meta'    => [
                'current_page' => $requests->currentPage(),
                'last_page'    => $requests->lastPage(),
                'total'        => $requests->total(),
            ],
        ]);
    }

    /**
     * Approves an extension request.
     * POST /api/v1/auditor/extension-requests/{extensionRequest}/approve
     */
    public function approveExtension(Request $request, ExtensionRequest $extensionRequest): JsonResponse
    {
        if (!$extensionRequest->isPending()) {
            return response()->json(['success' => false, 'message' => 'This request is not pending.'], 422);
        }

        $data = $request->validate(['reviewer_notes' => ['nullable', 'string', 'max:1000']]);

        $extensionRequest->update([
            'status'         => 'approved',
            'reviewed_by'    => $request->user()->id,
            'reviewed_at'    => now(),
            'reviewer_notes' => $data['reviewer_notes'] ?? null,
        ]);

        AuditService::log('extension.approved', "Extension request #{$extensionRequest->id} approved", $extensionRequest);

        // Notify requester
        $extensionRequest->requester->notify(
            new \App\Notifications\ExtensionApprovedNotification($extensionRequest)
        );

        return response()->json([
            'success' => true,
            'data'    => new ExtensionRequestResource($extensionRequest->fresh()),
            'message' => 'Extension approved.',
        ]);
    }

    /**
     * Rejects an extension request.
     * POST /api/v1/auditor/extension-requests/{extensionRequest}/reject
     */
    public function rejectExtension(Request $request, ExtensionRequest $extensionRequest): JsonResponse
    {
        if (!$extensionRequest->isPending()) {
            return response()->json(['success' => false, 'message' => 'This request is not pending.'], 422);
        }

        $data = $request->validate(['reviewer_notes' => ['required', 'string', 'min:5', 'max:1000']]);

        $extensionRequest->update([
            'status'         => 'rejected',
            'reviewed_by'    => $request->user()->id,
            'reviewed_at'    => now(),
            'reviewer_notes' => $data['reviewer_notes'],
        ]);

        AuditService::log('extension.rejected', "Extension request #{$extensionRequest->id} rejected", $extensionRequest);

        $extensionRequest->requester->notify(
            new \App\Notifications\ExtensionRejectedNotification($extensionRequest)
        );

        return response()->json([
            'success' => true,
            'data'    => new ExtensionRequestResource($extensionRequest->fresh()),
            'message' => 'Extension rejected.',
        ]);
    }
}
