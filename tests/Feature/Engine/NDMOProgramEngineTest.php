<?php

namespace Tests\Feature\Engine;

use App\Exceptions\InvalidHierarchyException;
use App\Exceptions\WorkflowConflictException;
use App\Models\AssessmentCycle;
use App\Models\ComplianceContentVersion;
use App\Models\ComplianceNode;
use App\Models\ComplianceProgram;
use App\Services\ComplianceNodeService;
use App\Services\ExtensionService;
use App\Services\ProgramConfigurationService;
use App\Services\ResponsibilityService;
use App\Services\WorkflowService;
use Database\Seeders\NDMOProgramConfigurationSeeder;
use Database\Seeders\NDMOWorkflowDefinitionSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Gate;
use Tests\Feature\Workflow\WorkflowTestCase;

/**
 * Proves NDMO runs on the same generic Compliance Engine Qiyas/Sumoud/ECC
 * use — no NDMO-specific controller/service/workflow class exists — AND
 * proves the ComplianceNode hierarchy engine (built for ECC's four
 * levels in Phase 6) transparently supports a DIFFERENT five-level shape
 * (domain -> policy -> standard -> requirement -> subrequirement) with
 * zero code changes, plus the new Phase 7 responsibility engine (Data
 * Owner/Data Steward) that never grants workflow authority.
 */
class NDMOProgramEngineTest extends WorkflowTestCase
{
    private ComplianceProgram $ndmo;

    private AssessmentCycle $ndmoCycle;

    private ComplianceContentVersion $contentVersion;

    private ComplianceNode $domain;

    private ComplianceNode $policy;

    private ComplianceNode $standard;

    protected function setUp(): void
    {
        parent::setUp();

        $this->ndmo = ComplianceProgram::where('code', 'NDMO')->firstOrFail();
        $this->seed(NDMOWorkflowDefinitionSeeder::class);
        $this->seed(NDMOProgramConfigurationSeeder::class);

        $this->contentVersion = ComplianceContentVersion::create([
            'compliance_program_id' => $this->ndmo->id,
            'version_label' => 'TEST-V1',
            'status' => 'active',
            'source_name' => 'PHPUnit fixture',
        ]);

        $this->ndmoCycle = AssessmentCycle::create([
            'compliance_program_id' => $this->ndmo->id,
            'content_version_id' => $this->contentVersion->id,
            'name' => 'NDMO Cycle 2026', 'year' => 2026,
            'start_date' => '2026-01-01', 'end_date' => '2026-12-31',
            'status' => 'active', 'is_current' => true, 'created_by' => $this->superAdmin->id,
        ]);

        $nodes = app(ComplianceNodeService::class);
        $this->domain = $nodes->createNode($this->ndmo, 'domain', 'D1', 'مجال تجريبي', null, $this->ndmoCycle, $this->contentVersion, $this->superAdmin);
        $this->policy = $nodes->createNode($this->ndmo, 'policy', 'D1-P1', 'سياسة تجريبية', $this->domain, $this->ndmoCycle, $this->contentVersion, $this->superAdmin);
        $this->standard = $nodes->createNode($this->ndmo, 'standard', 'D1-P1-S1', 'معيار تجريبي', $this->policy, $this->ndmoCycle, $this->contentVersion, $this->superAdmin);
    }

    // ── Program ──────────────────────────────────────────────────────────

    public function test_ndmo_is_created_and_configuration_is_independent(): void
    {
        $this->assertDatabaseHas('compliance_programs', ['code' => 'NDMO', 'is_active' => true]);

        $ndmoTerms = app(ProgramConfigurationService::class)->get($this->ndmo, 'terminology');
        $this->assertSame('Policy', $ndmoTerms['category']['en']);

        $qiyasTerms = app(ProgramConfigurationService::class)->get($this->qiyas, 'terminology');
        $this->assertSame('Axis', $qiyasTerms['category']['en'], 'Qiyas terminology must remain unchanged after adding NDMO.');
    }

    public function test_ndmo_scoring_and_deferred_features_are_disabled(): void
    {
        $features = app(ProgramConfigurationService::class)->get($this->ndmo, 'features');
        $this->assertFalse($features['scoring_enabled']);
        $this->assertFalse($features['not_applicable_enabled']);
        $this->assertFalse($features['assessment_result_enabled']);
    }

    // ── Hierarchy engine: a DIFFERENT depth/shape than ECC, zero code change ──

    public function test_ndmo_hierarchy_supports_five_configured_levels(): void
    {
        $nodes = app(ComplianceNodeService::class);
        $requirement = $nodes->createAssessableNode($this->ndmo, 'requirement', 'D1-P1-S1-R1', 'متطلب تجريبي', $this->standard, $this->ndmoCycle, $this->contentVersion, $this->superAdmin);
        $subrequirement = $nodes->createAssessableNode($this->ndmo, 'subrequirement', 'D1-P1-S1-R1-SR1', 'متطلب فرعي تجريبي', $requirement, $this->ndmoCycle, $this->contentVersion, $this->superAdmin);

        $this->assertSame(0, $this->domain->level);
        $this->assertSame(1, $this->policy->level);
        $this->assertSame(2, $this->standard->level);
        $this->assertSame(3, $requirement->level);
        $this->assertSame(4, $subrequirement->level);
        $this->assertNotNull($requirement->standard_id, 'An assessable node must bridge into standards.');
        $this->assertNotNull($subrequirement->standard_id);
    }

    public function test_invalid_parent_child_type_pairs_are_rejected(): void
    {
        $nodes = app(ComplianceNodeService::class);

        $this->expectException(InvalidHierarchyException::class);
        // A 'requirement' cannot be created directly under a 'domain' — its configured parent_type is 'standard'.
        $nodes->createNode($this->ndmo, 'requirement', 'BAD-1', 'خطأ', $this->domain, $this->ndmoCycle, $this->contentVersion, $this->superAdmin);
    }

    public function test_ndmo_node_cannot_use_an_ecc_parent_or_a_qiyas_cycle(): void
    {
        $ecc = ComplianceProgram::where('code', 'ECC')->first();
        $this->assertNotNull($ecc, 'ECC must exist from the Phase 6 migration for this cross-program check to be meaningful.');

        $eccNode = ComplianceNode::create([
            'compliance_program_id' => $ecc->id, 'parent_id' => null,
            'node_type' => 'domain', 'level' => 0, 'code' => 'E-D1', 'name_ar' => 'مجال ECC',
            'created_by' => $this->superAdmin->id,
        ]);

        $nodes = app(ComplianceNodeService::class);
        $this->expectException(InvalidHierarchyException::class);
        $nodes->createNode($this->ndmo, 'policy', 'CROSS-1', 'خطأ عبر برامج', $eccNode, $this->ndmoCycle, $this->contentVersion, $this->superAdmin);
    }

    public function test_ndmo_node_cannot_use_a_qiyas_cycle(): void
    {
        $qiyasCycle = AssessmentCycle::create([
            'compliance_program_id' => $this->qiyas->id, 'name' => 'Qiyas Cycle', 'year' => 2026,
            'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'status' => 'active',
            'is_current' => true, 'created_by' => $this->superAdmin->id,
        ]);

        $nodes = app(ComplianceNodeService::class);
        $this->expectException(InvalidHierarchyException::class);
        $nodes->createNode($this->ndmo, 'policy', 'CROSS-2', 'خطأ عبر برامج', $this->domain, $qiyasCycle, $this->contentVersion, $this->superAdmin);
    }

    // ── Content versions: reused from Phase 6 with zero new code ─────────

    public function test_updating_content_version_does_not_alter_a_historical_ndmo_cycle(): void
    {
        $v2 = ComplianceContentVersion::create([
            'compliance_program_id' => $this->ndmo->id, 'version_label' => 'TEST-V2', 'status' => 'draft',
            'previous_version_id' => $this->contentVersion->id,
        ]);

        $this->ndmoCycle->refresh();
        $this->assertSame($this->contentVersion->id, $this->ndmoCycle->content_version_id);
        $this->assertNotSame($v2->id, $this->ndmoCycle->content_version_id);
    }

    // ── Full workflow lifecycle (via the bridged Standard) ──────────────

    public function test_ndmo_full_lifecycle_completes_via_the_generic_workflow_service(): void
    {
        $ndmoPm = $this->makeUser('employee');
        $this->grantProgramRole($ndmoPm, $this->ndmo, 'program-manager');
        $ndmoAuditor = $this->makeUser('employee');
        $this->grantProgramRole($ndmoAuditor, $this->ndmo, 'auditor');
        $deptA = $this->makeDepartment('NDMO Dept A');
        $deptManager = $this->makeUser('employee', $deptA->id);
        $this->grantProgramRole($deptManager, $this->ndmo, 'department-manager', $deptA->id);
        $employee = $this->makeUser('employee', $deptA->id);
        $this->grantProgramRole($employee, $this->ndmo, 'employee', $deptA->id);

        $nodes = app(ComplianceNodeService::class);
        $requirement = $nodes->createAssessableNode($this->ndmo, 'requirement', 'D1-P1-S1-R2', 'متطلب تجريبي 2', $this->standard, $this->ndmoCycle, $this->contentVersion, $this->superAdmin);

        $workflow = app(WorkflowService::class);
        $assignment = $workflow->assign($requirement->standard, $this->ndmo, $ndmoPm, $deptA, $employee, '2026-12-01', null, null, null);
        $submission = $workflow->getOrCreateDraft($assignment, $employee);
        $workflow->addFile($submission, UploadedFile::fake()->create('e.pdf', 10, 'application/pdf'), $employee);
        $submission = $workflow->submit($submission, $employee, null);
        $this->assertSame('pending_department_manager', $submission->status);

        $submission = $workflow->approve($submission, $deptManager, 'department_manager', 'department-manager', null);
        $this->assertSame('pending_auditor', $submission->status);

        $submission = $workflow->approve($submission, $ndmoAuditor, 'auditor', 'auditor', null);
        $this->assertSame('pending_program_manager', $submission->status);

        $submission = $workflow->approve($submission, $ndmoPm, 'program_manager', 'program-manager', null);
        $this->assertSame('approved', $submission->status);
    }

    public function test_ndmo_rejection_returns_directly_to_employee_and_resubmission_restarts_at_department_manager(): void
    {
        $ndmoPm = $this->makeUser('employee');
        $this->grantProgramRole($ndmoPm, $this->ndmo, 'program-manager');
        $deptA = $this->makeDepartment('NDMO Rejection Dept');
        $deptManager = $this->makeUser('employee', $deptA->id);
        $this->grantProgramRole($deptManager, $this->ndmo, 'department-manager', $deptA->id);
        $employee = $this->makeUser('employee', $deptA->id);
        $this->grantProgramRole($employee, $this->ndmo, 'employee', $deptA->id);

        $nodes = app(ComplianceNodeService::class);
        $requirement = $nodes->createAssessableNode($this->ndmo, 'requirement', 'D1-P1-S1-R3', 'متطلب تجريبي 3', $this->standard, $this->ndmoCycle, $this->contentVersion, $this->superAdmin);

        $workflow = app(WorkflowService::class);
        $assignment = $workflow->assign($requirement->standard, $this->ndmo, $ndmoPm, $deptA, $employee, '2026-12-01', null, null, null);
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

    public function test_ndmo_extension_reviewer_is_program_scoped(): void
    {
        $ndmoPm = $this->makeUser('employee');
        $this->grantProgramRole($ndmoPm, $this->ndmo, 'program-manager');
        $ndmoAuditor = $this->makeUser('employee');
        $this->grantProgramRole($ndmoAuditor, $this->ndmo, 'auditor');
        $deptA = $this->makeDepartment('NDMO Extension Dept');
        $employee = $this->makeUser('employee', $deptA->id);
        $this->grantProgramRole($employee, $this->ndmo, 'employee', $deptA->id);

        $nodes = app(ComplianceNodeService::class);
        $requirement = $nodes->createAssessableNode($this->ndmo, 'requirement', 'D1-P1-S1-R4', 'متطلب تجريبي 4', $this->standard, $this->ndmoCycle, $this->contentVersion, $this->superAdmin);

        $workflow = app(WorkflowService::class);
        $assignment = $workflow->assign($requirement->standard, $this->ndmo, $ndmoPm, $deptA, $employee, now()->addDays(5)->toDateString(), null, null, null);
        $extension = app(ExtensionService::class)->request($assignment, $employee, now()->addDays(20)->toDateString(), 'سبب.');

        $this->assertTrue(Gate::forUser($ndmoAuditor)->allows('decide', $extension));
        $this->assertFalse(Gate::forUser($this->auditor)->allows('decide', $extension), 'A Qiyas-only Auditor must not decide an NDMO extension.');
    }

    // ── Responsibility engine (Phase 7) ──────────────────────────────────

    public function test_responsibility_can_be_assigned_and_revoked_preserving_history(): void
    {
        $ndmoPm = $this->makeUser('employee');
        $this->grantProgramRole($ndmoPm, $this->ndmo, 'program-manager');
        $deptA = $this->makeDepartment('NDMO Responsibility Dept');
        $dataOwner = $this->makeUser('employee', $deptA->id);
        $this->grantProgramRole($dataOwner, $this->ndmo, 'employee', $deptA->id);

        $nodes = app(ComplianceNodeService::class);
        $requirement = $nodes->createAssessableNode($this->ndmo, 'requirement', 'D1-P1-S1-R5', 'متطلب تجريبي 5', $this->standard, $this->ndmoCycle, $this->contentVersion, $this->superAdmin);
        $assignment = app(WorkflowService::class)->assign($requirement->standard, $this->ndmo, $ndmoPm, $deptA, null, '2026-12-01', null, null, null);

        $responsibility = app(ResponsibilityService::class)->assign($assignment, 'data_owner', $ndmoPm, $dataOwner);
        $this->assertTrue($responsibility->is_active);
        $this->assertDatabaseHas('compliance_responsibilities', ['id' => $responsibility->id, 'responsibility_type' => 'data_owner', 'is_active' => true]);

        app(ResponsibilityService::class)->revoke($responsibility, $ndmoPm, 'لم يعد مسؤولاً.');
        $this->assertDatabaseHas('compliance_responsibilities', ['id' => $responsibility->id, 'is_active' => false]);
        // Never deleted — history preserved.
        $this->assertDatabaseCount('compliance_responsibilities', 1);
    }

    public function test_an_unapproved_responsibility_type_is_rejected(): void
    {
        $ndmoPm = $this->makeUser('employee');
        $this->grantProgramRole($ndmoPm, $this->ndmo, 'program-manager');
        $deptA = $this->makeDepartment('NDMO Bad Responsibility Dept');

        $nodes = app(ComplianceNodeService::class);
        $requirement = $nodes->createAssessableNode($this->ndmo, 'requirement', 'D1-P1-S1-R6', 'متطلب تجريبي 6', $this->standard, $this->ndmoCycle, $this->contentVersion, $this->superAdmin);
        $assignment = app(WorkflowService::class)->assign($requirement->standard, $this->ndmo, $ndmoPm, $deptA, null, '2026-12-01', null, null, null);

        $this->expectException(WorkflowConflictException::class);
        app(ResponsibilityService::class)->assign($assignment, 'not_a_real_type', $ndmoPm);
    }

    public function test_responsibility_label_alone_never_grants_workflow_authority(): void
    {
        $ndmoPm = $this->makeUser('employee');
        $this->grantProgramRole($ndmoPm, $this->ndmo, 'program-manager');
        $deptA = $this->makeDepartment('NDMO Authority Dept');
        // dataOwner has ONLY the 'employee' program role — never auditor/department-manager/program-manager.
        $dataOwner = $this->makeUser('employee', $deptA->id);
        $this->grantProgramRole($dataOwner, $this->ndmo, 'employee', $deptA->id);

        $nodes = app(ComplianceNodeService::class);
        $requirement = $nodes->createAssessableNode($this->ndmo, 'requirement', 'D1-P1-S1-R7', 'متطلب تجريبي 7', $this->standard, $this->ndmoCycle, $this->contentVersion, $this->superAdmin);
        $assignment = app(WorkflowService::class)->assign($requirement->standard, $this->ndmo, $ndmoPm, $deptA, null, '2026-12-01', null, null, null);
        app(ResponsibilityService::class)->assign($assignment, 'data_owner', $ndmoPm, $dataOwner);

        // Despite being the assignment's Data Owner, this user has no
        // department-manager/auditor/program-manager program role and
        // must not be able to act as a reviewer at any stage — checked at
        // the real HTTP/controller authorization layer (WorkflowService
        // itself trusts its caller to have already authorized, so this
        // must be exercised through the actual review endpoint, not by
        // calling the service directly).
        $submission = app(WorkflowService::class)->getOrCreateDraft($assignment, $dataOwner);
        app(WorkflowService::class)->addFile($submission, UploadedFile::fake()->create('e.pdf', 10, 'application/pdf'), $dataOwner);
        $submission = app(WorkflowService::class)->submit($submission, $dataOwner, null);

        $this->postJson(
            "/api/v1/programs/{$this->ndmo->code}/reviews/department-manager/{$submission->id}/approve",
            ['notes' => null],
            $this->authHeader($dataOwner),
        )->assertForbidden();
    }

    // ── Cross-program membership (all four programs) ─────────────────────

    public function test_ndmo_only_user_cannot_access_qiyas_sumoud_or_ecc(): void
    {
        $ndmoOnly = $this->makeUser('employee');
        $this->grantProgramRole($ndmoOnly, $this->ndmo, 'employee');

        $this->assertTrue($ndmoOnly->hasProgramAccess($this->ndmo));
        $this->assertFalse($ndmoOnly->hasProgramAccess($this->qiyas));

        $sumoud = ComplianceProgram::where('code', 'SUMOUD')->first();
        $ecc = ComplianceProgram::where('code', 'ECC')->first();
        $this->assertFalse($ndmoOnly->hasProgramAccess($sumoud));
        $this->assertFalse($ndmoOnly->hasProgramAccess($ecc));
    }

    public function test_quad_program_user_resolves_a_different_role_in_each_program(): void
    {
        $sumoud = ComplianceProgram::where('code', 'SUMOUD')->firstOrFail();
        $ecc = ComplianceProgram::where('code', 'ECC')->firstOrFail();

        $user = $this->makeUser('employee');
        $this->grantProgramRole($user, $this->qiyas, 'program-manager');
        $this->grantProgramRole($user, $sumoud, 'auditor');
        $this->grantProgramRole($user, $ecc, 'employee');
        $this->grantProgramRole($user, $this->ndmo, 'department-manager');

        $this->assertTrue($user->hasProgramRole($this->qiyas, 'program-manager'));
        $this->assertTrue($user->hasProgramRole($sumoud, 'auditor'));
        $this->assertTrue($user->hasProgramRole($ecc, 'employee'));
        $this->assertTrue($user->hasProgramRole($this->ndmo, 'department-manager'));
        $this->assertFalse($user->hasProgramRole($this->ndmo, 'program-manager'));
    }
}
