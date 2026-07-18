<?php

namespace Tests\Feature\Engine;

use App\Exceptions\InvalidHierarchyException;
use App\Models\AssessmentCycle;
use App\Models\ComplianceContentVersion;
use App\Models\ComplianceNode;
use App\Models\ComplianceProgram;
use App\Services\ComplianceNodeService;
use App\Services\ExtensionService;
use App\Services\ProgramConfigurationService;
use App\Services\WorkflowService;
use Database\Seeders\ECCProgramConfigurationSeeder;
use Database\Seeders\ECCWorkflowDefinitionSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Gate;
use Tests\Feature\Workflow\WorkflowTestCase;

/**
 * Proves ECC runs on the same generic Compliance Engine Qiyas and Sumoud
 * use, PLUS proves the new arbitrary-depth ComplianceNode hierarchy engine
 * built for Phase 6 — no ECC-specific controller/service/workflow class
 * exists. The four-level hierarchy (domain -> subdomain -> control ->
 * subcontrol) is real, validated, and bridges into the exact same
 * WorkflowService/ExtensionService/SlaService the Qiyas/Sumoud tests
 * exercise, via ComplianceNode::standard_id.
 */
class ECCProgramEngineTest extends WorkflowTestCase
{
    private ComplianceProgram $ecc;

    private AssessmentCycle $eccCycle;

    private ComplianceContentVersion $contentVersion;

    private ComplianceNode $domain;

    private ComplianceNode $subdomain;

    protected function setUp(): void
    {
        parent::setUp();

        // The ECC row itself is seeded by migration
        // 2026_07_21_000004_seed_ecc_compliance_program.php.
        $this->ecc = ComplianceProgram::where('code', 'ECC')->firstOrFail();
        $this->seed(ECCWorkflowDefinitionSeeder::class);
        $this->seed(ECCProgramConfigurationSeeder::class);

        $this->contentVersion = ComplianceContentVersion::create([
            'compliance_program_id' => $this->ecc->id,
            'version_label' => 'TEST-V1',
            'status' => 'active',
            'source_name' => 'PHPUnit fixture',
        ]);

        $this->eccCycle = AssessmentCycle::create([
            'compliance_program_id' => $this->ecc->id,
            'content_version_id' => $this->contentVersion->id,
            'name' => 'ECC Cycle 2026', 'year' => 2026,
            'start_date' => '2026-01-01', 'end_date' => '2026-12-31',
            'status' => 'active', 'is_current' => true, 'created_by' => $this->superAdmin->id,
        ]);

        $nodes = app(ComplianceNodeService::class);
        $this->domain = $nodes->createNode($this->ecc, 'domain', 'D1', 'مجال تجريبي', null, $this->eccCycle, $this->contentVersion, $this->superAdmin);
        $this->subdomain = $nodes->createNode($this->ecc, 'subdomain', 'D1-S1', 'مجال فرعي تجريبي', $this->domain, $this->eccCycle, $this->contentVersion, $this->superAdmin);
    }

    // ── Program ──────────────────────────────────────────────────────────

    public function test_ecc_is_created_and_configuration_is_independent(): void
    {
        $this->assertDatabaseHas('compliance_programs', ['code' => 'ECC', 'is_active' => true]);

        $eccTerms = app(ProgramConfigurationService::class)->get($this->ecc, 'terminology');
        $this->assertSame('Main Domain', $eccTerms['domain']['en']);

        $qiyasTerms = app(ProgramConfigurationService::class)->get($this->qiyas, 'terminology');
        $this->assertSame('Perspective', $qiyasTerms['domain']['en'], 'Qiyas terminology must remain unchanged after adding ECC.');
    }

    public function test_ecc_scoring_is_disabled_and_not_applicable_process_is_deferred(): void
    {
        $features = app(ProgramConfigurationService::class)->get($this->ecc, 'features');
        $this->assertFalse($features['scoring_enabled']);
        $this->assertFalse($features['not_applicable_enabled']);
    }

    // ── Hierarchy engine ─────────────────────────────────────────────────

    public function test_ecc_hierarchy_supports_four_configured_levels(): void
    {
        $nodes = app(ComplianceNodeService::class);
        $control = $nodes->createAssessableNode($this->ecc, 'control', 'D1-S1-C1', 'ضابط تجريبي', $this->subdomain, $this->eccCycle, $this->contentVersion, $this->superAdmin);
        $subcontrol = $nodes->createAssessableNode($this->ecc, 'subcontrol', 'D1-S1-C1-SC1', 'ضابط فرعي تجريبي', $control, $this->eccCycle, $this->contentVersion, $this->superAdmin);

        $this->assertSame(0, $this->domain->level);
        $this->assertSame(1, $this->subdomain->level);
        $this->assertSame(2, $control->level);
        $this->assertSame(3, $subcontrol->level);
        $this->assertNotNull($control->standard_id, 'An assessable node must bridge into standards.');
        $this->assertNotNull($subcontrol->standard_id);
    }

    public function test_invalid_parent_child_type_pairs_are_rejected(): void
    {
        $nodes = app(ComplianceNodeService::class);

        $this->expectException(InvalidHierarchyException::class);
        // A 'control' cannot be a root node (its configured parent_type is 'subdomain').
        $nodes->createNode($this->ecc, 'control', 'BAD-1', 'خطأ', null, $this->eccCycle, $this->contentVersion, $this->superAdmin);
    }

    public function test_subdomain_cannot_be_created_directly_under_another_subdomain(): void
    {
        $nodes = app(ComplianceNodeService::class);

        $this->expectException(InvalidHierarchyException::class);
        // subdomain's configured parent_type is 'domain', not 'subdomain'.
        $nodes->createNode($this->ecc, 'subdomain', 'BAD-2', 'خطأ', $this->subdomain, $this->eccCycle, $this->contentVersion, $this->superAdmin);
    }

    public function test_ecc_node_cannot_use_a_qiyas_parent_or_qiyas_cycle(): void
    {
        $nodes = app(ComplianceNodeService::class);
        $qiyasNode = ComplianceNode::create([
            'compliance_program_id' => $this->qiyas->id, 'parent_id' => null,
            'node_type' => 'domain', 'level' => 0, 'code' => 'Q-D1', 'name_ar' => 'مجال قياس',
            'created_by' => $this->superAdmin->id,
        ]);

        $this->expectException(InvalidHierarchyException::class);
        $nodes->createNode($this->ecc, 'subdomain', 'CROSS-1', 'خطأ عبر برامج', $qiyasNode, $this->eccCycle, $this->contentVersion, $this->superAdmin);
    }

    public function test_ecc_node_cannot_use_a_sumoud_cycle(): void
    {
        $sumoud = ComplianceProgram::where('code', 'SUMOUD')->first();
        $this->assertNotNull($sumoud, 'Sumoud must exist from the Phase 6 migration for this cross-program check to be meaningful.');

        $sumoudCycle = AssessmentCycle::create([
            'compliance_program_id' => $sumoud->id, 'name' => 'Sumoud Cycle', 'year' => 2026,
            'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'status' => 'active',
            'is_current' => true, 'created_by' => $this->superAdmin->id,
        ]);

        $nodes = app(ComplianceNodeService::class);
        $this->expectException(InvalidHierarchyException::class);
        $nodes->createNode($this->ecc, 'subdomain', 'CROSS-2', 'خطأ عبر برامج', $this->domain, $sumoudCycle, $this->contentVersion, $this->superAdmin);
    }

    public function test_maximum_configured_depth_is_enforced(): void
    {
        app(ProgramConfigurationService::class)->set($this->ecc, 'hierarchy', [
            'levels' => [
                ['node_type' => 'domain', 'label_ar' => 'م', 'label_en' => 'D', 'parent_type' => null, 'is_assessable' => false],
                ['node_type' => 'subdomain', 'label_ar' => 'ف', 'label_en' => 'S', 'parent_type' => 'domain', 'is_assessable' => true],
            ],
            'max_depth' => 1,
        ], $this->superAdmin);

        $nodes = app(ComplianceNodeService::class);
        $this->expectException(InvalidHierarchyException::class);
        $nodes->createNode($this->ecc, 'subdomain', 'DEPTH-1', 'تجاوز العمق', $this->domain, $this->eccCycle, $this->contentVersion, $this->superAdmin);
    }

    public function test_duplicate_codes_are_rejected_within_the_same_content_version(): void
    {
        $nodes = app(ComplianceNodeService::class);
        $nodes->createNode($this->ecc, 'subdomain', 'DUP-1', 'الأول', $this->domain, $this->eccCycle, $this->contentVersion, $this->superAdmin);

        $this->expectException(QueryException::class);
        $nodes->createNode($this->ecc, 'subdomain', 'DUP-1', 'مكرر', $this->domain, $this->eccCycle, $this->contentVersion, $this->superAdmin);
    }

    // ── Content versions ─────────────────────────────────────────────────

    public function test_updating_content_version_does_not_alter_a_historical_cycle(): void
    {
        $v2 = ComplianceContentVersion::create([
            'compliance_program_id' => $this->ecc->id, 'version_label' => 'TEST-V2', 'status' => 'draft',
            'previous_version_id' => $this->contentVersion->id,
        ]);

        $this->eccCycle->refresh();
        $this->assertSame($this->contentVersion->id, $this->eccCycle->content_version_id, 'A cycle already created against V1 must remain tied to V1 after V2 exists.');
        $this->assertNotSame($v2->id, $this->eccCycle->content_version_id);
    }

    // ── Full workflow lifecycle (via the bridged Standard) ──────────────

    public function test_ecc_full_lifecycle_completes_via_the_generic_workflow_service(): void
    {
        $eccPm = $this->makeUser('employee');
        $this->grantProgramRole($eccPm, $this->ecc, 'program-manager');
        $eccAuditor = $this->makeUser('employee');
        $this->grantProgramRole($eccAuditor, $this->ecc, 'auditor');
        $deptA = $this->makeDepartment('ECC Dept A');
        $deptManager = $this->makeUser('employee', $deptA->id);
        $this->grantProgramRole($deptManager, $this->ecc, 'department-manager', $deptA->id);
        $employee = $this->makeUser('employee', $deptA->id);
        $this->grantProgramRole($employee, $this->ecc, 'employee', $deptA->id);

        $nodes = app(ComplianceNodeService::class);
        $control = $nodes->createAssessableNode($this->ecc, 'control', 'D1-S1-C1', 'ضابط تجريبي', $this->subdomain, $this->eccCycle, $this->contentVersion, $this->superAdmin);

        $workflow = app(WorkflowService::class);
        $assignment = $workflow->assign($control->standard, $this->ecc, $eccPm, $deptA, $employee, '2026-12-01', null, null, null);
        $submission = $workflow->getOrCreateDraft($assignment, $employee);
        $workflow->addFile($submission, UploadedFile::fake()->create('e.pdf', 10, 'application/pdf'), $employee);
        $submission = $workflow->submit($submission, $employee, null);
        $this->assertSame('pending_department_manager', $submission->status);

        $submission = $workflow->approve($submission, $deptManager, 'department_manager', 'department-manager', null);
        $this->assertSame('pending_auditor', $submission->status);

        $submission = $workflow->approve($submission, $eccAuditor, 'auditor', 'auditor', null);
        $this->assertSame('pending_program_manager', $submission->status);

        $submission = $workflow->approve($submission, $eccPm, 'program_manager', 'program-manager', null);
        $this->assertSame('approved', $submission->status);
    }

    public function test_ecc_rejection_returns_directly_to_employee_and_resubmission_restarts_at_department_manager(): void
    {
        $eccPm = $this->makeUser('employee');
        $this->grantProgramRole($eccPm, $this->ecc, 'program-manager');
        $deptA = $this->makeDepartment('ECC Rejection Dept');
        $deptManager = $this->makeUser('employee', $deptA->id);
        $this->grantProgramRole($deptManager, $this->ecc, 'department-manager', $deptA->id);
        $employee = $this->makeUser('employee', $deptA->id);
        $this->grantProgramRole($employee, $this->ecc, 'employee', $deptA->id);

        $nodes = app(ComplianceNodeService::class);
        $control = $nodes->createAssessableNode($this->ecc, 'control', 'D1-S1-C2', 'ضابط تجريبي 2', $this->subdomain, $this->eccCycle, $this->contentVersion, $this->superAdmin);

        $workflow = app(WorkflowService::class);
        $assignment = $workflow->assign($control->standard, $this->ecc, $eccPm, $deptA, $employee, '2026-12-01', null, null, null);
        $submission = $workflow->getOrCreateDraft($assignment, $employee);
        $workflow->addFile($submission, UploadedFile::fake()->create('e.pdf', 10, 'application/pdf'), $employee);
        $submission = $workflow->submit($submission, $employee, null);

        $rejected = $workflow->reject($submission, $deptManager, 'department_manager', 'department-manager', 'يحتاج تصحيح.', null);
        $this->assertSame('returned_for_revision', $rejected->status);

        $draft = $workflow->getOrCreateDraft($submission->assignment, $employee);
        $workflow->addFile($draft, UploadedFile::fake()->create('e2.pdf', 10, 'application/pdf'), $employee);
        $resubmitted = $workflow->submit($draft, $employee, null);
        $this->assertSame('pending_department_manager', $resubmitted->status);
    }

    public function test_ecc_extension_reviewer_is_program_scoped(): void
    {
        $eccPm = $this->makeUser('employee');
        $this->grantProgramRole($eccPm, $this->ecc, 'program-manager');
        $eccAuditor = $this->makeUser('employee');
        $this->grantProgramRole($eccAuditor, $this->ecc, 'auditor');
        $deptA = $this->makeDepartment('ECC Extension Dept');
        $employee = $this->makeUser('employee', $deptA->id);
        $this->grantProgramRole($employee, $this->ecc, 'employee', $deptA->id);

        $nodes = app(ComplianceNodeService::class);
        $control = $nodes->createAssessableNode($this->ecc, 'control', 'D1-S1-C3', 'ضابط تجريبي 3', $this->subdomain, $this->eccCycle, $this->contentVersion, $this->superAdmin);

        $workflow = app(WorkflowService::class);
        $assignment = $workflow->assign($control->standard, $this->ecc, $eccPm, $deptA, $employee, now()->addDays(5)->toDateString(), null, null, null);
        $extension = app(ExtensionService::class)->request($assignment, $employee, now()->addDays(20)->toDateString(), 'سبب.');

        $this->assertTrue(Gate::forUser($eccAuditor)->allows('decide', $extension));
        $this->assertFalse(Gate::forUser($this->auditor)->allows('decide', $extension), 'A Qiyas-only Auditor must not decide an ECC extension.');
    }

    // ── Cross-program membership ─────────────────────────────────────────

    public function test_ecc_only_user_cannot_access_qiyas_or_sumoud(): void
    {
        $eccOnly = $this->makeUser('employee');
        $this->grantProgramRole($eccOnly, $this->ecc, 'employee');

        $this->assertTrue($eccOnly->hasProgramAccess($this->ecc));
        $this->assertFalse($eccOnly->hasProgramAccess($this->qiyas));

        $sumoud = ComplianceProgram::where('code', 'SUMOUD')->first();
        $this->assertFalse($eccOnly->hasProgramAccess($sumoud));
    }

    public function test_tri_program_user_resolves_different_roles_per_program(): void
    {
        $sumoud = ComplianceProgram::where('code', 'SUMOUD')->firstOrFail();
        $user = $this->makeUser('employee');
        $this->grantProgramRole($user, $this->qiyas, 'program-manager');
        $this->grantProgramRole($user, $sumoud, 'auditor');
        $this->grantProgramRole($user, $this->ecc, 'employee');

        $this->assertTrue($user->hasProgramRole($this->qiyas, 'program-manager'));
        $this->assertTrue($user->hasProgramRole($sumoud, 'auditor'));
        $this->assertTrue($user->hasProgramRole($this->ecc, 'employee'));
        $this->assertFalse($user->hasProgramRole($this->ecc, 'program-manager'));
    }
}
