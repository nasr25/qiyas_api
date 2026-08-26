<?php

namespace App\Services;

use App\Exceptions\WorkflowConflictException;
use App\Models\AssessmentCycle;
use App\Models\ComplianceNode;
use App\Models\ComplianceProgram;
use App\Models\ImportLog;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Upload → validate/preview → confirm, for structure-driven hierarchy
 * imports.
 *
 * Two guarantees the brief calls for explicitly:
 *
 *   8. No partial imports. Validation must be completely clean before any
 *      write happens; a single bad row aborts the whole file.
 *   9. Transactional. The node tree is written inside one DB transaction,
 *      so a failure part-way leaves the database exactly as it was.
 *
 * `confirm()` re-reads and re-validates the STORED file rather than
 * trusting a client-submitted row list, so a tampered confirm request
 * cannot change what gets imported.
 */
class HierarchyImportService
{
    public function __construct(
        private readonly HierarchyImportValidator $validator,
        private readonly HierarchyDefinitionService $structures,
    ) {}

    public function storeAndValidate(UploadedFile $file, ComplianceProgram $program, AssessmentCycle $cycle, User $user): array
    {
        $storedName = Str::uuid()->toString().'.xlsx';
        $path = $file->storeAs("imports/{$program->id}", $storedName, 'private');
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

        $result = $this->validator->validate($absolutePath, $program);

        $log->update([
            'template_version' => $result['template_version'],
            // Reuses the existing import_logs status vocabulary rather than
            // introducing parallel names for the same states.
            'status' => empty($result['errors']) && $result['metadata_valid']
                ? 'ready_for_confirmation'
                : 'validation_failed',
            'total_rows' => $result['summary']['total_rows'],
            'error_rows' => $result['summary']['error_rows'],
        ]);

        AuditService::log(
            'hierarchy_import.validated',
            "Validated '{$log->original_file_name}': {$result['summary']['valid_rows']} valid, {$result['summary']['error_rows']} in error.",
            $log,
            complianceProgramId: $program->id,
        );

        return ['import_log' => $log->fresh(), 'result' => $result];
    }

    /**
     * Writes the validated tree. Refuses outright if anything is wrong —
     * there is no "import the good rows" path, by design.
     */
    public function confirm(ImportLog $log, ComplianceProgram $program, AssessmentCycle $cycle, User $user): array
    {
        $absolutePath = Storage::disk('private')->path("imports/{$program->id}/{$log->stored_file_name}");

        if (! is_file($absolutePath)) {
            throw new WorkflowConflictException('The uploaded file is no longer available — please upload it again.');
        }
        if (hash_file('sha256', $absolutePath) !== $log->file_hash) {
            throw new WorkflowConflictException('The stored file changed since validation and cannot be imported.');
        }

        $result = $this->validator->validate($absolutePath, $program);

        // Requirement 8: any error at all aborts the entire import.
        if (! $result['metadata_valid'] || ! empty($result['errors'])) {
            $log->update(['status' => 'validation_failed']);

            throw new WorkflowConflictException(
                'The file failed validation on confirm; nothing was imported. '
                .count($result['errors']).' error(s) must be corrected first.'
            );
        }
        if (empty($result['valid_rows'])) {
            $log->update(['status' => 'validation_failed']);

            throw new WorkflowConflictException('The file contains no importable rows.');
        }

        $log->update(['status' => 'importing']);
        $structureVersionId = $this->structures->currentStructureVersion($program)?->id;

        $created = 0;
        $updated = 0;

        // Requirement 9: one transaction for the whole file.
        DB::transaction(function () use ($result, $program, $cycle, $user, $structureVersionId, &$created, &$updated) {
            // Cache of code -> node for this run, so a repeated ancestor code
            // across rows resolves to the same node instead of duplicating it.
            $resolved = [];

            foreach ($result['valid_rows'] as $row) {
                $parent = null;

                foreach ($row['chain'] as $position => $entry) {
                    $pathKey = implode('|', array_slice(array_column($row['chain'], 'code'), 0, $position + 1));
                    $isLeaf = $position === count($row['chain']) - 1;

                    if (isset($resolved[$pathKey])) {
                        $parent = $resolved[$pathKey];

                        continue;
                    }

                    $existing = ComplianceNode::where('compliance_program_id', $program->id)
                        ->where('program_cycle_id', $cycle->id)
                        ->where('hierarchy_level_id', $entry['level_id'])
                        ->where('code', $entry['code'])
                        ->first();

                    $attributes = [
                        'compliance_program_id' => $program->id,
                        'program_cycle_id' => $cycle->id,
                        'structure_version_id' => $structureVersionId,
                        'hierarchy_level_id' => $entry['level_id'],
                        'parent_id' => $parent?->id,
                        'node_type' => $entry['level_key'],
                        'level' => $entry['depth'],
                        'code' => $entry['code'],
                        'name_ar' => $entry['name_ar'],
                        'name_en' => $entry['name_en'],
                        'status' => 'active',
                        'updated_by' => $user->id,
                    ];

                    // Row attributes describe the deepest level only.
                    if ($isLeaf) {
                        $attributes += [
                            'description_ar' => $row['attributes']['description_ar'],
                            'objective_ar' => $row['attributes']['objective_ar'],
                            'guidance_ar' => $row['attributes']['guidance_ar'],
                            'weight' => $row['attributes']['weight'],
                            'default_due_date' => $row['attributes']['default_due_date'],
                        ];
                    }

                    if ($existing) {
                        $existing->update($attributes);
                        $node = $existing;
                        $updated++;
                    } else {
                        $node = ComplianceNode::create($attributes + ['created_by' => $user->id]);
                        $created++;
                    }

                    $resolved[$pathKey] = $node;
                    $parent = $node;
                }
            }
        });

        $log->update([
            'status' => 'completed',
            'created_records' => $created,
            'updated_records' => $updated,
            'completed_at' => now(),
        ]);

        AuditService::log(
            'hierarchy_import.completed',
            "Import '{$log->original_file_name}' completed: {$created} created, {$updated} updated.",
            $log,
            null,
            ['created' => $created, 'updated' => $updated, 'structure_version' => $result['structure_version']],
            $program->id,
        );

        return ['created' => $created, 'updated' => $updated];
    }
}
