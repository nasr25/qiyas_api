<?php

namespace Tests\Feature\Workflow;

use App\Models\AssessmentCycle;
use App\Models\ComplianceProgram;
use App\Models\Department;
use App\Models\ProgramUserRole;
use App\Models\Standard;
use App\Models\User;
use Database\Seeders\EmailTemplatesSeeder;
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

    protected Standard $requirement;

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

        $this->requirement = Standard::create([
            'cycle_id' => $this->cycle->id, 'standard_number' => 'STD-1',
            'name_ar' => 'معيار', 'name_en' => 'Standard', 'is_active' => true,
        ]);

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

    protected function grantProgramRole(User $user, ComplianceProgram $program, string $roleKey, ?int $departmentId = null): ProgramUserRole
    {
        return ProgramUserRole::create([
            'user_id' => $user->id, 'compliance_program_id' => $program->id,
            'role_key' => $roleKey, 'department_id' => $departmentId,
            'is_active' => true, 'assigned_at' => now(),
        ]);
    }
}
