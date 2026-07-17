<?php

namespace Tests\Feature;

use App\Models\AssessmentCycle;
use App\Models\ComplianceProgram;
use App\Models\Department;
use App\Models\ProgramUserRole;
use App\Models\Standard;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Covers the Phase 1 multi-program authorization matrix: program access
 * (super-admin/executive implicit, others via program_user_roles),
 * cross-program IDOR protection, inactive-program access, and program
 * context in audit logs. Department-level isolation on the underlying
 * evidence/document data is already covered by RolePermissionTest and is
 * not duplicated here — this file is specifically about the new program
 * layer sitting above it.
 */
class ComplianceProgramAccessTest extends TestCase
{
    use RefreshDatabase;

    protected ComplianceProgram $qiyas;

    protected ComplianceProgram $otherProgram;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);

        // Seeded by the compliance_programs migration itself.
        $this->qiyas = ComplianceProgram::where('code', 'QIYAS')->firstOrFail();

        // A second program with no assigned users, representing a future
        // program (e.g. Sumoud) — used to prove cross-program isolation.
        $this->otherProgram = ComplianceProgram::create([
            'code' => 'OTHER', 'name_ar' => 'برنامج آخر', 'name_en' => 'Other Program',
            'status' => 'active', 'is_active' => true,
        ]);
    }

    private function grantProgramRole(User $user, ComplianceProgram $program, string $roleKey, ?int $departmentId = null): void
    {
        ProgramUserRole::create([
            'user_id' => $user->id, 'compliance_program_id' => $program->id,
            'role_key' => $roleKey, 'department_id' => $departmentId,
            'is_active' => true, 'assigned_at' => now(),
        ]);
    }

    // ── Program Selection listing ──────────────────────────────────────────

    public function test_program_selection_lists_only_authorized_programs(): void
    {
        $user = $this->makeUser('employee');
        $this->grantProgramRole($user, $this->qiyas, 'employee');

        $response = $this->getJson('/api/v1/programs', $this->authHeader($user))->assertOk();

        $codes = collect($response->json('data'))->pluck('code');
        $this->assertTrue($codes->contains('QIYAS'));
        $this->assertFalse($codes->contains('OTHER'));
    }

    public function test_super_admin_sees_all_active_programs(): void
    {
        $admin = $this->makeUser('super-admin');

        $response = $this->getJson('/api/v1/programs', $this->authHeader($admin))->assertOk();

        $codes = collect($response->json('data'))->pluck('code');
        $this->assertTrue($codes->contains('QIYAS'));
        $this->assertTrue($codes->contains('OTHER'));
    }

    public function test_user_with_no_program_role_sees_no_programs(): void
    {
        $user = $this->makeUser('employee'); // no program_user_roles row

        $response = $this->getJson('/api/v1/programs', $this->authHeader($user))->assertOk();

        $this->assertCount(0, $response->json('data'));
    }

    // ── Program access enforcement ─────────────────────────────────────────

    public function test_super_admin_can_access_qiyas_dashboard(): void
    {
        $admin = $this->makeUser('super-admin');

        $this->getJson('/api/v1/programs/QIYAS/dashboard', $this->authHeader($admin))
            ->assertOk();
    }

    public function test_authorized_qiyas_user_can_access_qiyas(): void
    {
        $user = $this->makeUser('qiyas-admin');
        $this->grantProgramRole($user, $this->qiyas, 'program-manager');

        $this->getJson('/api/v1/programs/QIYAS', $this->authHeader($user))
            ->assertOk()
            ->assertJsonPath('data.code', 'QIYAS');
    }

    public function test_unauthorized_user_cannot_access_qiyas(): void
    {
        $user = $this->makeUser('employee'); // no program_user_roles row, not platform role

        $this->getJson('/api/v1/programs/QIYAS', $this->authHeader($user))
            ->assertStatus(404);
    }

    public function test_user_cannot_access_another_program_by_changing_the_url(): void
    {
        $user = $this->makeUser('employee');
        $this->grantProgramRole($user, $this->qiyas, 'employee');

        // Authorized for QIYAS, but not for OTHER — must not leak via URL swap.
        $this->getJson('/api/v1/programs/OTHER', $this->authHeader($user))
            ->assertStatus(404);
    }

    public function test_nonexistent_program_code_returns_not_found_not_leaking_existence(): void
    {
        $admin = $this->makeUser('super-admin');

        $this->getJson('/api/v1/programs/DOES-NOT-EXIST', $this->authHeader($admin))
            ->assertStatus(404);
    }

    public function test_program_manager_permissions_limited_to_assigned_programs(): void
    {
        $manager = $this->makeUser('qiyas-admin');
        $this->grantProgramRole($manager, $this->qiyas, 'program-manager');

        $this->getJson('/api/v1/programs/QIYAS/cycles', $this->authHeader($manager))->assertOk();
        $this->getJson('/api/v1/programs/OTHER/cycles', $this->authHeader($manager))->assertStatus(404);
    }

    public function test_auditor_permissions_limited_to_assigned_programs(): void
    {
        $auditor = $this->makeUser('auditor');
        $this->grantProgramRole($auditor, $this->qiyas, 'auditor');

        $this->getJson('/api/v1/programs/QIYAS/requirements', $this->authHeader($auditor))->assertOk();
        $this->getJson('/api/v1/programs/OTHER/requirements', $this->authHeader($auditor))->assertStatus(404);
    }

    public function test_executive_viewer_has_read_only_implicit_access(): void
    {
        $executive = $this->makeUser('executive');

        // Implicit access to any active program, no program_user_roles row needed.
        $this->getJson('/api/v1/programs/QIYAS/dashboard', $this->authHeader($executive))->assertOk();

        // But still cannot manage programs (Super Admin only).
        $this->assertFalse($executive->can('manage', ComplianceProgram::class));
    }

    public function test_inactive_program_is_not_accessible_to_normal_users(): void
    {
        $this->otherProgram->update(['status' => 'inactive', 'is_active' => false]);

        $user = $this->makeUser('employee');
        $this->grantProgramRole($user, $this->otherProgram, 'employee');

        $this->getJson('/api/v1/programs/OTHER', $this->authHeader($user))
            ->assertStatus(404);
    }

    public function test_inactive_program_remains_accessible_to_super_admin(): void
    {
        $this->otherProgram->update(['status' => 'inactive', 'is_active' => false]);

        $admin = $this->makeUser('super-admin');

        $this->getJson('/api/v1/programs/OTHER', $this->authHeader($admin))->assertOk();
    }

    // ── Cross-program IDOR on nested records ───────────────────────────────

    public function test_program_scoped_cycle_cannot_be_accessed_across_programs(): void
    {
        $admin = $this->makeUser('super-admin');

        $otherCycle = AssessmentCycle::create([
            'compliance_program_id' => $this->otherProgram->id,
            'name' => 'Other Cycle', 'year' => 2026,
            'start_date' => '2026-01-01', 'end_date' => '2026-12-31',
            'status' => 'draft', 'created_by' => $admin->id,
        ]);

        // Even super-admin, going through the QIYAS program context, must not
        // reach a cycle that belongs to a different program via its id.
        $this->getJson("/api/v1/programs/QIYAS/cycles/{$otherCycle->id}", $this->authHeader($admin))
            ->assertStatus(404);
    }

    // ── Data preserved after migration ─────────────────────────────────────

    public function test_existing_qiyas_records_remain_accessible_after_migration(): void
    {
        $admin = $this->makeUser('super-admin');

        $cycle = AssessmentCycle::create([
            'compliance_program_id' => $this->qiyas->id,
            'name' => 'Legacy Cycle', 'year' => 2026,
            'start_date' => '2026-01-01', 'end_date' => '2026-12-31',
            'status' => 'active', 'is_current' => true, 'created_by' => $admin->id,
        ]);

        $standard = Standard::create([
            'cycle_id' => $cycle->id, 'standard_number' => 'X.1',
            'name_ar' => 'معيار', 'name_en' => 'Standard', 'is_active' => true,
        ]);

        // Auto-stamped by the model's creating hook, not passed explicitly.
        $this->assertEquals($this->qiyas->id, $standard->fresh()->compliance_program_id);

        $this->getJson('/api/v1/programs/QIYAS/requirements', $this->authHeader($admin))
            ->assertOk()
            ->assertJsonFragment(['number' => 'X.1']);
    }

    // ── Audit log program context ──────────────────────────────────────────

    public function test_cycle_creation_audit_log_includes_program_context(): void
    {
        $admin = $this->makeUser('super-admin');

        $this->postJson('/api/v1/cycles', [
            'name' => 'Audited Cycle', 'year' => 2027,
            'start_date' => '2027-01-01', 'end_date' => '2027-12-31',
        ], $this->authHeader($admin))->assertCreated();

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'cycle.created',
            'compliance_program_id' => $this->qiyas->id,
        ]);
    }

    // ── Bilingual terminology ───────────────────────────────────────────────

    public function test_program_terminology_is_available_in_both_languages(): void
    {
        $admin = $this->makeUser('super-admin');

        $response = $this->getJson('/api/v1/programs/QIYAS', $this->authHeader($admin))->assertOk();

        $response->assertJsonPath('data.terminology.requirement.ar', 'المعيار');
        $response->assertJsonPath('data.terminology.requirement.en', 'Standard');
        $response->assertJsonPath('data.name_ar', 'قياس');
        $response->assertJsonPath('data.name_en', 'Qiyas');
    }

    // ── Quick Login production gate ────────────────────────────────────────

    public function test_quick_login_is_unavailable_when_environment_is_production(): void
    {
        $this->app['env'] = 'production';
        config(['app.debug' => false]);

        $this->postJson('/api/v1/auth/quick-login', ['username' => 'superadmin'])
            ->assertStatus(403);

        $this->getJson('/api/v1/auth/dev-users')
            ->assertOk()->assertJson(['data' => []]);
    }
}
