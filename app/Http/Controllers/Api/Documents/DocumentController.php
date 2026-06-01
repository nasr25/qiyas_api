<?php

namespace App\Http\Controllers\Api\Documents;

use App\Http\Controllers\Controller;
use App\Http\Resources\DocumentResource;
use App\Models\Document;
use App\Models\EvidenceRequirement;
use App\Services\AuditService;
use App\Services\DocumentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Manages document lifecycle: creation, upload, submission, and retrieval.
 */
class DocumentController extends Controller
{
    public function __construct(private readonly DocumentService $documentService) {}

    /**
     * Lists documents with filters.
     * GET /api/v1/documents
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        $query = Document::with(['requirement', 'department', 'submitter', 'currentVersionFile'])
            ->withCount('versions');

        // Scope by role
        if ($user->hasRole('employee') || $user->hasRole('coordinator')) {
            $query->where('department_id', $user->department_id);
        }

        $query
            ->when($request->cycle_id, fn($q) => $q->where('cycle_id', $request->cycle_id))
            ->when($request->department_id, fn($q) => $q->where('department_id', $request->department_id))
            ->when($request->status, fn($q) => $q->where('status', $request->status))
            ->when($request->requirement_id, fn($q) => $q->where('requirement_id', $request->requirement_id));

        $documents = $query->latest()->paginate($request->get('per_page', 15));

        return response()->json([
            'success' => true,
            'data'    => DocumentResource::collection($documents),
            'meta'    => [
                'current_page' => $documents->currentPage(),
                'last_page'    => $documents->lastPage(),
                'total'        => $documents->total(),
            ],
        ]);
    }

    /**
     * Creates or gets the document record for a requirement/department/cycle.
     * POST /api/v1/documents
     */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'requirement_id' => ['required', 'exists:evidence_requirements,id'],
            'cycle_id'       => ['required', 'exists:assessment_cycles,id'],
            'title'          => ['required', 'string', 'max:500'],
        ]);

        $user = $request->user();

        $document = Document::firstOrCreate(
            [
                'requirement_id' => $data['requirement_id'],
                'department_id'  => $user->department_id,
                'cycle_id'       => $data['cycle_id'],
            ],
            ['title' => $data['title'], 'status' => 'draft']
        );

        return response()->json([
            'success' => true,
            'data'    => new DocumentResource($document->load(['requirement', 'department'])),
        ], 201);
    }

    /**
     * Shows a document with full details.
     * GET /api/v1/documents/{document}
     */
    public function show(Document $document): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data'    => new DocumentResource($document->load([
                'requirement', 'department', 'submitter', 'reviewer',
                'versions.uploader', 'currentVersionFile',
            ])),
        ]);
    }

    /**
     * Uploads a new file version to a document.
     * POST /api/v1/documents/{document}/upload
     */
    public function upload(Request $request, Document $document): JsonResponse
    {
        // Check cycle is not closed
        if ($document->cycle->isReadOnly()) {
            return response()->json(['success' => false, 'message' => 'This assessment cycle is closed.'], 422);
        }

        $allowedTypes  = Setting('upload.allowed_types', 'pdf,docx,xlsx,pptx,zip');
        $maxSizeMb     = Setting('upload.max_size_mb', 20);
        $allowedMimes  = $this->getMimeTypes($allowedTypes);

        $request->validate([
            'file'          => ['required', 'file', "max:{$maxSizeMb}048", "mimes:{$allowedTypes}"],
            'change_reason' => ['nullable', 'string', 'max:500'],
        ]);

        $version = $this->documentService->uploadVersion(
            $document,
            $request->file('file'),
            $request->user(),
            $request->change_reason
        );

        return response()->json([
            'success' => true,
            'data'    => [
                'version_number' => $version->version_number,
                'filename'       => $version->original_filename,
                'file_size'      => $version->file_size_human,
            ],
            'message' => 'File uploaded successfully.',
        ]);
    }

    /**
     * Submits a document for review.
     * POST /api/v1/documents/{document}/submit
     */
    public function submit(Request $request, Document $document): JsonResponse
    {
        if ($document->cycle->isReadOnly()) {
            return response()->json(['success' => false, 'message' => 'This assessment cycle is closed.'], 422);
        }

        try {
            $document = $this->documentService->submit($document, $request->user());
        } catch (\RuntimeException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }

        return response()->json([
            'success' => true,
            'data'    => new DocumentResource($document),
            'message' => 'Document submitted for review.',
        ]);
    }

    /**
     * Downloads a document version file.
     * GET /api/v1/documents/{document}/versions/{version}/download
     */
    public function download(Document $document, int $versionNumber)
    {
        $version = $document->versions()->where('version_number', $versionNumber)->firstOrFail();

        AuditService::log('document.download', "Downloaded v{$versionNumber} of document #{$document->id}", $document);

        return $this->documentService->streamDownload($version);
    }
}

/**
 * Helper to retrieve a platform setting value.
 */
function Setting(string $key, mixed $default = null): mixed
{
    [$group, $settingKey] = explode('.', $key, 2) + ['general', $key];
    return \App\Models\Setting::get($group, $settingKey, $default);
}
