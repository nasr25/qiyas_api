<?php

namespace App\Http\Controllers\Api\Standards;

use App\Exports\StandardsTemplateExport;
use App\Http\Controllers\Controller;
use App\Http\Resources\StandardResource;
use App\Imports\StandardsImport;
use App\Models\AssessmentCycle;
use App\Models\Standard;
use App\Services\AuditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Standard management within assessment cycles.
 */
class StandardController extends Controller
{
    /**
     * Lists standards for a cycle.
     * GET /api/v1/cycles/{cycle}/standards
     */
    public function index(Request $request, AssessmentCycle $cycle): JsonResponse
    {
        $standards = $cycle->standards()
            ->with(['departments', 'evidenceRequirements'])
            ->withCount('evidenceRequirements')
            ->when($request->search, fn($q) => $q
                ->where('name_ar', 'like', "%{$request->search}%")
                ->orWhere('name_en', 'like', "%{$request->search}%")
                ->orWhere('standard_number', 'like', "%{$request->search}%"))
            ->when($request->department_id, fn($q) => $q->whereHas('departments', fn($dq) => $dq->where('departments.id', $request->department_id)))
            ->paginate($request->get('per_page', 15));

        return response()->json([
            'success' => true,
            'data'    => StandardResource::collection($standards),
            'meta'    => [
                'current_page' => $standards->currentPage(),
                'last_page'    => $standards->lastPage(),
                'total'        => $standards->total(),
            ],
        ]);
    }

    /**
     * Creates a standard in a cycle.
     * POST /api/v1/cycles/{cycle}/standards
     */
    public function store(Request $request, AssessmentCycle $cycle): JsonResponse
    {
        if ($cycle->isReadOnly()) {
            return response()->json(['success' => false, 'message' => 'Cannot add standards to a closed cycle.'], 422);
        }

        $data = $request->validate([
            'standard_number'  => ['required', 'string', 'max:50'],
            'name_ar'          => ['required', 'string', 'max:500'],
            'name_en'          => ['required', 'string', 'max:500'],
            'description'      => ['nullable', 'string'],
            'version'          => ['nullable', 'string', 'max:20'],
            'weight'           => ['nullable', 'numeric', 'min:0', 'max:100'],
            'due_date'         => ['nullable', 'date'],
            'department_ids'   => ['nullable', 'array'],
            'department_ids.*' => ['exists:departments,id'],
        ]);

        $standard = $cycle->standards()->create($data);

        if (!empty($data['department_ids'])) {
            $pivot = array_fill_keys($data['department_ids'], [
                'assigned_at' => now(),
                'assigned_by' => $request->user()->id,
            ]);
            $standard->departments()->attach($pivot);
        }

        AuditService::log('standard.created', "Standard '{$standard->standard_number}' created in cycle #{$cycle->id}", $standard);

        return response()->json([
            'success' => true,
            'data'    => new StandardResource($standard->load(['departments', 'evidenceRequirements'])),
        ], 201);
    }

    /**
     * Downloads the .xlsx template for bulk standard import.
     * GET /api/v1/cycles/{cycle}/standards/template
     */
    public function template(AssessmentCycle $cycle): BinaryFileResponse
    {
        return Excel::download(new StandardsTemplateExport(), 'standards-import-template.xlsx');
    }

    /**
     * Bulk-imports standards into the cycle from an uploaded spreadsheet.
     * POST /api/v1/cycles/{cycle}/standards/import
     */
    public function import(Request $request, AssessmentCycle $cycle): JsonResponse
    {
        if ($cycle->isReadOnly()) {
            return response()->json(['success' => false, 'message' => 'Cannot import standards into a closed cycle.'], 422);
        }

        // Validate by extension — Excel files are often mis-detected by finfo.
        $request->validate(['file' => ['required', 'file', 'max:5120']]);
        $ext = strtolower($request->file('file')->getClientOriginalExtension());
        if (!in_array($ext, ['xlsx', 'xls', 'csv'], true)) {
            return response()->json([
                'success' => false,
                'message' => 'Unsupported file type. Allowed: xlsx, xls, csv.',
                'errors'  => ['file' => ['Unsupported file type.']],
            ], 422);
        }

        $import = new StandardsImport($cycle, $request->user()->id);
        Excel::import($import, $request->file('file'));

        AuditService::log(
            'standard.imported',
            "Imported standards into cycle #{$cycle->id}: {$import->created} created, {$import->updated} updated, " . count($import->errors) . ' error(s)',
        );

        return response()->json([
            'success' => true,
            'message' => 'Import processed.',
            'data'    => [
                'created' => $import->created,
                'updated' => $import->updated,
                'errors'  => $import->errors,
            ],
        ]);
    }

    /**
     * Shows a standard with all requirements.
     * GET /api/v1/cycles/{cycle}/standards/{standard}
     */
    public function show(AssessmentCycle $cycle, Standard $standard): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data'    => new StandardResource($standard->load(['departments', 'evidenceRequirements'])),
        ]);
    }

    /**
     * Shows a single standard by id (not nested under a cycle).
     * GET /api/v1/standards/{standard}
     */
    public function showById(Standard $standard): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data'    => new StandardResource(
                $standard->loadCount('evidenceRequirements')
                    ->load(['departments', 'evidenceRequirements'])
            ),
        ]);
    }

    /**
     * Updates a standard.
     * PUT /api/v1/cycles/{cycle}/standards/{standard}
     */
    public function update(Request $request, AssessmentCycle $cycle, Standard $standard): JsonResponse
    {
        if ($cycle->isReadOnly()) {
            return response()->json(['success' => false, 'message' => 'Cannot edit standards in a closed cycle.'], 422);
        }

        $data = $request->validate([
            'standard_number'  => ['sometimes', 'string', 'max:50'],
            'name_ar'          => ['sometimes', 'string', 'max:500'],
            'name_en'          => ['sometimes', 'string', 'max:500'],
            'description'      => ['nullable', 'string'],
            'version'          => ['nullable', 'string', 'max:20'],
            'weight'           => ['nullable', 'numeric', 'min:0', 'max:100'],
            'due_date'         => ['nullable', 'date'],
            'is_active'        => ['boolean'],
            'department_ids'   => ['nullable', 'array'],
            'department_ids.*' => ['exists:departments,id'],
        ]);

        $standard->update($data);

        if (array_key_exists('department_ids', $data)) {
            $pivot = collect($data['department_ids'] ?? [])->mapWithKeys(fn($id) => [
                $id => ['assigned_at' => now(), 'assigned_by' => $request->user()->id],
            ])->toArray();
            $standard->departments()->sync($pivot);
        }

        AuditService::log('standard.updated', "Standard '{$standard->standard_number}' updated", $standard);

        return response()->json([
            'success' => true,
            'data'    => new StandardResource($standard->fresh()->load(['departments', 'evidenceRequirements'])),
        ]);
    }

    /**
     * Deletes a standard from a draft cycle only.
     * DELETE /api/v1/cycles/{cycle}/standards/{standard}
     */
    public function destroy(AssessmentCycle $cycle, Standard $standard): JsonResponse
    {
        if ($cycle->status !== 'draft') {
            return response()->json(['success' => false, 'message' => 'Can only delete standards from draft cycles.'], 422);
        }

        AuditService::log('standard.deleted', "Standard '{$standard->standard_number}' deleted", $standard);
        $standard->delete();

        return response()->json(['success' => true, 'message' => 'Standard deleted.']);
    }
}
