<?php

namespace Tests\Feature;

use App\Models\AssessmentCycle;
use App\Models\ComplianceNode;
use App\Models\ComplianceProgram;
use App\Models\Department;
use App\Models\ProgramUserRole;
use App\Services\HierarchyDefinitionService;
use App\Services\WorkflowService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Role permissions and strict department data isolation.
 *
 * Five tests that exercised the legacy Document review API were retired
 * when that API was removed with the legacy Standard authoring path. Every
 * guarantee they asserted is covered on the supported EvidenceSubmission
 * path — see docs/testing/legacy-playwright-retirement.md for the
 * test-by-test mapping.
 */
class RolePermissionTest extends TestCase
{
    use RefreshDatabase;

    protected Department $deptA;

    protected Department $deptB;

    protected AssessmentCycle $cycle;

    protected ComplianceNode $nodeA;

    protected ComplianceNode $nodeB;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);

        $admin = $this->makeUser('super-admin');

        $this->deptA = $this->makeDepartment('Dept A');
        $this->deptB = $this->makeDepartment('Dept B');

        $this->cycle = AssessmentCycle::create([
            'compliance_program_id' => ComplianceProgram::where('code', 'QIYAS')->value('id'),
            'name' => 'Cycle 2026', 'year' => 2026,
            'start_date' => '2026-01-01', 'end_date' => '2026-12-31',
            'status' => 'active', 'is_current' => true, 'created_by' => $admin->id,
        ]);

        // A two-level structure with one assignable node per department —
        // the node-based equivalent of the old per-department standards.
        $this->activateStructure(
            ComplianceProgram::where('code', 'QIYAS')->firstOrFail(),
            [['key' => 'domain'], ['key' => 'requirement', 'is_assignable' => true, 'is_assessable' => true, 'accepts_evidence' => true]],
            $admin,
        );

        $this->nodeA = $this->makeNodeChain('A.1');
        $this->nodeB = $this->makeNodeChain('B.1');

        $workflow = app(WorkflowService::class);
        $program = ComplianceProgram::where('code', 'QIYAS')->firstOrFail();
        $workflow->assign($this->nodeA, $program, $admin, $this->deptA, null, null, null, null, null);
        $workflow->assign($this->nodeB, $program, $admin, $this->deptB, null, null, null, null, null);
    }

    /** Builds a root + assignable leaf and returns the leaf. */
    private function makeNodeChain(string $code): ComplianceNode
    {
        $program = ComplianceProgram::where('code', 'QIYAS')->firstOrFail();
        $levels = collect(app(HierarchyDefinitionService::class)->levels($program))->values();

        $root = ComplianceNode::create([
            'compliance_program_id' => $program->id, 'program_cycle_id' => $this->cycle->id,
            'hierarchy_level_id' => $levels[0]->id, 'node_type' => $levels[0]->key,
            'level' => 0, 'code' => "{$code}-root", 'name_ar' => $code,
        ]);

        return ComplianceNode::create([
            'compliance_program_id' => $program->id, 'program_cycle_id' => $this->cycle->id,
            'hierarchy_level_id' => $levels[1]->id, 'parent_id' => $root->id,
            'node_type' => $levels[1]->key, 'level' => 1, 'code' => $code, 'name_ar' => $code,
        ]);
    }

    // ── Employee ─────────────────────────────────────────────────────────────

    /**
     * Department isolation, rewritten for the node model.
     *
     * Program CONTENT (the hierarchy) is visible to every program member —
     * an employee must be able to see the structure their work sits in.
     * What is department-scoped is the WORK: assignments and evidence. This
     * asserts the latter, which is where the isolation guarantee actually
     * lives.
     */
    public function test_employee_sees_only_own_department_assignments(): void
    {
        $employee = $this->makeUser('employee', $this->deptA->id);
        ProgramUserRole::create([
            'compliance_program_id' => ComplianceProgram::where('code', 'QIYAS')->value('id'),
            'user_id' => $employee->id, 'role_key' => 'employee',
            'department_id' => $this->deptA->id, 'is_active' => true,
        ]);

        $res = $this->withHeaders($this->authHeader($employee))
            ->getJson('/api/v1/programs/QIYAS/my-requirements')
            ->assertOk();

        $codes = collect($res->json('data'))->pluck('requirement.code');
        $this->assertContains('A.1', $codes);
        $this->assertNotContains('B.1', $codes, 'Another department\'s assignment must never be listed.');
    }

    // ── Auditor ──────────────────────────────────────────────────────────────

    public function test_auditor_cannot_manage_users(): void
    {
        $auditor = $this->makeUser('auditor');

        $this->withHeaders($this->authHeader($auditor))
            ->postJson('/api/v1/admin/users', ['name' => 'X', 'username' => 'x', 'password' => 'Password123!', 'roles' => ['employee']])
            ->assertStatus(403);
    }

    // ── Executive (read-only) ─────────────────────────────────────────────────

    public function test_executive_cannot_create_cycle(): void
    {
        $exec = $this->makeUser('executive');

        $this->withHeaders($this->authHeader($exec))
            ->postJson('/api/v1/cycles', ['name' => 'X', 'year' => 2027, 'start_date' => '2027-01-01', 'end_date' => '2027-12-31'])
            ->assertStatus(403);
    }

    public function test_executive_can_view_reports(): void
    {
        $exec = $this->makeUser('executive');

        $this->withHeaders($this->authHeader($exec))
            ->getJson("/api/v1/reports/by-department?cycle_id={$this->cycle->id}")
            ->assertOk();
    }

    // ── Super Admin ───────────────────────────────────────────────────────────

    public function test_super_admin_can_manage_users(): void
    {
        $admin = $this->makeUser('super-admin');

        $this->withHeaders($this->authHeader($admin))
            ->postJson('/api/v1/admin/users', [
                'name' => 'New', 'username' => 'newuser', 'password' => 'Password123!',
                'roles' => ['employee'],
            ])
            ->assertCreated();
    }
}
