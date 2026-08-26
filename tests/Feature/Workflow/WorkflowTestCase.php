<?php

namespace Tests\Feature\Workflow;

use App\Models\AssessmentCycle;
use App\Models\ComplianceNode;
use App\Models\ComplianceProgram;
use App\Models\Department;
use App\Models\ProgramUserRole;
use App\Models\User;
use App\Services\HierarchyDefinitionService;
use Database\Seeders\EmailTemplatesSeeder;
use Database\Seeders\QiyasProgramConfigurationSeeder;
use Database\Seeders\QiyasWorkflowDefinitionSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Shared fixtures for the Phase 2 workflow test suite: a QIYAS cycle, two
 * departments (A/B) each with a Department Manager + Employee, an Auditor,
 * a Program Manager, and one Standard ready to assign.
 */
abstract class WorkflowTestCase extends TestCase
{
    use RefreshDatabase;

    protected ComplianceProgram $qiyas;

    protected ComplianceProgram $otherProgram;

    protected AssessmentCycle $cycle;

    protected Department $deptA;

    protected Department $deptB;

    protected ComplianceNode $requirement;

    protected User $superAdmin;

    protected User $programManager;

    protected User $auditor;

    protected User $deptAManager;

    protected User $deptAEmployee;

    protected User $deptBManager;

    protected User $deptBEmployee;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(EmailTemplatesSeeder::class);

        $this->qiyas = ComplianceProgram::where('code', 'QIYAS')->firstOrFail();
        $this->seed(QiyasWorkflowDefinitionSeeder::class);
        $this->seed(QiyasProgramConfigurationSeeder::class);
        $this->otherProgram = ComplianceProgram::create([
            'code' => 'OTHER', 'name_ar' => 'برنامج آخر', 'name_en' => 'Other Program',
            'status' => 'active', 'is_active' => true,
        ]);

        $this->superAdmin = $this->makeUser('super-admin');

        $this->cycle = AssessmentCycle::create([
            'compliance_program_id' => $this->qiyas->id,
            'name' => 'Cycle 2026', 'year' => 2026,
            'start_date' => '2026-01-01', 'end_date' => '2026-12-31',
            'status' => 'active', 'is_current' => true, 'created_by' => $this->superAdmin->id,
        ]);

        $this->deptA = $this->makeDepartment('Dept A');
        $this->deptB = $this->makeDepartment('Dept B');

        $this->requirement = $this->seedHierarchy();

        $this->programManager = $this->makeUser('qiyas-admin');
        $this->grantProgramRole($this->programManager, $this->qiyas, 'program-manager');

        $this->auditor = $this->makeUser('auditor');
        $this->grantProgramRole($this->auditor, $this->qiyas, 'auditor');

        $this->deptAManager = $this->makeUser('coordinator', $this->deptA->id);
        $this->grantProgramRole($this->deptAManager, $this->qiyas, 'department-manager', $this->deptA->id);

        $this->deptAEmployee = $this->makeUser('employee', $this->deptA->id);
        $this->grantProgramRole($this->deptAEmployee, $this->qiyas, 'employee', $this->deptA->id);

        $this->deptBManager = $this->makeUser('coordinator', $this->deptB->id);
        $this->grantProgramRole($this->deptBManager, $this->qiyas, 'department-manager', $this->deptB->id);

        $this->deptBEmployee = $this->makeUser('employee', $this->deptB->id);
        $this->grantProgramRole($this->deptBEmployee, $this->qiyas, 'employee', $this->deptB->id);
    }

    /**
     * Activates a three-level QIYAS structure and builds one node per level,
     * returning the assessable leaf. Deliberately deeper than two levels so
     * the suite would catch any reintroduced fixed-depth assumption.
     */
    protected function seedHierarchy(): ComplianceNode
    {
        $structures = app(HierarchyDefinitionService::class);
        $draft = $structures->openDraft($this->qiyas, $this->superAdmin);

        foreach ([
            ['key' => 'perspective', 'name_ar' => 'المنظور', 'name_en' => 'Perspective'],
            ['key' => 'axis', 'name_ar' => 'المحور', 'name_en' => 'Axis'],
            ['key' => 'criterion', 'name_ar' => 'المعيار', 'name_en' => 'Criterion',
                'is_assignable' => true, 'is_assessable' => true, 'accepts_evidence' => true],
        ] as $level) {
            $structures->addLevel($draft, $level + [
                'appears_in_dashboard' => true, 'appears_in_reports' => true,
                'appears_in_filters' => true, 'appears_in_breadcrumb' => true,
            ], $this->superAdmin);
        }
        $structures->activate($draft->fresh(), $this->superAdmin);

        $parent = null;
        foreach ($structures->levels($this->qiyas) as $index => $level) {
            $parent = ComplianceNode::create([
                'compliance_program_id' => $this->qiyas->id,
                'program_cycle_id' => $this->cycle->id,
                'hierarchy_level_id' => $level->id,
                'parent_id' => $parent?->id,
                'node_type' => $level->key,
                'level' => $index,
                'code' => 'STD-1-L'.($index + 1),
                'name_ar' => 'معيار', 'name_en' => 'Standard',
                'created_by' => $this->superAdmin->id,
            ]);
        }

        return $parent;
    }

    protected function grantProgramRole(User $user, ComplianceProgram $program, string $roleKey, ?int $departmentId = null): ProgramUserRole
    {
        return ProgramUserRole::create([
            'user_id' => $user->id, 'compliance_program_id' => $program->id,
            'role_key' => $roleKey, 'department_id' => $departmentId,
            'is_active' => true, 'assigned_at' => now(),
        ]);
    }
}
