<?php

namespace App\Http\Controllers\Api\Workflow;

use App\Exceptions\WorkflowConflictException;
use App\Exports\Qiyas\ImportErrorReportExport;
use App\Exports\Qiyas\QiyasRequirementsTemplateExport;
use App\Http\Controllers\Controller;
use App\Models\AssessmentCycle;
use App\Models\ComplianceProgram;
use App\Models\ImportLog;
use App\Models\User;
use App\Services\AuditService;
use App\Services\QiyasImportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Maatwebsite\Excel\Facades\Excel;

/**
 * Program Manager only. See docs/qiyas-xlsx-import.md for the full
 * upload -> validate/preview -> confirm workflow.
 */
class QiyasImportController extends Controller
{
    public function __construct(private readonly QiyasImportService $importer) {}

    /** GET /api/v1/programs/{program}/requirements-template */
    public function downloadTemplate(Request $request)
    {
        $program = $this->program($request);
        $this->authorizeManage($request->user(), $program);

        $cycleId = $request->cycle_id ? (int) $request->cycle_id : null;

        AuditService::log('import.template_downloaded', "Downloaded requirements template for '{$program->code}'", $program, complianceProgramId: $program->id);

        return Excel::download(
            new QiyasRequirementsTemplateExport($program->code, $program->id, $cycleId),
            strtolower($program->code).'-requirements-template.xlsx',
        );
    }

    /** POST /api/v1/programs/{program}/requirements-import/preview */
    public function preview(Request $request): JsonResponse
    {
        $program = $this->program($request);
        $this->authorizeManage($request->user(), $program);

        $data = $request->validate([
            'file' => ['required', 'file', 'max:10240'],
            'cycle_id' => ['required', 'integer'],
        ]);

        $file = $request->file('file');
        if (strtolower($file->getClientOriginalExtension()) !== 'xlsx') {
            return response()->json(['success' => false, 'message' => 'Only .xlsx files are supported. Macro-enabled workbooks (.xlsm) are rejected.'], 422);
        }

        $cycle = AssessmentCycle::where('id', $data['cycle_id'])->where('compliance_program_id', $program->id)->first();
        if (! $cycle) {
            return response()->json(['success' => false, 'message' => 'Cycle not found.'], 404);
        }

        $outcome = $this->importer->storeAndValidate($file, $program, $cycle, $request->user());

        return response()->json([
            'success' => true,
            'data' => [
                'import_log_id' => $outcome['import_log']->id,
                'status' => $outcome['import_log']->status,
                'summary' => $outcome['result']['summary'],
                'errors' => array_slice($outcome['result']['errors'], 0, 200),
                'truncated' => count($outcome['result']['errors']) > 200,
            ],
        ]);
    }

    /** POST /api/v1/programs/{program}/requirements-import/{importLog}/confirm */
    public function confirm(Request $request, string $program, int|string $importLog): JsonResponse
    {
        $resolvedProgram = $this->program($request);
        $this->authorizeManage($request->user(), $resolvedProgram);

        $log = ImportLog::where('id', $importLog)->where('compliance_program_id', $resolvedProgram->id)->first();
        if (! $log) {
            return response()->json(['success' => false, 'message' => 'Import not found.'], 404);
        }

        $cycle = AssessmentCycle::findOrFail($log->program_cycle_id);

        try {
            $result = $this->importer->confirm($log, $resolvedProgram, $cycle, $request->user());
        } catch (WorkflowConflictException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 409);
        }

        return response()->json(['success' => true, 'data' => $result, 'message' => 'Import completed.']);
    }

    /** GET /api/v1/programs/{program}/requirements-import/{importLog}/error-report */
    public function errorReport(Request $request, string $program, int|string $importLog)
    {
        $resolvedProgram = $this->program($request);
        $this->authorizeManage($request->user(), $resolvedProgram);

        $log = ImportLog::where('id', $importLog)->where('compliance_program_id', $resolvedProgram->id)->first();
        if (! $log || ! $log->validation_report_path || ! Storage::disk('private')->exists($log->validation_report_path)) {
            return response()->json(['success' => false, 'message' => 'No error report available for this import.'], 404);
        }

        $errors = json_decode(Storage::disk('private')->get($log->validation_report_path), true) ?? [];

        return Excel::download(new ImportErrorReportExport($errors), 'import-errors.xlsx');
    }

    /** GET /api/v1/programs/{program}/requirements-imports */
    public function index(Request $request): JsonResponse
    {
        $program = $this->program($request);
        $this->authorizeManage($request->user(), $program);

        $logs = ImportLog::where('compliance_program_id', $program->id)
            ->with('importer')
            ->latest('started_at')
            ->paginate($request->get('per_page', 20));

        return response()->json([
            'success' => true,
            'data' => $logs->getCollection()->map(fn (ImportLog $l) => [
                'id' => $l->id, 'original_file_name' => $l->original_file_name, 'status' => $l->status,
                'total_rows' => $l->total_rows, 'valid_rows' => $l->valid_rows, 'invalid_rows' => $l->invalid_rows,
                'created_records' => $l->created_records, 'updated_records' => $l->updated_records,
                'imported_by' => $l->importer?->name, 'started_at' => $l->started_at->toIso8601String(),
                'completed_at' => $l->completed_at?->toIso8601String(),
            ])->values(),
            'meta' => ['current_page' => $logs->currentPage(), 'last_page' => $logs->lastPage(), 'total' => $logs->total()],
        ]);
    }

    private function program(Request $request): ComplianceProgram
    {
        return $request->attributes->get('compliance_program');
    }

    private function authorizeManage(User $user, ComplianceProgram $program): void
    {
        abort_unless($user->isPlatformSuperAdmin() || $user->hasProgramRole($program, 'program-manager'), 403, 'Only the Program Manager can manage requirements imports.');
    }
}
