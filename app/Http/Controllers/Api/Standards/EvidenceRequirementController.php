<?php

namespace App\Http\Controllers\Api\Standards;

use App\Http\Controllers\Controller;
use App\Http\Resources\EvidenceRequirementResource;
use App\Models\EvidenceRequirement;
use App\Models\Standard;
use App\Services\AuditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Evidence requirement management within standards.
 */
class EvidenceRequirementController extends Controller
{
    /**
     * Lists requirements for a standard.
     * GET /api/v1/standards/{standard}/requirements
     */
    public function index(Standard $standard): JsonResponse
    {
        $requirements = $standard->evidenceRequirements()->get();

        return response()->json([
            'success' => true,
            'data'    => EvidenceRequirementResource::collection($requirements),
        ]);
    }

    /**
     * Creates a requirement.
     * POST /api/v1/standards/{standard}/requirements
     */
    public function store(Request $request, Standard $standard): JsonResponse
    {
        $data = $request->validate([
            'title_ar'     => ['required', 'string', 'max:500'],
            'title_en'     => ['required', 'string', 'max:500'],
            'description'  => ['nullable', 'string'],
            'is_mandatory' => ['boolean'],
            'sort_order'   => ['nullable', 'integer', 'min:0'],
        ]);

        $data['sort_order'] = $data['sort_order'] ?? ($standard->evidenceRequirements()->max('sort_order') + 1);

        $requirement = $standard->evidenceRequirements()->create($data);
        AuditService::log('requirement.created', "Requirement '{$requirement->title_en}' created", $requirement);

        return response()->json([
            'success' => true,
            'data'    => new EvidenceRequirementResource($requirement),
        ], 201);
    }

    /**
     * Shows a requirement.
     * GET /api/v1/standards/{standard}/requirements/{requirement}
     */
    public function show(Standard $standard, EvidenceRequirement $requirement): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data'    => new EvidenceRequirementResource($requirement),
        ]);
    }

    /**
     * Updates a requirement.
     * PUT /api/v1/standards/{standard}/requirements/{requirement}
     */
    public function update(Request $request, Standard $standard, EvidenceRequirement $requirement): JsonResponse
    {
        $data = $request->validate([
            'title_ar'     => ['sometimes', 'string', 'max:500'],
            'title_en'     => ['sometimes', 'string', 'max:500'],
            'description'  => ['nullable', 'string'],
            'is_mandatory' => ['boolean'],
            'sort_order'   => ['nullable', 'integer', 'min:0'],
        ]);

        $requirement->update($data);

        return response()->json([
            'success' => true,
            'data'    => new EvidenceRequirementResource($requirement->fresh()),
        ]);
    }

    /**
     * Deletes a requirement.
     * DELETE /api/v1/standards/{standard}/requirements/{requirement}
     */
    public function destroy(Standard $standard, EvidenceRequirement $requirement): JsonResponse
    {
        if ($requirement->documents()->exists()) {
            return response()->json(['success' => false, 'message' => 'Cannot delete: requirement has documents.'], 422);
        }

        AuditService::log('requirement.deleted', "Requirement '{$requirement->title_en}' deleted", $requirement);
        $requirement->delete();

        return response()->json(['success' => true, 'message' => 'Requirement deleted.']);
    }
}
