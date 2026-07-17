<?php

namespace Tests\Feature\Engine;

use App\Exceptions\InvalidProgramConfigurationException;
use App\Exports\Qiyas\QiyasRequirementsTemplateExport;
use App\Models\ProgramConfigurationVersion;
use App\Models\WorkflowDefinition;
use App\Services\EvidenceUploadValidator;
use App\Services\ExtensionService;
use App\Services\ProgramConfigurationService;
use App\Services\WorkflowDefinitionRepository;
use App\Services\WorkflowService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Gate;
use Maatwebsite\Excel\Facades\Excel;
use Tests\Feature\Workflow\WorkflowTestCase;

/**
 * Proves the Phase 4 engine changes actually change runtime behavior when
 * configuration changes — not just that the seeded defaults reproduce the
 * old hardcoded behavior (already covered by the full pre-existing suite
 * passing unchanged).
 */
class ProgramConfigurationEngineTest extends WorkflowTestCase
{
    public function test_workflow_transitions_are_read_from_the_database_not_a_php_constant(): void
    {
        $repo = app(WorkflowDefinitionRepository::class);

        $this->assertSame('auditor', $repo->nextStage($this->qiyas, 'department_manager', 'approve'));
        $this->assertSame('employee', $repo->nextStage($this->qiyas, 'auditor', 'reject'));
        $this->assertSame('approved', $repo->nextStage($this->qiyas, 'program_manager', 'approve'));
        $this->assertTrue($repo->stage($this->qiyas, 'approved')['is_final']);

        // A program with no seeded workflow definition has no transitions —
        // proves this is genuinely read from data, not a fallback constant.
        $this->assertNull($repo->nextStage($this->otherProgram, 'department_manager', 'approve'));
    }

    public function test_changing_the_workflow_definition_changes_where_an_approval_moves_the_submission(): void
    {
        $workflow = app(WorkflowService::class);
        $assignment = $workflow->assign($this->requirement, $this->qiyas, $this->programManager, $this->deptA, null, '2026-12-01', null, null, null);
        $submission = $workflow->getOrCreateDraft($assignment, $this->deptAEmployee);
        $workflow->addFile($submission, UploadedFile::fake()->create('e.pdf', 10, 'application/pdf'), $this->deptAEmployee);
        $submission = $workflow->submit($submission, $this->deptAEmployee, null);

        // Reconfigure Qiyas so Department Manager approval skips the
        // Auditor and goes straight to the Program Manager — a
        // configuration-only change, no code edit.
        $definition = WorkflowDefinition::where('compliance_program_id', $this->qiyas->id)->firstOrFail();
        $definition->transitions()->where('from_stage_key', 'department_manager')->where('action', 'approve')
            ->update(['to_stage_key' => 'program_manager']);
        app(WorkflowDefinitionRepository::class)->forgetCache($this->qiyas);

        $updated = $workflow->approve($submission, $this->deptAManager, 'department_manager', 'department-manager', null);

        $this->assertSame('pending_program_manager', $updated->status);
    }

    public function test_program_configuration_service_rejects_an_unknown_category(): void
    {
        $this->expectException(InvalidProgramConfigurationException::class);

        app(ProgramConfigurationService::class)->set($this->qiyas, 'not_a_real_category', ['x' => 1], $this->superAdmin);
    }

    public function test_program_configuration_service_rejects_an_invalid_extensions_value(): void
    {
        $this->expectException(InvalidProgramConfigurationException::class);

        // 'reviewer_role' must be one of the known role keys — this is not
        // free-text arbitrary configuration.
        app(ProgramConfigurationService::class)->set($this->qiyas, 'extensions', ['reviewer_role' => 'not_a_role'], $this->superAdmin);
    }

    public function test_every_configuration_write_is_versioned_and_audited(): void
    {
        $service = app(ProgramConfigurationService::class);
        $service->set($this->qiyas, 'extensions', [
            'requester_role' => 'employee', 'reviewer_role' => 'auditor',
            'rejection_reason_required' => true, 'allow_multiple_pending' => false,
        ], $this->superAdmin);
        $service->set($this->qiyas, 'extensions', [
            'requester_role' => 'employee', 'reviewer_role' => 'program-manager',
            'rejection_reason_required' => true, 'allow_multiple_pending' => false,
        ], $this->superAdmin);

        $versions = ProgramConfigurationVersion::where('compliance_program_id', $this->qiyas->id)
            ->where('category', 'extensions')->orderBy('version')->get();

        $this->assertGreaterThanOrEqual(3, $versions->count()); // seeded (v1) + the two writes above
        $this->assertDatabaseHas('audit_logs', ['action' => 'program_configuration.updated']);
    }

    public function test_extension_reviewer_role_is_configurable_and_the_extension_engine_honors_it(): void
    {
        $workflow = app(WorkflowService::class);
        $assignment = $workflow->assign($this->requirement, $this->qiyas, $this->programManager, $this->deptA, null, now()->addDays(5)->toDateString(), null, null, null);
        $extension = app(ExtensionService::class)->request($assignment, $this->deptAEmployee, now()->addDays(20)->toDateString(), 'Reason.');

        // Default configuration: the Auditor may decide, the Department Manager may not.
        $this->assertTrue(Gate::forUser($this->auditor)->allows('decide', $extension));
        $this->assertFalse(Gate::forUser($this->deptAManager)->allows('decide', $extension));

        // Reconfigure Qiyas so the Department Manager decides instead —
        // configuration-only change.
        app(ProgramConfigurationService::class)->set($this->qiyas, 'extensions', [
            'requester_role' => 'employee', 'reviewer_role' => 'department-manager',
            'rejection_reason_required' => true, 'allow_multiple_pending' => false,
        ], $this->superAdmin);

        $this->assertFalse(Gate::forUser($this->auditor)->allows('decide', $extension));
        $this->assertTrue(Gate::forUser($this->deptAManager)->allows('decide', $extension));
    }

    public function test_evidence_upload_limits_are_program_scoped_not_platform_wide(): void
    {
        app(ProgramConfigurationService::class)->set($this->qiyas, 'evidence', [
            'allowed_extensions' => ['pdf'],
            'max_file_size_mb' => 1,
            'max_files_per_submission' => 10,
            'max_total_submission_size_mb' => 100,
        ], $this->superAdmin);

        $validator = app(EvidenceUploadValidator::class);

        // .docx is rejected under Qiyas's new pdf-only policy...
        $error = $validator->validateFile(UploadedFile::fake()->create('report.docx', 10, 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'), $this->qiyas);
        $this->assertNotNull($error);

        // ...but a program with no evidence configuration of its own still
        // falls back to the platform-wide default, unaffected by Qiyas's change.
        $this->assertContains('docx', $validator->allowedExtensions($this->otherProgram));
    }

    public function test_xlsx_template_columns_come_from_program_configuration_not_a_php_constant(): void
    {
        $config = app(ProgramConfigurationService::class)->get($this->qiyas, 'import');
        $config['columns'][] = ['key' => 'engine_test_column', 'label_ar' => 'عمود تجريبي', 'label_en' => 'Engine Test Column', 'required' => false];
        app(ProgramConfigurationService::class)->set($this->qiyas, 'import', $config, $this->superAdmin);

        $export = new QiyasRequirementsTemplateExport($this->qiyas->code, $this->qiyas->id, $this->cycle->id);
        Excel::store($export, 'engine-test-template.xlsx', 'local');
        $path = storage_path('app/private/engine-test-template.xlsx');

        $sheets = Excel::toArray(null, $path);
        $requirementsSheet = collect($sheets)->first(fn ($rows) => in_array('standard_number', $rows[0] ?? [], true));

        $this->assertContains('engine_test_column', $requirementsSheet[0]);

        @unlink($path);
    }
}
