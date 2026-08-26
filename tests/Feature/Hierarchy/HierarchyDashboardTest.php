<?php

namespace Tests\Feature\Hierarchy;

use App\Models\AssessmentCycle;
use App\Models\ComplianceNode;
use App\Models\ComplianceProgram;
use App\Models\Department;
use App\Models\ProgramUserRole;
use App\Models\User;
use App\Services\HierarchyDashboardService;
use App\Services\HierarchyDefinitionService;
use App\Services\WorkflowService;
use Database\Seeders\EmailTemplatesSeeder;
use Database\Seeders\QiyasProgramConfigurationSeeder;
use Database\Seeders\QiyasWorkflowDefinitionSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The dynamic dashboard: universal metrics that ignore depth entirely, and
 * drill-down driven by each program's own dashboard-visible levels.
 *
 * The depth-parameterised tests matter most here — a dashboard is exactly
 * the place a fixed-depth assumption hides, because it is easy to write
 * "group by level 1, then level 2" and never notice a program has six.
 */
class HierarchyDashboardTest extends TestCase
{
    use RefreshDatabase;

    private ComplianceProgram $program;

    private User $admin;

    private HierarchyDefinitionService $structures;

    private HierarchyDashboardService $dashboard;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(EmailTemplatesSeeder::class);

        $this->program = ComplianceProgram::where('code', 'QIYAS')->firstOrFail();
        $this->seed(QiyasWorkflowDefinitionSeeder::class);
        $this->seed(QiyasProgramConfigurationSeeder::class);

        $this->admin = $this->makeUser('super-admin');
        $this->structures = app(HierarchyDefinitionService::class);
        $this->dashboard = app(HierarchyDashboardService::class);
    }

    /**
     * Builds an N-level structure where every level is dashboard-visible and
     * the deepest is assessable, then one linear node chain.
     *
     * @return array{cycle:AssessmentCycle,nodes:array<int,ComplianceNode>}
     */
    private function buildProgram(int $depth): array
    {
        $levels = [];
        for ($i = 1; $i <= $depth; $i++) {
            $levels[] = [
                'key' => "level_{$i}",
                'name_ar' => "المستوى {$i}",
                'name_en' => "Level {$i}",
                'is_assessable' => $i === $depth,
                'is_assignable' => $i === $depth,
                'accepts_evidence' => $i === $depth,
                'appears_in_dashboard' => true,
                'appears_in_reports' => true,
                'appears_in_filters' => true,
            ];
        }
        $this->activateStructure($this->program, $levels, $this->admin);

        $cycle = AssessmentCycle::create([
            'compliance_program_id' => $this->program->id,
            'structure_version_id' => $this->structures->currentStructureVersion($this->program)?->id,
            'name' => 'دورة', 'year' => 2026,
            'start_date' => now()->subMonth(), 'end_date' => now()->addMonths(6),
            'status' => 'active', 'is_current' => true, 'created_by' => $this->admin->id,
        ]);

        $nodes = [];
        $parent = null;
        foreach ($this->structures->levels($this->program) as $index => $level) {
            $parent = ComplianceNode::create([
                'compliance_program_id' => $this->program->id,
                'program_cycle_id' => $cycle->id,
                'hierarchy_level_id' => $level->id,
                'parent_id' => $parent?->id,
                'node_type' => $level->key, 'level' => $index,
                'code' => 'N'.($index + 1), 'name_ar' => "عقدة {$index}",
                'created_by' => $this->admin->id,
            ]);
            $nodes[] = $parent;
        }

        return ['cycle' => $cycle, 'nodes' => $nodes];
    }

    // ─── Universal metrics ───────────────────────────────────────────────────

    /**
     * The metric SET must be identical regardless of depth — that is what
     * makes the executive dashboard able to compare programs.
     */
    #[DataProvider('depths')]
    public function test_universal_metrics_have_the_same_shape_at_every_depth(int $depth): void
    {
        $this->buildProgram($depth);

        $metrics = $this->dashboard->universalMetrics($this->program);

        $this->assertSame(
            HierarchyDashboardService::SUPPORTED_METRICS,
            array_keys($metrics),
            "A {$depth}-level program must expose exactly the universal metric set.",
        );
        // One assessable leaf in the chain, regardless of how deep it sits.
        $this->assertSame(1, $metrics['count_assessable']);
        $this->assertSame(0, $metrics['count_assigned']);
        $this->assertSame(1, $metrics['count_unassigned']);
    }

    public static function depths(): array
    {
        return ['3 levels' => [3], '5 levels' => [5], '7 levels' => [7], '8 levels' => [8]];
    }

    public function test_universal_metrics_never_name_a_hierarchy_level(): void
    {
        $this->buildProgram(6);

        $keys = implode(' ', array_keys($this->dashboard->universalMetrics($this->program)));

        foreach (['perspective', 'axis', 'domain', 'policy', 'control', 'level_'] as $term) {
            $this->assertStringNotContainsString($term, $keys);
        }
    }

    public function test_metrics_follow_the_workflow(): void
    {
        ['nodes' => $nodes] = $this->buildProgram(4);
        $leaf = end($nodes);

        $department = $this->makeDepartment('Dept A');
        $employee = $this->makeUser('employee', $department->id);
        ProgramUserRole::create([
            'compliance_program_id' => $this->program->id, 'user_id' => $employee->id,
            'role_key' => 'employee', 'department_id' => $department->id, 'is_active' => true,
        ]);

        app(WorkflowService::class)->assign(
            $leaf, $this->program, $this->admin, $department, $employee,
            now()->addMonth()->toDateString(), null, null, null,
        );

        $metrics = $this->dashboard->universalMetrics($this->program);
        $this->assertSame(1, $metrics['count_assigned']);
        $this->assertSame(0, $metrics['count_unassigned']);
    }

    // ─── Drill-down ──────────────────────────────────────────────────────────

    /**
     * The whole drill path must be walkable from metadata alone, to the
     * configured depth — no client or server knowledge of level names.
     */
    #[DataProvider('depths')]
    public function test_drill_down_follows_the_structure_to_its_full_depth(int $depth): void
    {
        $this->buildProgram($depth);

        $levels = $this->dashboard->dashboardLevels($this->program);
        $this->assertCount($depth, $levels, 'Every level was marked dashboard-visible.');

        $visited = [];
        $level = $levels[0];
        $nodeId = null;

        while ($level) {
            $result = $this->dashboard->groupByLevel($this->program, $level, null, $nodeId);
            $visited[] = $result['level']['key'];

            $this->assertNotEmpty($result['rows'], "Level {$level->key} must report at least one group.");
            $nodeId = $result['rows'][0]['node']['id'];

            $next = $result['next_level'];
            $level = $next ? $this->structures->levelByKey($this->program, $next['key']) : null;
        }

        $this->assertCount($depth, $visited, 'The drill path must reach every dashboard level.');
    }

    public function test_a_level_hidden_from_the_dashboard_is_skipped_in_the_drill_path(): void
    {
        $this->buildProgram(4);

        // The Program Manager hides level 2 from the dashboard.
        $draft = $this->structures->openDraft($this->program, $this->admin);
        $second = $draft->levels()->get()->firstWhere('key', 'level_2');
        $this->structures->updateLevel($second, ['appears_in_dashboard' => false], $this->admin);
        $this->structures->activate($draft->fresh(), $this->admin, acknowledgeMigration: true);

        $keys = collect($this->dashboard->dashboardLevels($this->program))->pluck('key')->all();
        $this->assertSame(['level_1', 'level_3', 'level_4'], $keys);

        // …and the drill path follows the new configuration immediately.
        $first = $this->structures->levelByKey($this->program, 'level_1');
        $result = $this->dashboard->groupByLevel($this->program, $first);
        $this->assertSame('level_3', $result['next_level']['key']);
    }

    public function test_drilling_narrows_the_counts_to_the_subtree(): void
    {
        ['cycle' => $cycle] = $this->buildProgram(3);
        $levels = $this->structures->levels($this->program);
        $root = ComplianceNode::where('hierarchy_level_id', $levels[0]->id)->firstOrFail();

        // A second branch under the same root, so narrowing is observable.
        $mid = ComplianceNode::create([
            'compliance_program_id' => $this->program->id, 'program_cycle_id' => $cycle->id,
            'hierarchy_level_id' => $levels[1]->id, 'parent_id' => $root->id,
            'node_type' => $levels[1]->key, 'level' => 1, 'code' => 'N2b', 'name_ar' => 'فرع',
        ]);
        ComplianceNode::create([
            'compliance_program_id' => $this->program->id, 'program_cycle_id' => $cycle->id,
            'hierarchy_level_id' => $levels[2]->id, 'parent_id' => $mid->id,
            'node_type' => $levels[2]->key, 'level' => 2, 'code' => 'N3b', 'name_ar' => 'ورقة',
        ]);

        $this->assertSame(2, $this->dashboard->universalMetrics($this->program)['count_assessable']);
        $this->assertSame(1, $this->dashboard->universalMetrics($this->program, null, $mid->id)['count_assessable']);
    }

    // ─── API surface and security ────────────────────────────────────────────

    public function test_the_api_exposes_levels_metrics_and_drill_down(): void
    {
        $this->buildProgram(5);
        $pm = $this->makeUser('employee');
        ProgramUserRole::create([
            'compliance_program_id' => $this->program->id, 'user_id' => $pm->id,
            'role_key' => 'program-manager', 'is_active' => true,
        ]);

        $this->getJson('/api/v1/programs/QIYAS/dashboard/levels', $this->authHeader($pm))
            ->assertOk()->assertJsonCount(5, 'data');

        $this->getJson('/api/v1/programs/QIYAS/dashboard/metrics', $this->authHeader($pm))
            ->assertOk()->assertJsonPath('data.metrics.count_assessable', 1);

        $this->getJson('/api/v1/programs/QIYAS/dashboard/by-level/level_1', $this->authHeader($pm))
            ->assertOk()
            ->assertJsonPath('data.level.key', 'level_1')
            ->assertJsonPath('data.next_level.key', 'level_2');
    }

    public function test_a_level_not_enabled_for_the_dashboard_is_refused(): void
    {
        $this->buildProgram(3);
        $draft = $this->structures->openDraft($this->program, $this->admin);
        $this->structures->updateLevel(
            $draft->levels()->get()->firstWhere('key', 'level_2'),
            ['appears_in_dashboard' => false],
            $this->admin,
        );
        $this->structures->activate($draft->fresh(), $this->admin, acknowledgeMigration: true);

        $this->getJson('/api/v1/programs/QIYAS/dashboard/by-level/level_2', $this->authHeader($this->admin))
            ->assertStatus(422);
    }

    public function test_a_node_from_another_program_cannot_widen_the_scope(): void
    {
        $this->buildProgram(3);

        $other = ComplianceProgram::create([
            'code' => 'OTHER', 'name_ar' => 'آخر', 'name_en' => 'Other',
            'status' => 'active', 'is_active' => true,
        ]);
        $foreignNode = ComplianceNode::create([
            'compliance_program_id' => $other->id,
            'node_type' => 'x', 'level' => 0, 'code' => 'X1', 'name_ar' => 'أجنبي',
        ]);

        // Passing a foreign node id must scope to nothing, never to everything.
        $metrics = $this->dashboard->universalMetrics($this->program, null, $foreignNode->id);
        $this->assertSame(0, $metrics['count_assessable']);

        $this->getJson("/api/v1/programs/QIYAS/dashboard/metrics?node_id={$foreignNode->id}", $this->authHeader($this->admin))
            ->assertOk()
            // The foreign id is ignored rather than honoured, so the response
            // is the unfiltered program view, not another program's data.
            ->assertJsonPath('data.metrics.count_assessable', 1);
    }

    public function test_an_employee_only_sees_their_own_department(): void
    {
        ['nodes' => $nodes] = $this->buildProgram(3);
        $leaf = end($nodes);

        $deptA = $this->makeDepartment('Dept A');
        $deptB = $this->makeDepartment('Dept B');
        $employeeB = $this->makeUser('employee', $deptB->id);
        ProgramUserRole::create([
            'compliance_program_id' => $this->program->id, 'user_id' => $employeeB->id,
            'role_key' => 'employee', 'department_id' => $deptB->id, 'is_active' => true,
        ]);

        app(WorkflowService::class)->assign(
            $leaf, $this->program, $this->admin, $deptA, null,
            now()->addMonth()->toDateString(), null, null, null,
        );

        // Assigned to Department A, so Department B's employee sees none of it.
        $this->assertSame(1, $this->dashboard->universalMetrics($this->program, null, null, $this->admin)['count_assigned']);
        $this->assertSame(0, $this->dashboard->universalMetrics($this->program, null, null, $employeeB)['count_assigned']);
    }
}
