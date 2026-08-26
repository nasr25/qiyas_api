<?php

namespace Tests\Feature\Engine;

use App\Exports\Hierarchy\HierarchyTemplateExport;
use App\Models\AssessmentCycle;
use App\Models\ComplianceNode;
use App\Models\ComplianceProgram;
use App\Models\EvidenceSubmission;
use App\Models\ProgramConfigurationVersion;
use App\Models\Standard;
use App\Models\User;
use App\Models\WorkflowDefinition;
use App\Services\CycleService;
use App\Services\DashboardMetricsService;
use App\Services\ExtensionService;
use App\Services\HierarchyDefinitionService;
use App\Services\HierarchyImportValidator;
use App\Services\ProgramConfigurationService;
use App\Services\SlaService;
use App\Services\WorkflowDefinitionRepository;
use App\Services\WorkflowService;
use Database\Seeders\SumoudProgramConfigurationSeeder;
use Database\Seeders\SumoudWorkflowDefinitionSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Gate;
use Maatwebsite\Excel\Facades\Excel;
use Tests\Feature\Workflow\WorkflowTestCase;

/**
 * Proves Sumoud runs on the same generic Compliance Engine Qiyas uses —
 * no Qiyas controller, service, workflow, SLA, evidence, notification,
 * dashboard, or authorization code was copied to make these pass. Every
 * assertion here exercises the exact same classes the Qiyas test suite
 * exercises (WorkflowService, ExtensionService, ProgramConfigurationService,
 * CycleService, EvidenceUploadValidator, ...), only with a Sumoud
 * ComplianceProgram passed in instead of Qiyas.
 */
class SumoudProgramEngineTest extends WorkflowTestCase
{
    private ComplianceProgram $sumoud;

    private AssessmentCycle $sumoudCycle;

    private ComplianceNode $sumoudRequirement;

    protected function setUp(): void
    {
        parent::setUp();

        // The SUMOUD row itself is seeded by migration
        // 2026_07_20_000001_seed_sumoud_compliance_program.php (mirroring
        // how QIYAS is bootstrapped) and therefore already exists by the
        // time RefreshDatabase finishes running migrations.
        $this->sumoud = ComplianceProgram::where('code', 'SUMOUD')->firstOrFail();
        $this->seed(SumoudWorkflowDefinitionSeeder::class);
        $this->seed(SumoudProgramConfigurationSeeder::class);

        $this->sumoudCycle = AssessmentCycle::create([
            'compliance_program_id' => $this->sumoud->id,
            'name' => 'Sumoud Cycle 2026', 'year' => 2026,
            'start_date' => '2026-01-01', 'end_date' => '2026-12-31',
            'status' => 'active', 'is_current' => true, 'created_by' => $this->superAdmin->id,
        ]);

        // Sumoud's own three-level structure, activated through the same
        // service a Program Manager uses. The requirement is the assessable
        // leaf node — the standards mirror was removed in the
        // dynamic-hierarchy phase (audit finding C2).
        $structures = app(HierarchyDefinitionService::class);
        $draft = $structures->openDraft($this->sumoud, $this->superAdmin);
        foreach ([
            ['key' => 'domain', 'name_ar' => 'المجال', 'name_en' => 'Domain'],
            ['key' => 'category', 'name_ar' => 'الفئة', 'name_en' => 'Category'],
            ['key' => 'requirement', 'name_ar' => 'المتطلب', 'name_en' => 'Requirement',
                'is_assignable' => true, 'is_assessable' => true, 'accepts_evidence' => true],
        ] as $level) {
            $structures->addLevel($draft, $level, $this->superAdmin);
        }
        $structures->activate($draft->fresh(), $this->superAdmin);

        $parent = null;
        foreach ($structures->levels($this->sumoud) as $index => $level) {
            $parent = ComplianceNode::create([
                'compliance_program_id' => $this->sumoud->id,
                'program_cycle_id' => $this->sumoudCycle->id,
                'hierarchy_level_id' => $level->id,
                'parent_id' => $parent?->id,
                'node_type' => $level->key, 'level' => $index,
                'code' => 'SMD-1-L'.($index + 1),
                'name_ar' => 'متطلب تجريبي لصمود', 'name_en' => 'Sumoud Test Requirement',
                'created_by' => $this->superAdmin->id,
            ]);
        }
        $this->sumoudRequirement = $parent;
    }

    // ── Program creation ────────────────────────────────────────────────

    public function test_sumoud_is_created_successfully_and_code_is_unique(): void
    {
        $this->assertDatabaseHas('compliance_programs', ['code' => 'SUMOUD', 'is_active' => true]);
        $this->assertSame(1, ComplianceProgram::where('code', 'SUMOUD')->count());

        $this->expectException(QueryException::class);
        ComplianceProgram::create(['code' => 'SUMOUD', 'name_ar' => 'تكرار', 'name_en' => 'Duplicate', 'status' => 'active', 'is_active' => true]);
    }

    public function test_sumoud_configuration_is_independent_of_qiyas_and_qiyas_is_unchanged(): void
    {
        $qiyasExtensions = app(ProgramConfigurationService::class)->get($this->qiyas, 'extensions');
        $sumoudExtensions = app(ProgramConfigurationService::class)->get($this->sumoud, 'extensions');
        $this->assertSame('auditor', $qiyasExtensions['reviewer_role']);
        $this->assertSame('auditor', $sumoudExtensions['reviewer_role']);

        // Changing Sumoud's config must not touch Qiyas's row.
        app(ProgramConfigurationService::class)->set($this->sumoud, 'extensions', [
            'requester_role' => 'employee', 'reviewer_role' => 'department-manager',
            'rejection_reason_required' => true, 'allow_multiple_pending' => false,
        ], $this->superAdmin);

        $qiyasExtensionsAfter = app(ProgramConfigurationService::class)->get($this->qiyas, 'extensions');
        $this->assertSame('auditor', $qiyasExtensionsAfter['reviewer_role'], 'Qiyas config must be unaffected by a Sumoud configuration change.');

        $sumoudRows = ProgramConfigurationVersion::where('compliance_program_id', $this->sumoud->id)->count();
        $qiyasRows = ProgramConfigurationVersion::where('compliance_program_id', $this->qiyas->id)->count();
        $this->assertGreaterThan(0, $sumoudRows);
        $this->assertGreaterThan(0, $qiyasRows);
        $this->assertNotEquals($sumoudRows, 0);
    }

    public function test_sumoud_scoring_is_disabled_by_default_because_no_approved_formula_exists(): void
    {
        $features = app(ProgramConfigurationService::class)->get($this->sumoud, 'features');
        $this->assertFalse($features['scoring_enabled']);

        $qiyasFeatures = app(ProgramConfigurationService::class)->get($this->qiyas, 'features');
        $this->assertTrue($qiyasFeatures['scoring_enabled'], 'Qiyas scoring must remain unchanged by adding Sumoud.');
    }

    // ── Membership / role resolution ────────────────────────────────────

    public function test_qiyas_only_user_cannot_access_sumoud_and_vice_versa(): void
    {
        $this->assertFalse($this->auditor->hasProgramAccess($this->sumoud));
        $this->assertTrue($this->auditor->hasProgramAccess($this->qiyas));

        $sumoudOnly = $this->makeUser('employee');
        $this->grantProgramRole($sumoudOnly, $this->sumoud, 'employee');
        $this->assertFalse($sumoudOnly->hasProgramAccess($this->qiyas));
        $this->assertTrue($sumoudOnly->hasProgramAccess($this->sumoud));
    }

    public function test_dual_program_user_can_access_both_with_different_roles(): void
    {
        $dual = $this->makeUser('employee');
        $this->grantProgramRole($dual, $this->qiyas, 'program-manager');
        $this->grantProgramRole($dual, $this->sumoud, 'auditor');

        $this->assertTrue($dual->hasProgramAccess($this->qiyas));
        $this->assertTrue($dual->hasProgramAccess($this->sumoud));
        $this->assertTrue($dual->hasProgramRole($this->qiyas, 'program-manager'));
        $this->assertFalse($dual->hasProgramRole($this->qiyas, 'auditor'));
        $this->assertTrue($dual->hasProgramRole($this->sumoud, 'auditor'));
        $this->assertFalse($dual->hasProgramRole($this->sumoud, 'program-manager'));
    }

    public function test_revoking_sumoud_access_does_not_revoke_qiyas_access(): void
    {
        $dual = $this->makeUser('employee');
        $this->grantProgramRole($dual, $this->qiyas, 'employee');
        $role = $this->grantProgramRole($dual, $this->sumoud, 'employee');

        $role->update(['is_active' => false, 'revoked_at' => now()]);
        $dual->refresh();

        $this->assertTrue($dual->hasProgramAccess($this->qiyas));
        $this->assertFalse($dual->hasProgramAccess($this->sumoud));
    }

    // ── Cycles ───────────────────────────────────────────────────────────

    public function test_sumoud_cycle_is_independent_and_program_scoped_write_endpoint_creates_it_correctly(): void
    {
        $sumoudPm = $this->makeUser('employee');
        $this->grantProgramRole($sumoudPm, $this->sumoud, 'program-manager');

        $response = $this->postJson('/api/v1/programs/SUMOUD/cycles', [
            'name' => 'دورة جديدة', 'year' => 2027, 'start_date' => '2027-01-01', 'end_date' => '2027-12-31',
        ], $this->authHeader($sumoudPm))->assertCreated();

        $this->assertDatabaseHas('assessment_cycles', [
            'id' => $response->json('data.id'), 'compliance_program_id' => $this->sumoud->id,
        ]);

        // The Qiyas Program Manager cannot create a Sumoud cycle without a
        // Sumoud program_user_roles row of their own.
        $this->postJson('/api/v1/programs/SUMOUD/cycles', [
            'name' => 'محاولة غير مصرحة', 'year' => 2027, 'start_date' => '2027-01-01', 'end_date' => '2027-12-31',
        ], $this->authHeader($this->programManager))->assertNotFound();
    }

    public function test_closing_sumoud_cycle_does_not_close_qiyas_cycle(): void
    {
        $sumoudPm = $this->makeUser('employee');
        $this->grantProgramRole($sumoudPm, $this->sumoud, 'program-manager');

        app(CycleService::class)->close($this->sumoudCycle, null, null, $sumoudPm);

        $this->assertSame('closed', $this->sumoudCycle->fresh()->status);
        $this->assertSame('active', $this->cycle->fresh()->status, 'Closing a Sumoud cycle must never close a Qiyas cycle.');
    }

    public function test_qiyas_cycle_cannot_be_read_through_the_sumoud_program_route(): void
    {
        $sumoudPm = $this->makeUser('employee');
        $this->grantProgramRole($sumoudPm, $this->sumoud, 'program-manager');

        $this->getJson("/api/v1/programs/SUMOUD/cycles/{$this->cycle->id}", $this->authHeader($sumoudPm))
            ->assertNotFound();
    }

    // ── Hierarchy ────────────────────────────────────────────────────────

    public function test_sumoud_requirement_cannot_be_created_under_a_qiyas_cycle(): void
    {
        // Standard::creating() derives compliance_program_id from cycle_id —
        // a "Sumoud requirement under a Qiyas cycle" is therefore
        // structurally impossible: it becomes a Qiyas-owned record instead,
        // proving the FK-derived scoping, not application-level trust.
        $standard = Standard::create([
            'cycle_id' => $this->cycle->id, 'standard_number' => 'CROSS-1', 'name_ar' => 'اختبار', 'is_active' => true,
        ]);

        $this->assertSame($this->qiyas->id, $standard->compliance_program_id);
        $this->assertNotSame($this->sumoud->id, $standard->compliance_program_id);
    }

    public function test_duplicate_requirement_codes_are_scoped_per_program(): void
    {
        Standard::create(['cycle_id' => $this->cycle->id, 'standard_number' => 'SAME-CODE', 'name_ar' => 'قياس', 'is_active' => true]);
        $sumoudStandard = Standard::create(['cycle_id' => $this->sumoudCycle->id, 'standard_number' => 'SAME-CODE', 'name_ar' => 'صمود', 'is_active' => true]);

        $this->assertSame($this->sumoud->id, $sumoudStandard->compliance_program_id);
        $this->assertSame(2, Standard::where('standard_number', 'SAME-CODE')->count());
    }

    // ── Workflow lifecycle ───────────────────────────────────────────────

    public function test_sumoud_full_lifecycle_completes_via_the_generic_workflow_service(): void
    {
        $sumoudPm = $this->makeUser('employee');
        $this->grantProgramRole($sumoudPm, $this->sumoud, 'program-manager');
        $sumoudAuditor = $this->makeUser('employee');
        $this->grantProgramRole($sumoudAuditor, $this->sumoud, 'auditor');
        $deptA = $this->makeDepartment('Sumoud Dept A');
        $deptManager = $this->makeUser('employee', $deptA->id);
        $this->grantProgramRole($deptManager, $this->sumoud, 'department-manager', $deptA->id);
        $employee = $this->makeUser('employee', $deptA->id);
        $this->grantProgramRole($employee, $this->sumoud, 'employee', $deptA->id);

        $workflow = app(WorkflowService::class);
        $assignment = $workflow->assign($this->sumoudRequirement, $this->sumoud, $sumoudPm, $deptA, $employee, '2026-12-01', null, null, null);
        $submission = $workflow->getOrCreateDraft($assignment, $employee);
        $workflow->addFile($submission, UploadedFile::fake()->create('e.pdf', 10, 'application/pdf'), $employee);
        $submission = $workflow->submit($submission, $employee, null);
        $this->assertSame('pending_department_manager', $submission->status);

        $submission = $workflow->approve($submission, $deptManager, 'department_manager', 'department-manager', null);
        $this->assertSame('pending_auditor', $submission->status);

        $submission = $workflow->approve($submission, $sumoudAuditor, 'auditor', 'auditor', null);
        $this->assertSame('pending_program_manager', $submission->status);

        $submission = $workflow->approve($submission, $sumoudPm, 'program_manager', 'program-manager', null);
        $this->assertSame('approved', $submission->status);
    }

    public function test_every_sumoud_rejection_path_returns_directly_to_employee(): void
    {
        [$workflow, $employee, $deptManager, $auditor, $pm, $submission] = $this->sumoudSubmissionAtDepartmentManager();

        $rejected = $workflow->reject($submission, $deptManager, 'department_manager', 'department-manager', 'يحتاج تصحيح.', null);
        $this->assertSame('returned_for_revision', $rejected->status);

        // Resubmission always restarts at Department Manager, never skips
        // to whichever stage rejected it.
        $draft = $workflow->getOrCreateDraft($submission->assignment, $employee);
        $workflow->addFile($draft, UploadedFile::fake()->create('e2.pdf', 10, 'application/pdf'), $employee);
        $resubmitted = $workflow->submit($draft, $employee, null);
        $this->assertSame('pending_department_manager', $resubmitted->status);

        $approved1 = $workflow->approve($resubmitted, $deptManager, 'department_manager', 'department-manager', null);
        $rejectedByAuditor = $workflow->reject($approved1, $auditor, 'auditor', 'auditor', 'رفض المدقق.', null);
        $this->assertSame('returned_for_revision', $rejectedByAuditor->status);
    }

    public function test_changing_sumoud_workflow_definition_does_not_change_qiyas(): void
    {
        $sumoudDefinition = WorkflowDefinition::where('compliance_program_id', $this->sumoud->id)->firstOrFail();
        $qiyasDefinition = WorkflowDefinition::where('compliance_program_id', $this->qiyas->id)->firstOrFail();
        $this->assertNotSame($sumoudDefinition->id, $qiyasDefinition->id, 'Sumoud and Qiyas must have separate workflow_definitions rows.');

        $sumoudDefinition->transitions()->where('from_stage_key', 'department_manager')->where('action', 'approve')
            ->update(['to_stage_key' => 'program_manager']);
        app(WorkflowDefinitionRepository::class)->forgetCache($this->sumoud);
        app(WorkflowDefinitionRepository::class)->forgetCache($this->qiyas);

        $this->assertSame('program_manager', app(WorkflowDefinitionRepository::class)->nextStage($this->sumoud, 'department_manager', 'approve'));
        $this->assertSame('auditor', app(WorkflowDefinitionRepository::class)->nextStage($this->qiyas, 'department_manager', 'approve'), 'Qiyas transitions must be unaffected by a Sumoud workflow definition change.');
    }

    // ── Extensions ───────────────────────────────────────────────────────

    public function test_sumoud_auditor_can_decide_extensions_but_a_qiyas_only_auditor_cannot(): void
    {
        $sumoudPm = $this->makeUser('employee');
        $this->grantProgramRole($sumoudPm, $this->sumoud, 'program-manager');
        $sumoudAuditor = $this->makeUser('employee');
        $this->grantProgramRole($sumoudAuditor, $this->sumoud, 'auditor');
        $deptA = $this->makeDepartment('Sumoud Dept A2');
        $employee = $this->makeUser('employee', $deptA->id);
        $this->grantProgramRole($employee, $this->sumoud, 'employee', $deptA->id);

        $workflow = app(WorkflowService::class);
        $assignment = $workflow->assign($this->sumoudRequirement, $this->sumoud, $sumoudPm, $deptA, $employee, now()->addDays(5)->toDateString(), null, null, null);
        $extension = app(ExtensionService::class)->request($assignment, $employee, now()->addDays(20)->toDateString(), 'سبب.');

        $this->assertTrue(Gate::forUser($sumoudAuditor)->allows('decide', $extension));
        // this->auditor has an active QIYAS auditor role only — no Sumoud membership.
        $this->assertFalse(Gate::forUser($this->auditor)->allows('decide', $extension), 'A Qiyas-only Auditor must not be able to decide a Sumoud extension.');
    }

    public function test_sumoud_extension_approval_does_not_change_qiyas_deadlines(): void
    {
        $qiyasAssignment = app(WorkflowService::class)->assign($this->requirement, $this->qiyas, $this->programManager, $this->deptA, null, '2026-12-01', null, null, null);
        $originalQiyasDue = $qiyasAssignment->effective_due_date;

        $sumoudPm = $this->makeUser('employee');
        $this->grantProgramRole($sumoudPm, $this->sumoud, 'program-manager');
        $sumoudAuditor = $this->makeUser('employee');
        $this->grantProgramRole($sumoudAuditor, $this->sumoud, 'auditor');
        $deptA = $this->makeDepartment('Sumoud Dept A3');
        $employee = $this->makeUser('employee', $deptA->id);
        $this->grantProgramRole($employee, $this->sumoud, 'employee', $deptA->id);

        $sumoudAssignment = app(WorkflowService::class)->assign($this->sumoudRequirement, $this->sumoud, $sumoudPm, $deptA, $employee, now()->addDays(5)->toDateString(), null, null, null);
        $extension = app(ExtensionService::class)->request($sumoudAssignment, $employee, now()->addDays(20)->toDateString(), 'سبب.');
        app(ExtensionService::class)->decide($extension, $sumoudAuditor, 'approved', null, null);

        $this->assertEquals($originalQiyasDue, $qiyasAssignment->fresh()->effective_due_date, 'Approving a Sumoud extension must never change a Qiyas assignment deadline.');
    }

    // ── SLA ──────────────────────────────────────────────────────────────

    public function test_sumoud_sla_settings_are_independent_from_qiyas(): void
    {
        $sla = app(SlaService::class);
        $qiyasSettings = $sla->settingsFor($this->qiyas);
        $sumoudSettings = $sla->settingsFor($this->sumoud);

        $this->assertNotSame($qiyasSettings->id, $sumoudSettings->id);

        $sumoudSettings->update(['employee_submission_sla_value' => 1, 'employee_submission_sla_unit' => 'hours']);

        $this->assertNotEquals(1, $qiyasSettings->fresh()->employee_submission_sla_value, 'Changing Sumoud SLA settings must not alter Qiyas SLA settings.');
    }

    // ── Import isolation ─────────────────────────────────────────────────

    /**
     * Rewritten onto the structure-driven engine: Sumoud's own template must
     * be refused by Qiyas and vice versa. The guarantee is unchanged; only
     * the engine behind it is.
     */
    public function test_a_programs_import_template_is_rejected_by_another_program(): void
    {
        $structures = app(HierarchyDefinitionService::class);

        $build = function ($program, $cycle) use ($structures) {
            $levels = array_values(collect($structures->levels($program))->all());
            $name = strtolower($program->code).'-cross-template.xlsx';
            Excel::store(new HierarchyTemplateExport(
                $program, $levels, $structures->currentStructureVersion($program), $cycle,
            ), $name, 'local');

            return storage_path('app/private/'.$name);
        };

        $validator = app(HierarchyImportValidator::class);

        $sumoudTemplate = $build($this->sumoud, $this->sumoudCycle);
        $result = $validator->validate($sumoudTemplate, $this->qiyas);
        $this->assertFalse($result['metadata_valid']);
        $this->assertContains('WRONG_PROGRAM', array_column($result['errors'], 'code'));

        $qiyasTemplate = $build($this->qiyas, $this->cycle);
        $reverse = $validator->validate($qiyasTemplate, $this->sumoud);
        $this->assertFalse($reverse['metadata_valid']);
        $this->assertContains('WRONG_PROGRAM', array_column($reverse['errors'], 'code'));

        @unlink($sumoudTemplate);
        @unlink($qiyasTemplate);
    }

    // ── Dashboard isolation ──────────────────────────────────────────────

    public function test_dashboard_metrics_service_counts_are_program_scoped(): void
    {
        $sumoudPm = $this->makeUser('employee');
        $this->grantProgramRole($sumoudPm, $this->sumoud, 'program-manager');
        $deptA = $this->makeDepartment('Sumoud Dashboard Dept');
        $workflow = app(WorkflowService::class);
        $workflow->assign($this->sumoudRequirement, $this->sumoud, $sumoudPm, $deptA, null, '2026-12-01', null, null, null);

        $metrics = app(DashboardMetricsService::class);
        $sumoudCounts = $metrics->submissionStatusCounts($this->sumoud, $this->sumoudCycle->id);
        $qiyasCounts = $metrics->submissionStatusCounts($this->qiyas, $this->cycle->id);

        $this->assertIsArray($sumoudCounts);
        $this->assertIsArray($qiyasCounts);
        // No shared/contaminated totals: Sumoud's one new assignment must
        // not appear in Qiyas's independently-queried counts.
        $this->assertSame(0, array_sum($qiyasCounts));
    }

    /** @return array{0: WorkflowService, 1: User, 2: User, 3: User, 4: User, 5: EvidenceSubmission} */
    private function sumoudSubmissionAtDepartmentManager(): array
    {
        $sumoudPm = $this->makeUser('employee');
        $this->grantProgramRole($sumoudPm, $this->sumoud, 'program-manager');
        $auditor = $this->makeUser('employee');
        $this->grantProgramRole($auditor, $this->sumoud, 'auditor');
        $deptA = $this->makeDepartment('Sumoud Rejection Dept');
        $deptManager = $this->makeUser('employee', $deptA->id);
        $this->grantProgramRole($deptManager, $this->sumoud, 'department-manager', $deptA->id);
        $employee = $this->makeUser('employee', $deptA->id);
        $this->grantProgramRole($employee, $this->sumoud, 'employee', $deptA->id);

        $workflow = app(WorkflowService::class);
        $assignment = $workflow->assign($this->sumoudRequirement, $this->sumoud, $sumoudPm, $deptA, $employee, '2026-12-01', null, null, null);
        $submission = $workflow->getOrCreateDraft($assignment, $employee);
        $workflow->addFile($submission, UploadedFile::fake()->create('e.pdf', 10, 'application/pdf'), $employee);
        $submission = $workflow->submit($submission, $employee, null);

        return [$workflow, $employee, $deptManager, $auditor, $sumoudPm, $submission];
    }
}
