<?php

namespace Tests\Feature\Engine;

use App\Exceptions\InvalidHierarchyException;
use App\Models\AssessmentCycle;
use App\Models\ComplianceContentVersion;
use App\Models\ComplianceNode;
use App\Models\ComplianceProgram;
use App\Services\ComplianceNodeService;
use App\Services\ExtensionService;
use App\Services\HierarchyDefinitionService;
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
 * exercise. The standards mirror was removed in the dynamic-hierarchy
 * phase — the node IS the requirement now.
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
        // The program's structure, activated through the same service a
        // Program Manager uses. Assignability/evidence are level
        // properties now, not implied by depth (audit finding H7).
        $this->activateStructure($this->ecc, [
            ['key' => 'domain'],
            ['key' => 'subdomain'],
            ['key' => 'control', 'is_assignable' => true, 'is_assessable' => true, 'accepts_evidence' => true],
            ['key' => 'subcontrol', 'is_assignable' => true, 'is_assessable' => true, 'accepts_evidence' => true],
        ], $this->superAdmin);

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
        // Mirror removal (audit finding C2): an assessable node is no longer
        // copied into `standards`. It IS the requirement, and — unlike the
        // mirror — it keeps its whole ancestor chain rather than the first
        // two levels.
        $this->assertNull($control->standard_id, 'The standards mirror must no longer be written.');
        $this->assertNull($subcontrol->standard_id);
        $this->assertSame(
            ['D1', 'D1-S1', 'D1-S1-C1', 'D1-S1-C1-SC1'],
            array_column($subcontrol->pathLabels(), 'code'),
            'The full four-level path must survive; the old mirror kept only two.',
        );
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

    /**
     * Depth is now bounded by the program's own structure — the number of
     * level definitions — rather than by a separate `max_depth` number in a
     * configuration blob that could drift out of sync with the level list
     * (audit finding H4). A node type that the structure does not define
     * cannot be created at all.
     */
    public function test_depth_is_bounded_by_the_programs_own_structure(): void
    {
        $nodes = app(ComplianceNodeService::class);

        $this->assertCount(4, app(HierarchyDefinitionService::class)->levels($this->ecc));

        $this->expectException(InvalidHierarchyException::class);
        $nodes->createNode($this->ecc, 'a_fifth_level', 'DEPTH-1', 'تجاوز العمق', $this->subdomain, $this->eccCycle, $this->contentVersion, $this->superAdmin);
    }

    /** A level may only ever parent the level the structure says it parents. */
    public function test_a_node_cannot_be_nested_below_the_deepest_defined_level(): void
    {
        $nodes = app(ComplianceNodeService::class);

        $control = $nodes->createAssessableNode($this->ecc, 'control', 'DEEP-C1', 'ضابط', $this->subdomain, $this->eccCycle, $this->contentVersion, $this->superAdmin);
        $subcontrol = $nodes->createAssessableNode($this->ecc, 'subcontrol', 'DEEP-SC1', 'ضابط فرعي', $control, $this->eccCycle, $this->contentVersion, $this->superAdmin);

        // 'subcontrol' is the deepest level; nothing may sit beneath it.
        $this->expectException(InvalidHierarchyException::class);
        $nodes->createAssessableNode($this->ecc, 'subcontrol', 'DEEP-SC2', 'أعمق', $subcontrol, $this->eccCycle, $this->contentVersion, $this->superAdmin);
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
        $assignment = $workflow->assign($control, $this->ecc, $eccPm, $deptA, $employee, '2026-12-01', null, null, null);
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
        $assignment = $workflow->assign($control, $this->ecc, $eccPm, $deptA, $employee, '2026-12-01', null, null, null);
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
        $assignment = $workflow->assign($control, $this->ecc, $eccPm, $deptA, $employee, now()->addDays(5)->toDateString(), null, null, null);
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
