<?php

namespace App\Http\Controllers\Api\Programs;

use App\Exceptions\WorkflowConflictException;
use App\Exports\Hierarchy\HierarchyImportErrorReport;
use App\Exports\Hierarchy\HierarchyNodesExport;
use App\Exports\Hierarchy\HierarchyTemplateExport;
use App\Http\Controllers\Controller;
use App\Models\AssessmentCycle;
use App\Models\ComplianceProgram;
use App\Models\HierarchyLevelDefinition;
use App\Models\ImportLog;
use App\Models\User;
use App\Policies\HierarchyStructurePolicy;
use App\Services\HierarchyDefinitionService;
use App\Services\HierarchyImportService;
use App\Services\HierarchyImportValidator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Structure-driven XLSX template, import and export.
 *
 * One controller for every program at every depth: the template's column
 * count comes from the active structure, and the importer validates against
 * that same structure. Writes require the program-manager role for THIS
 * program (HierarchyStructurePolicy), since importing content is a
 * structural act.
 */
class HierarchyImportController extends Controller
{
    public function __construct(
        private readonly HierarchyImportService $imports,
        private readonly HierarchyDefinitionService $structures,
    ) {}

    /** GET /programs/{program}/hierarchy-template */
    public function template(Request $request): BinaryFileResponse
    {
        $program = $this->program($request);
        $levels = $this->requireLevels($program);

        $export = new HierarchyTemplateExport(
            $program,
            $levels,
            $this->structures->currentStructureVersion($program),
            $this->resolveCycle($program, $request),
        );

        $version = $this->structures->currentStructureVersion($program)?->version ?? 0;

        return Excel::download($export, strtolower($program->code)."-hierarchy-template-v{$version}.xlsx");
    }

    /** GET /programs/{program}/hierarchy-export */
    public function export(Request $request): BinaryFileResponse
    {
        $program = $this->program($request);
        $levels = $this->requireLevels($program);
        $cycle = $this->resolveCycle($program, $request);

        return Excel::download(
            new HierarchyNodesExport($program, $levels, $cycle?->id),
            strtolower($program->code).'-hierarchy-export.xlsx',
        );
    }

    /** POST /programs/{program}/hierarchy-import/preview */
    public function preview(Request $request): JsonResponse
    {
        $program = $this->program($request);
        $this->authorizeManage($request->user(), $program);

        $request->validate([
            'file' => ['required', 'file', 'mimes:xlsx', 'max:10240'],
            'cycle_id' => ['required', 'integer'],
        ]);

        $cycle = $this->requireCycle($program, (int) $request->input('cycle_id'));

        ['import_log' => $log, 'result' => $result] = $this->imports->storeAndValidate(
            $request->file('file'), $program, $cycle, $request->user(),
        );

        return response()->json([
            'success' => true,
            'data' => [
                'import_log_id' => $log->id,
                'can_import' => $result['metadata_valid'] && empty($result['errors']) && $result['summary']['valid_rows'] > 0,
                'summary' => $result['summary'],
                // Per-level "new vs reused" counts — what makes the preview
                // reviewable before anything is written.
                'by_level' => $result['summary']['by_level'] ?? [],
                'structure_version' => $result['structure_version'],
                'template_version' => $result['template_version'],
                // Bounded so a 5,000-row failure cannot produce an unbounded
                // response; the full list is available as an error report.
                'errors' => array_slice($result['errors'], 0, 200),
                'error_count' => count($result['errors']),
            ],
        ]);
    }

    /** POST /programs/{program}/hierarchy-import/{importLog}/confirm */
    public function confirm(Request $request, string $program, int $importLog): JsonResponse
    {
        $resolved = $this->program($request);
        $this->authorizeManage($request->user(), $resolved);

        $log = ImportLog::where('id', $importLog)
            ->where('compliance_program_id', $resolved->id)
            ->first();
        abort_unless($log, 404, 'Import not found.');

        $cycle = $this->requireCycle($resolved, (int) $log->program_cycle_id);

        try {
            $counts = $this->imports->confirm($log, $resolved, $cycle, $request->user());
        } catch (WorkflowConflictException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 409);
        }

        return response()->json(['success' => true, 'data' => $counts]);
    }

    /**
     * GET /programs/{program}/hierarchy-import/{importLog}/error-report
     *
     * Re-validates the stored file and returns the errors as a workbook, so
     * a Program Manager can work through them offline rather than reading a
     * long JSON list in the browser.
     */
    public function errorReport(Request $request, string $program, int $importLog): BinaryFileResponse
    {
        $resolved = $this->program($request);
        $this->authorizeManage($request->user(), $resolved);

        $log = ImportLog::where('id', $importLog)
            ->where('compliance_program_id', $resolved->id)->first();
        abort_unless($log, 404, 'Import not found.');

        $path = Storage::disk('private')
            ->path("imports/{$resolved->id}/{$log->stored_file_name}");
        abort_unless(is_file($path), 404, 'The uploaded file is no longer available.');

        $result = app(HierarchyImportValidator::class)->validate($path, $resolved);

        return Excel::download(
            new HierarchyImportErrorReport($result['errors']),
            strtolower($resolved->code)."-import-errors-{$log->id}.xlsx",
        );
    }

    // ─── Helpers ─────────────────────────────────────────────────────────────

    /** @return array<int, HierarchyLevelDefinition> */
    private function requireLevels(ComplianceProgram $program): array
    {
        $levels = array_values(collect($this->structures->levels($program))->all());
        abort_if($levels === [], 422, 'This program has no active hierarchy structure.');

        return $levels;
    }

    private function resolveCycle(ComplianceProgram $program, Request $request): ?AssessmentCycle
    {
        if ($request->filled('cycle_id')) {
            return AssessmentCycle::where('id', $request->query('cycle_id'))
                ->where('compliance_program_id', $program->id)->first();
        }

        return AssessmentCycle::where('compliance_program_id', $program->id)
            ->where('is_current', true)->first();
    }

    private function requireCycle(ComplianceProgram $program, int $cycleId): AssessmentCycle
    {
        $cycle = AssessmentCycle::where('id', $cycleId)
            ->where('compliance_program_id', $program->id)->first();
        abort_unless($cycle, 404, 'Cycle not found.');

        return $cycle;
    }

    private function program(Request $request): ComplianceProgram
    {
        return $request->attributes->get('compliance_program');
    }

    private function authorizeManage(User $user, ComplianceProgram $program): void
    {
        abort_unless(
            app(HierarchyStructurePolicy::class)->manage($user, $program),
            403,
            'Only the Program Manager of this program may import hierarchy content.',
        );
    }
}
