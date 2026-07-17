<?php

namespace App\Services;

use App\Exceptions\WorkflowConflictException;
use App\Models\AssessmentCycle;
use App\Models\ComplianceProgram;
use App\Models\ImportLog;
use App\Models\Standard;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Orchestrates the upload -> validate/preview -> confirm pipeline. No data
 * is ever saved during validation — only `confirm()` writes, and it always
 * re-reads and re-validates the SAME stored file rather than trusting a
 * client-submitted row list, so a tampered request body cannot change what
 * gets imported. See docs/qiyas-xlsx-import.md.
 */
class QiyasImportService
{
    public function __construct(private readonly QiyasImportValidator $validator) {}

    public function storeAndValidate(UploadedFile $file, ComplianceProgram $program, AssessmentCycle $cycle, User $user): array
    {
        $storedName = Str::uuid()->toString().'.xlsx';
        $directory = "imports/{$program->id}";
        $path = $file->storeAs($directory, $storedName, 'private');
        $absolutePath = Storage::disk('private')->path($path);

        $log = ImportLog::create([
            'compliance_program_id' => $program->id,
            'program_cycle_id' => $cycle->id,
            'imported_by' => $user->id,
            'original_file_name' => $file->getClientOriginalName(),
            'stored_file_name' => $storedName,
            'file_hash' => hash_file('sha256', $absolutePath),
            'template_version' => 'unknown',
            'mode' => 'create',
            'status' => 'validating',
            'started_at' => now(),
        ]);

        $result = $this->validator->validate($absolutePath, $program, $cycle);

        $reportPath = null;
        if (! empty($result['errors'])) {
            $reportPath = "{$directory}/{$log->id}-errors.json";
            Storage::disk('private')->put($reportPath, json_encode($result['errors']));
        }

        $log->update([
            'template_version' => $result['template_version'],
            'total_rows' => $result['summary']['total_rows'],
            'valid_rows' => $result['summary']['valid_rows'],
            'invalid_rows' => $result['summary']['invalid_rows'],
            'warning_count' => $result['summary']['warning_count'],
            'error_count' => $result['summary']['error_count'],
            'validation_report_path' => $reportPath,
            'status' => $result['metadata_valid'] && empty($result['errors']) ? 'ready_for_confirmation' : 'validation_failed',
        ]);

        AuditService::log('import.validated', "Validated import '{$file->getClientOriginalName()}' ({$result['summary']['valid_rows']} valid rows, {$result['summary']['error_count']} errors)", $log, complianceProgramId: $program->id);

        return ['import_log' => $log, 'result' => $result];
    }

    public function confirm(ImportLog $log, ComplianceProgram $program, AssessmentCycle $cycle, User $user): array
    {
        if ($log->status !== 'ready_for_confirmation') {
            throw new WorkflowConflictException('This import is not ready for confirmation — re-upload the file.');
        }

        $path = "imports/{$program->id}/{$log->stored_file_name}";
        $absolutePath = Storage::disk('private')->path($path);
        if (! Storage::disk('private')->exists($path)) {
            throw new WorkflowConflictException('The uploaded file is no longer available — please upload it again.');
        }

        // Re-validate against the stored file, never a client-submitted row list.
        $result = $this->validator->validate($absolutePath, $program, $cycle);
        if (! $result['metadata_valid'] || ! empty($result['errors'])) {
            $log->update(['status' => 'failed']);
            throw new WorkflowConflictException('The file failed validation on confirm — it may have changed since preview.');
        }

        $log->update(['status' => 'importing']);

        $created = 0;
        $updated = 0;

        DB::transaction(function () use ($result, $cycle, $program, &$created, &$updated) {
            foreach ($result['valid_rows'] as $row) {
                $standard = Standard::updateOrCreate(
                    ['cycle_id' => $cycle->id, 'standard_number' => $row['standard_number']],
                    [
                        'compliance_program_id' => $program->id,
                        'perspective' => $row['perspective'],
                        'axis' => $row['axis'],
                        'name_ar' => $row['name_ar'],
                        'name_en' => $row['name_en'],
                        'description' => $row['description'],
                        'application_requirements' => $row['application_requirements'],
                        'evidence_documents' => $row['evidence_documents'],
                        'weight' => $row['weight'],
                        'due_date' => $row['due_date'],
                        'is_active' => true,
                    ],
                );
                $row['is_new'] ? $created++ : $updated++;
            }
        });

        $log->update([
            'status' => 'completed',
            'created_records' => $created,
            'updated_records' => $updated,
            'completed_at' => now(),
        ]);

        AuditService::log('import.completed', "Import '{$log->original_file_name}' completed: {$created} created, {$updated} updated", $log, complianceProgramId: $program->id);

        return ['created' => $created, 'updated' => $updated];
    }
}
