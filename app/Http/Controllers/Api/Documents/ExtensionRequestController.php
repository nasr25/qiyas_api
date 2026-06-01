<?php

namespace App\Http\Controllers\Api\Documents;

use App\Http\Controllers\Controller;
use App\Http\Resources\ExtensionRequestResource;
use App\Models\Document;
use App\Models\ExtensionRequest;
use App\Services\AuditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Extension request submission by employees/coordinators.
 */
class ExtensionRequestController extends Controller
{
    /**
     * Lists extension requests for a document.
     * GET /api/v1/documents/{document}/extension-requests
     */
    public function index(Document $document): JsonResponse
    {
        $requests = ExtensionRequest::with(['requester', 'reviewer'])
            ->where('document_id', $document->id)
            ->latest()
            ->get();

        return response()->json([
            'success' => true,
            'data'    => ExtensionRequestResource::collection($requests),
        ]);
    }

    /**
     * Submits a new extension request.
     * POST /api/v1/documents/{document}/extension-requests
     */
    public function store(Request $request, Document $document): JsonResponse
    {
        // Check no pending request exists for this document
        if ($document->extensionRequests()->where('status', 'pending')->exists()) {
            return response()->json([
                'success' => false,
                'message' => 'A pending extension request already exists for this document.',
            ], 422);
        }

        $data = $request->validate([
            'requested_date' => ['required', 'date', 'after:today'],
            'reason'         => ['required', 'string', 'min:20', 'max:2000'],
        ]);

        $extensionRequest = ExtensionRequest::create([
            'document_id'    => $document->id,
            'requested_by'   => $request->user()->id,
            'requested_date' => $data['requested_date'],
            'reason'         => $data['reason'],
            'status'         => 'pending',
        ]);

        AuditService::log('extension.requested', "Extension requested for document #{$document->id}", $document);

        // Notify auditors
        $auditors = \App\Models\User::role('auditor')->where('is_active', true)->get();
        foreach ($auditors as $auditor) {
            $auditor->notify(new \App\Notifications\ExtensionRequestedNotification($extensionRequest));
        }

        return response()->json([
            'success' => true,
            'data'    => new ExtensionRequestResource($extensionRequest),
            'message' => 'Extension request submitted.',
        ], 201);
    }
}
