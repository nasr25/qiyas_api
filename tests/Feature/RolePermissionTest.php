<?php

namespace Tests\Feature;

use App\Models\AssessmentCycle;
use App\Models\ComplianceProgram;
use App\Models\Department;
use App\Models\Document;
use App\Models\EvidenceRequirement;
use App\Models\Standard;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Feature tests for role permissions and strict department data isolation.
 */
class RolePermissionTest extends TestCase
{
    use RefreshDatabase;

    protected Department $deptA;

    protected Department $deptB;

    protected AssessmentCycle $cycle;

    protected Standard $stdA;

    protected Standard $stdB;

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

        $this->stdA = $this->makeStandard('A.1');
        $this->stdA->departments()->attach($this->deptA->id, ['assigned_at' => now(), 'assigned_by' => $admin->id]);

        $this->stdB = $this->makeStandard('B.1');
        $this->stdB->departments()->attach($this->deptB->id, ['assigned_at' => now(), 'assigned_by' => $admin->id]);
    }

    private function makeStandard(string $number): Standard
    {
        return Standard::create([
            'cycle_id' => $this->cycle->id, 'standard_number' => $number,
            'name_ar' => $number, 'name_en' => $number, 'is_active' => true,
        ]);
    }

    private function makeDocument(Standard $std, Department $dept, string $status = 'under_review'): Document
    {
        $req = EvidenceRequirement::create([
            'standard_id' => $std->id, 'title_ar' => 'r', 'title_en' => 'r',
            'is_mandatory' => true, 'sort_order' => 1,
        ]);

        return Document::create([
            'requirement_id' => $req->id, 'department_id' => $dept->id, 'cycle_id' => $this->cycle->id,
            'title' => 'Doc', 'status' => $status, 'current_version' => 1,
        ]);
    }

    // ── Employee ─────────────────────────────────────────────────────────────

    public function test_employee_sees_only_own_department_standards(): void
    {
        $employee = $this->makeUser('employee', $this->deptA->id);

        $res = $this->withHeaders($this->authHeader($employee))
            ->getJson("/api/v1/cycles/{$this->cycle->id}/standards");

        $res->assertOk();
        $numbers = collect($res->json('data'))->pluck('standard_number');
        $this->assertContains('A.1', $numbers);
        $this->assertNotContains('B.1', $numbers);
    }

    public function test_employee_cannot_access_other_department_document(): void
    {
        $employee = $this->makeUser('employee', $this->deptA->id);
        $docB = $this->makeDocument($this->stdB, $this->deptB);

        $this->withHeaders($this->authHeader($employee))
            ->getJson("/api/v1/documents/{$docB->id}")
            ->assertStatus(403);
    }

    public function test_employee_can_access_own_department_document(): void
    {
        $employee = $this->makeUser('employee', $this->deptA->id);
        $docA = $this->makeDocument($this->stdA, $this->deptA);

        $this->withHeaders($this->authHeader($employee))
            ->getJson("/api/v1/documents/{$docA->id}")
            ->assertOk();
    }

    public function test_employee_cannot_approve_documents(): void
    {
        $employee = $this->makeUser('employee', $this->deptA->id);
        $docA = $this->makeDocument($this->stdA, $this->deptA);

        // Employees are not part of the auditor route group → 403.
        $this->withHeaders($this->authHeader($employee))
            ->postJson("/api/v1/auditor/documents/{$docA->id}/approve")
            ->assertStatus(403);
    }

    // ── Auditor ──────────────────────────────────────────────────────────────

    public function test_auditor_can_view_pending_reviews_across_departments(): void
    {
        $auditor = $this->makeUser('auditor');
        $this->makeDocument($this->stdA, $this->deptA);
        $this->makeDocument($this->stdB, $this->deptB);

        $res = $this->withHeaders($this->authHeader($auditor))
            ->getJson('/api/v1/auditor/pending-reviews');

        $res->assertOk();
        $this->assertCount(2, $res->json('data'));
    }

    public function test_auditor_reject_requires_reason(): void
    {
        $auditor = $this->makeUser('auditor');
        $doc = $this->makeDocument($this->stdA, $this->deptA);

        $this->withHeaders($this->authHeader($auditor))
            ->postJson("/api/v1/auditor/documents/{$doc->id}/reject", [])
            ->assertStatus(422);

        $this->withHeaders($this->authHeader($auditor))
            ->postJson("/api/v1/auditor/documents/{$doc->id}/reject", ['reason' => 'Incomplete evidence provided.'])
            ->assertOk();

        $this->assertEquals('rejected', $doc->fresh()->status);
    }

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
