<?php

namespace Tests\Feature\Hierarchy;

use App\Models\AssessmentCycle;
use App\Models\ComplianceNode;
use App\Models\ComplianceProgram;
use App\Models\ProgramUserRole;
use App\Models\User;
use App\Services\HierarchyDefinitionService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Structure Settings API: the permission matrix and the validation the
 * Phase B brief requires be enforced on the BACKEND rather than by hiding
 * buttons.
 *
 * The distinction that matters most here — and is easy to get wrong — is
 * 403 vs 404. A user with no access to a program at all must get 404 so
 * program codes cannot be enumerated; a user who can see the program but
 * is not its Program Manager must get 403.
 */
class ProgramStructureApiTest extends TestCase
{
    use RefreshDatabase;

    private ComplianceProgram $qiyas;

    private ComplianceProgram $other;

    private HierarchyDefinitionService $structures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->qiyas = ComplianceProgram::where('code', 'QIYAS')->firstOrFail();
        $this->other = ComplianceProgram::create([
            'code' => 'OTHER', 'name_ar' => 'آخر', 'name_en' => 'Other',
            'status' => 'active', 'is_active' => true,
        ]);
        $this->structures = app(HierarchyDefinitionService::class);
    }

    private function programManagerFor(ComplianceProgram $program): User
    {
        // A plain user carrying only the program-scoped role — deliberately
        // NOT a platform super-admin, so the policy is genuinely exercised.
        $user = $this->makeUser('employee');
        ProgramUserRole::create([
            'compliance_program_id' => $program->id,
            'user_id' => $user->id,
            'role_key' => 'program-manager',
            'is_active' => true,
        ]);

        return $user;
    }

    private function memberOf(ComplianceProgram $program, string $roleKey = 'employee'): User
    {
        $user = $this->makeUser('employee');
        ProgramUserRole::create([
            'compliance_program_id' => $program->id,
            'user_id' => $user->id,
            'role_key' => $roleKey,
            'is_active' => true,
        ]);

        return $user;
    }

    private function seedStructure(ComplianceProgram $program, int $depth = 3): void
    {
        $actor = $this->makeUser('super-admin');
        $draft = $this->structures->openDraft($program, $actor);
        for ($i = 1; $i <= $depth; $i++) {
            $this->structures->addLevel($draft, [
                'key' => "level_{$i}",
                'name_ar' => "المستوى {$i}",
                'name_en' => "Level {$i}",
                'is_assessable' => $i === $depth,
                'is_assignable' => $i === $depth,
                'accepts_evidence' => $i === $depth,
            ], $actor);
        }
        $this->structures->activate($draft->fresh(), $actor);
    }

    // ─── Permission matrix ───────────────────────────────────────────────────

    public function test_program_manager_can_read_and_manage_their_own_program(): void
    {
        $this->seedStructure($this->qiyas);
        $pm = $this->programManagerFor($this->qiyas);

        $this->getJson('/api/v1/programs/QIYAS/structure', $this->authHeader($pm))
            ->assertOk()
            ->assertJsonPath('data.can_manage', true)
            ->assertJsonPath('data.definition.depth', 3);

        $this->postJson('/api/v1/programs/QIYAS/structure/draft', [], $this->authHeader($pm))
            ->assertCreated()
            ->assertJsonPath('data.status', 'draft');
    }

    public function test_program_manager_of_another_program_cannot_reach_this_one(): void
    {
        $this->seedStructure($this->qiyas);
        // Program Manager of OTHER, with no membership in QIYAS at all.
        $pm = $this->programManagerFor($this->other);

        // 404, not 403 — the program's existence must not be disclosed.
        $this->getJson('/api/v1/programs/QIYAS/structure', $this->authHeader($pm))->assertNotFound();
        $this->postJson('/api/v1/programs/QIYAS/structure/draft', [], $this->authHeader($pm))->assertNotFound();
    }

    public function test_a_member_who_is_not_program_manager_may_read_but_not_write(): void
    {
        $this->seedStructure($this->qiyas);
        $member = $this->memberOf($this->qiyas, 'employee');

        $this->getJson('/api/v1/programs/QIYAS/structure', $this->authHeader($member))
            ->assertOk()
            ->assertJsonPath('data.can_manage', false);

        // Visible, but immutable.
        $this->postJson('/api/v1/programs/QIYAS/structure/draft', [], $this->authHeader($member))->assertForbidden();
        $this->getJson('/api/v1/programs/QIYAS/structure/draft', $this->authHeader($member))->assertForbidden();
        $this->getJson('/api/v1/programs/QIYAS/structure/draft/impact', $this->authHeader($member))->assertForbidden();
        $this->postJson('/api/v1/programs/QIYAS/structure/draft/activate', [], $this->authHeader($member))->assertForbidden();
    }

    public function test_being_program_manager_elsewhere_grants_nothing_here(): void
    {
        $this->seedStructure($this->qiyas);

        // Program Manager of OTHER, but only an employee in QIYAS.
        $user = $this->programManagerFor($this->other);
        ProgramUserRole::create([
            'compliance_program_id' => $this->qiyas->id,
            'user_id' => $user->id,
            'role_key' => 'employee',
            'is_active' => true,
        ]);

        $this->getJson('/api/v1/programs/QIYAS/structure', $this->authHeader($user))
            ->assertOk()
            ->assertJsonPath('data.can_manage', false);
        $this->postJson('/api/v1/programs/QIYAS/structure/draft', [], $this->authHeader($user))->assertForbidden();

        // …while their own program remains fully manageable.
        $this->postJson('/api/v1/programs/OTHER/structure/draft', [], $this->authHeader($user))->assertCreated();
    }

    public function test_super_admin_can_manage_any_program(): void
    {
        $this->seedStructure($this->qiyas);
        $admin = $this->makeUser('super-admin');

        $this->postJson('/api/v1/programs/QIYAS/structure/draft', [], $this->authHeader($admin))->assertCreated();
        $this->postJson('/api/v1/programs/OTHER/structure/draft', [], $this->authHeader($admin))->assertCreated();
    }

    public function test_a_level_of_another_program_cannot_be_edited_by_id(): void
    {
        $this->seedStructure($this->qiyas);
        $this->seedStructure($this->other);

        $pm = $this->programManagerFor($this->other);
        $this->postJson('/api/v1/programs/OTHER/structure/draft', [], $this->authHeader($pm))->assertCreated();

        // A level id belonging to QIYAS, addressed through the OTHER program.
        $foreignLevel = $this->structures->activeDefinition($this->qiyas)->levels()->get()->first();

        $this->putJson(
            "/api/v1/programs/OTHER/structure/draft/levels/{$foreignLevel->id}",
            ['name_en' => 'Hijacked'],
            $this->authHeader($pm),
        )->assertNotFound();

        $this->assertNotSame('Hijacked', $foreignLevel->fresh()->name_en);
    }

    // ─── Level configuration ─────────────────────────────────────────────────

    public function test_a_manager_can_configure_every_level_property(): void
    {
        $this->seedStructure($this->qiyas);
        $pm = $this->programManagerFor($this->qiyas);
        $this->postJson('/api/v1/programs/QIYAS/structure/draft', [], $this->authHeader($pm));

        $response = $this->postJson('/api/v1/programs/QIYAS/structure/draft/levels', [
            'key' => 'evidence_step',
            'name_ar' => 'خطوة الإثبات',
            'name_en' => 'Evidence Step',
            'plural_name_ar' => 'خطوات الإثبات',
            'plural_name_en' => 'Evidence Steps',
            'is_active' => true,
            'is_assignable' => true,
            'is_assessable' => true,
            'accepts_evidence' => true,
            'appears_in_dashboard' => true,
            'appears_in_reports' => true,
            'appears_in_filters' => true,
            'appears_in_breadcrumb' => true,
            'weight_enabled' => true,
            'due_date_enabled' => true,
        ], $this->authHeader($pm))->assertCreated();

        foreach ([
            'is_assignable', 'is_assessable', 'accepts_evidence',
            'appears_in_dashboard', 'appears_in_reports', 'appears_in_filters',
            'appears_in_breadcrumb', 'weight_enabled', 'due_date_enabled',
        ] as $flag) {
            $response->assertJsonPath("data.{$flag}", true);
        }

        $response->assertJsonPath('data.name_ar', 'خطوة الإثبات')
            ->assertJsonPath('data.name_en', 'Evidence Step')
            ->assertJsonPath('data.level_order', 4);
    }

    public function test_level_key_must_be_a_machine_identifier(): void
    {
        $this->seedStructure($this->qiyas);
        $pm = $this->programManagerFor($this->qiyas);
        $this->postJson('/api/v1/programs/QIYAS/structure/draft', [], $this->authHeader($pm));

        $this->postJson('/api/v1/programs/QIYAS/structure/draft/levels', [
            'key' => 'Not A Key!', 'name_ar' => 'أ', 'name_en' => 'A',
        ], $this->authHeader($pm))->assertStatus(422)->assertJsonValidationErrors('key');
    }

    public function test_a_duplicate_level_key_is_rejected(): void
    {
        $this->seedStructure($this->qiyas);
        $pm = $this->programManagerFor($this->qiyas);
        $this->postJson('/api/v1/programs/QIYAS/structure/draft', [], $this->authHeader($pm));

        $this->postJson('/api/v1/programs/QIYAS/structure/draft/levels', [
            'key' => 'level_1', 'name_ar' => 'أ', 'name_en' => 'A',
        ], $this->authHeader($pm))->assertStatus(422);
    }

    public function test_levels_can_be_reordered_and_disabled_through_the_api(): void
    {
        $this->seedStructure($this->qiyas, 4);
        $pm = $this->programManagerFor($this->qiyas);
        $draft = $this->postJson('/api/v1/programs/QIYAS/structure/draft', [], $this->authHeader($pm))->json('data');

        $third = collect($draft['levels'])->firstWhere('key', 'level_3');

        $this->postJson("/api/v1/programs/QIYAS/structure/draft/levels/{$third['id']}/move",
            ['direction' => 'up'], $this->authHeader($pm))->assertOk();

        $after = $this->getJson('/api/v1/programs/QIYAS/structure/draft', $this->authHeader($pm))->json('data.levels');
        $this->assertSame(['level_1', 'level_3', 'level_2', 'level_4'], collect($after)->pluck('key')->all());

        $second = collect($after)->firstWhere('key', 'level_2');
        $this->putJson("/api/v1/programs/QIYAS/structure/draft/levels/{$second['id']}",
            ['is_active' => false], $this->authHeader($pm))
            ->assertOk()
            ->assertJsonPath('data.is_active', false);
    }

    // ─── Draft / preview / activate flow ─────────────────────────────────────

    public function test_the_full_draft_preview_activate_flow_adds_a_level_without_code_changes(): void
    {
        $this->seedStructure($this->qiyas, 3);
        $pm = $this->programManagerFor($this->qiyas);

        $this->postJson('/api/v1/programs/QIYAS/structure/draft', [], $this->authHeader($pm))->assertCreated();
        $this->postJson('/api/v1/programs/QIYAS/structure/draft/levels', [
            'key' => 'level_4', 'name_ar' => 'رابع', 'name_en' => 'Fourth', 'is_assessable' => true,
        ], $this->authHeader($pm))->assertCreated();

        $this->getJson('/api/v1/programs/QIYAS/structure/draft/impact', $this->authHeader($pm))
            ->assertOk()
            ->assertJsonPath('data.classification', 'safe')
            ->assertJsonPath('data.blocking', false);

        $this->postJson('/api/v1/programs/QIYAS/structure/draft/activate',
            ['change_summary' => 'Added a fourth level'], $this->authHeader($pm))
            ->assertOk()
            ->assertJsonPath('data.status', 'active')
            ->assertJsonPath('data.version', 2)
            ->assertJsonPath('data.depth', 4);

        // Version history keeps both, and the old snapshot is untouched.
        $versions = $this->getJson('/api/v1/programs/QIYAS/structure/versions', $this->authHeader($pm))->json('data');
        $this->assertCount(2, $versions);
        $this->assertSame(4, collect($versions)->firstWhere('version', 2)['level_count']);
        $this->assertSame(3, collect($versions)->firstWhere('version', 1)['level_count']);
    }

    public function test_activation_is_refused_while_the_draft_is_invalid(): void
    {
        $this->seedStructure($this->qiyas, 3);
        $pm = $this->programManagerFor($this->qiyas);
        $draft = $this->postJson('/api/v1/programs/QIYAS/structure/draft', [], $this->authHeader($pm))->json('data');

        // Disable the only assessable level: nothing could ever be reviewed.
        $leaf = collect($draft['levels'])->firstWhere('key', 'level_3');
        $this->putJson("/api/v1/programs/QIYAS/structure/draft/levels/{$leaf['id']}",
            ['is_active' => false], $this->authHeader($pm))->assertOk();

        $this->getJson('/api/v1/programs/QIYAS/structure/draft', $this->authHeader($pm))
            ->assertOk()
            ->assertJsonCount(1, 'data.validation_errors');

        $this->postJson('/api/v1/programs/QIYAS/structure/draft/activate', [], $this->authHeader($pm))
            ->assertStatus(422);
    }

    // ─── Active-cycle protection ─────────────────────────────────────────────

    public function test_removing_a_populated_level_during_an_active_cycle_is_blocked_by_the_api(): void
    {
        $this->seedStructure($this->qiyas, 3);
        $pm = $this->programManagerFor($this->qiyas);
        $this->seedContent();

        $draft = $this->postJson('/api/v1/programs/QIYAS/structure/draft', [], $this->authHeader($pm))->json('data');
        $leaf = collect($draft['levels'])->firstWhere('key', 'level_3');

        $this->deleteJson("/api/v1/programs/QIYAS/structure/draft/levels/{$leaf['id']}", [], $this->authHeader($pm))
            ->assertOk();

        $this->getJson('/api/v1/programs/QIYAS/structure/draft/impact', $this->authHeader($pm))
            ->assertOk()
            ->assertJsonPath('data.classification', 'not_allowed')
            ->assertJsonPath('data.blocking', true);

        // Even explicitly acknowledging cannot force a blocked change.
        $this->postJson('/api/v1/programs/QIYAS/structure/draft/activate',
            ['acknowledge_migration' => true], $this->authHeader($pm))
            ->assertStatus(422);
    }

    public function test_inserting_a_level_mid_cycle_requires_explicit_acknowledgement(): void
    {
        $this->seedStructure($this->qiyas, 3);
        $pm = $this->programManagerFor($this->qiyas);
        $this->seedContent();

        $this->postJson('/api/v1/programs/QIYAS/structure/draft', [], $this->authHeader($pm))->assertCreated();
        $this->postJson('/api/v1/programs/QIYAS/structure/draft/levels', [
            'key' => 'inserted', 'name_ar' => 'مضاف', 'name_en' => 'Inserted', 'is_assessable' => true,
        ], $this->authHeader($pm))->assertCreated();

        $this->getJson('/api/v1/programs/QIYAS/structure/draft/impact', $this->authHeader($pm))
            ->assertJsonPath('data.classification', 'requires_migration');

        $this->postJson('/api/v1/programs/QIYAS/structure/draft/activate', [], $this->authHeader($pm))
            ->assertStatus(422);

        $this->postJson('/api/v1/programs/QIYAS/structure/draft/activate',
            ['acknowledge_migration' => true], $this->authHeader($pm))
            ->assertOk();
    }

    private function seedContent(): void
    {
        $actor = $this->makeUser('super-admin');
        $cycle = AssessmentCycle::create([
            'compliance_program_id' => $this->qiyas->id,
            'name' => 'دورة', 'year' => 2026,
            'start_date' => now()->subMonth(), 'end_date' => now()->addMonth(),
            'status' => 'active', 'is_current' => true, 'created_by' => $actor->id,
        ]);

        $parent = null;
        foreach ($this->structures->levels($this->qiyas) as $index => $level) {
            $parent = ComplianceNode::create([
                'compliance_program_id' => $this->qiyas->id,
                'program_cycle_id' => $cycle->id,
                'hierarchy_level_id' => $level->id,
                'parent_id' => $parent?->id,
                'node_type' => $level->key, 'level' => $index,
                'code' => "C{$index}", 'name_ar' => "عقدة {$index}",
            ]);
        }
    }
}
