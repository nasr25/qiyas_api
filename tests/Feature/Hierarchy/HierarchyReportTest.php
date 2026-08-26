<?php

namespace Tests\Feature\Hierarchy;

use App\Models\AssessmentCycle;
use App\Models\ComplianceNode;
use App\Models\ComplianceProgram;
use App\Models\ProgramUserRole;
use App\Models\User;
use App\Services\HierarchyDefinitionService;
use App\Services\HierarchyReportService;
use App\Services\WorkflowService;
use Database\Seeders\EmailTemplatesSeeder;
use Database\Seeders\QiyasProgramConfigurationSeeder;
use Database\Seeders\QiyasWorkflowDefinitionSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Generic hierarchy reporting: group-by any reportable level, cascading
 * filters, and export columns that follow the program's own structure.
 *
 * Before this engine, reports could not group by ANY hierarchy level
 * (audit finding H2), so these tests establish the capability rather than
 * guard an existing one.
 */
class HierarchyReportTest extends TestCase
{
    use RefreshDatabase;

    private ComplianceProgram $program;

    private User $admin;

    private HierarchyDefinitionService $structures;

    private HierarchyReportService $reports;

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
        $this->reports = app(HierarchyReportService::class);
    }

    /** Builds an N-level structure and a branching tree, returning the leaves. */
    private function buildProgram(int $depth, int $branch = 2): array
    {
        $levels = [];
        for ($i = 1; $i <= $depth; $i++) {
            $levels[] = [
                'key' => "level_{$i}", 'name_ar' => "المستوى {$i}", 'name_en' => "Level {$i}",
                'is_assessable' => $i === $depth, 'is_assignable' => $i === $depth,
                'accepts_evidence' => $i === $depth,
                'appears_in_dashboard' => true, 'appears_in_reports' => true, 'appears_in_filters' => true,
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

        $parents = [null];
        $leaves = [];
        foreach ($this->structures->levels($this->program) as $index => $level) {
            $next = [];
            foreach ($parents as $parent) {
                for ($i = 1; $i <= $branch; $i++) {
                    $code = $parent ? "{$parent->code}.{$i}" : "N{$i}";
                    $next[] = ComplianceNode::create([
                        'compliance_program_id' => $this->program->id,
                        'program_cycle_id' => $cycle->id,
                        'hierarchy_level_id' => $level->id,
                        'parent_id' => $parent?->id,
                        'node_type' => $level->key, 'level' => $index,
                        'code' => $code, 'name_ar' => "عقدة {$code}",
                        'created_by' => $this->admin->id,
                    ]);
                }
            }
            $parents = $next;
            $leaves = $next;
        }

        return ['cycle' => $cycle, 'leaves' => $leaves];
    }

    private function assignAll(array $leaves): void
    {
        $department = $this->makeDepartment('Dept A');
        $workflow = app(WorkflowService::class);
        foreach ($leaves as $leaf) {
            $workflow->assign($leaf, $this->program, $this->admin, $department, null,
                now()->addMonth()->toDateString(), null, null, null);
        }
    }

    // ─── Dimensions ──────────────────────────────────────────────────────────

    #[DataProvider('depths')]
    public function test_every_reportable_level_becomes_a_group_by_dimension(int $depth): void
    {
        $this->buildProgram($depth, 1);

        $dimensions = collect($this->reports->availableDimensions($this->program));
        $hierarchy = $dimensions->where('type', 'hierarchy');

        $this->assertCount($depth, $hierarchy, "All {$depth} levels are reportable.");
        foreach (HierarchyReportService::OPERATIONAL_DIMENSIONS as $op) {
            $this->assertTrue($dimensions->contains('key', $op));
        }
    }

    public static function depths(): array
    {
        return ['3 levels' => [3], '5 levels' => [5], '7 levels' => [7], '8 levels' => [8]];
    }

    public function test_a_level_hidden_from_reports_is_not_a_dimension(): void
    {
        $this->buildProgram(3, 1);

        $draft = $this->structures->openDraft($this->program, $this->admin);
        $this->structures->updateLevel(
            $draft->levels()->get()->firstWhere('key', 'level_2'),
            ['appears_in_reports' => false],
            $this->admin,
        );
        $this->structures->activate($draft->fresh(), $this->admin, acknowledgeMigration: true);

        $keys = collect($this->reports->availableDimensions($this->program))->where('type', 'hierarchy')->pluck('key');
        $this->assertFalse($keys->contains('level_2'));
        $this->assertFalse($this->reports->isValidDimension($this->program, 'level_2'));
    }

    // ─── Grouping ────────────────────────────────────────────────────────────

    public function test_a_report_groups_by_several_hierarchy_levels_in_order(): void
    {
        ['leaves' => $leaves] = $this->buildProgram(4, 2);
        $this->assignAll($leaves);

        $report = $this->reports->build($this->program, ['level_1', 'level_2', 'level_3']);

        $this->assertSame(count($leaves), $report['row_count']);

        // Two branches at each of the three grouped levels.
        $l1 = $report['grouping']['groups'];
        $this->assertCount(2, $l1);
        $l2 = $l1[0]['groups'];
        $this->assertCount(2, $l2);
        $l3 = $l2[0]['groups'];
        $this->assertCount(2, $l3);
        // The innermost group holds the actual rows.
        $this->assertArrayHasKey('rows', $l3[0]);
        $this->assertSame('level_3', $l3[0]['dimension']);
    }

    public function test_grouping_by_the_deepest_level_of_a_seven_level_program_works(): void
    {
        ['leaves' => $leaves] = $this->buildProgram(7, 1);
        $this->assignAll($leaves);

        $report = $this->reports->build($this->program, ['level_7']);

        $this->assertSame(1, $report['row_count']);
        $this->assertSame('level_7', $report['grouping']['groups'][0]['dimension']);
    }

    public function test_group_counts_and_totals_add_up(): void
    {
        ['leaves' => $leaves] = $this->buildProgram(3, 2);
        $this->assignAll($leaves);

        $report = $this->reports->build($this->program, ['level_1']);
        $sum = collect($report['grouping']['groups'])->sum('count');

        $this->assertSame($report['row_count'], $sum);
        $this->assertSame($report['totals']['total'], $sum);
    }

    // ─── Cascading filters ───────────────────────────────────────────────────

    public function test_filter_options_cascade_down_the_whole_structure(): void
    {
        $this->buildProgram(5, 2);

        $parent = null;
        foreach ($this->structures->levels($this->program) as $level) {
            $options = $this->reports->filterOptions($this->program, $level, $parent);
            $this->assertNotEmpty($options, "Level {$level->key} must offer filter options.");

            // Each level offers exactly the children of the chosen parent.
            $this->assertCount($parent === null ? 2 : 2, $options);
            $parent = $options[0]['id'];
        }
    }

    public function test_filter_options_are_scoped_to_the_chosen_parent(): void
    {
        $this->buildProgram(3, 2);
        $levels = $this->structures->levels($this->program);

        $allSecond = $this->reports->filterOptions($this->program, $levels[1]);
        $this->assertCount(4, $allSecond, 'Two roots × two children.');

        $firstRoot = $this->reports->filterOptions($this->program, $levels[0])[0];
        $scoped = $this->reports->filterOptions($this->program, $levels[1], $firstRoot['id']);
        $this->assertCount(2, $scoped, 'Only the chosen root\'s children.');
    }

    // ─── Export columns ──────────────────────────────────────────────────────

    #[DataProvider('depths')]
    public function test_export_columns_expand_with_the_structure(int $depth): void
    {
        $this->buildProgram($depth, 1);

        $columns = $this->reports->columns($this->program);
        $hierarchyColumns = collect($columns)->where('type', 'hierarchy');

        // A code + name column per reportable level.
        $this->assertCount($depth * 2, $hierarchyColumns);
        $this->assertSame('level_1_code', $hierarchyColumns->first()['key']);
        $this->assertSame("level_{$depth}_name", $hierarchyColumns->last()['key']);
    }

    public function test_export_rows_carry_the_full_path_for_every_level(): void
    {
        ['leaves' => $leaves] = $this->buildProgram(6, 1);
        $this->assignAll($leaves);

        $columns = $this->reports->columns($this->program);
        $rows = $this->reports->exportRows($this->program);

        $this->assertCount(1, $rows);
        $this->assertCount(count($columns), $rows[0]);

        // Every hierarchy cell is populated — the old two-level mirror could
        // only ever have filled the first two (audit finding C2).
        $hierarchyCellCount = collect($columns)->where('type', 'hierarchy')->count();
        for ($i = 0; $i < $hierarchyCellCount; $i++) {
            $this->assertNotSame('', $rows[0][$i], "Hierarchy column {$i} must be populated.");
        }
    }

    // ─── API and security ────────────────────────────────────────────────────

    public function test_the_api_rejects_a_dimension_outside_the_whitelist(): void
    {
        $this->buildProgram(3, 1);

        $this->getJson('/api/v1/programs/QIYAS/reports/hierarchy?group_by[]=password', $this->authHeader($this->admin))
            ->assertStatus(422);

        $this->getJson('/api/v1/programs/QIYAS/reports/hierarchy?group_by[]=level_1', $this->authHeader($this->admin))
            ->assertOk();
    }

    public function test_a_level_not_enabled_as_a_filter_is_refused(): void
    {
        $this->buildProgram(3, 1);

        $draft = $this->structures->openDraft($this->program, $this->admin);
        $this->structures->updateLevel(
            $draft->levels()->get()->firstWhere('key', 'level_2'),
            ['appears_in_filters' => false],
            $this->admin,
        );
        $this->structures->activate($draft->fresh(), $this->admin, acknowledgeMigration: true);

        $this->getJson('/api/v1/programs/QIYAS/reports/filter-options/level_2', $this->authHeader($this->admin))
            ->assertStatus(422);
    }

    public function test_an_employee_report_is_limited_to_their_own_department(): void
    {
        ['leaves' => $leaves] = $this->buildProgram(3, 2);

        $deptA = $this->makeDepartment('Dept A');
        $deptB = $this->makeDepartment('Dept B');
        $workflow = app(WorkflowService::class);
        foreach ($leaves as $i => $leaf) {
            $workflow->assign($leaf, $this->program, $this->admin, $i === 0 ? $deptA : $deptB, null,
                now()->addMonth()->toDateString(), null, null, null);
        }

        $employeeA = $this->makeUser('employee', $deptA->id);
        ProgramUserRole::create([
            'compliance_program_id' => $this->program->id, 'user_id' => $employeeA->id,
            'role_key' => 'employee', 'department_id' => $deptA->id, 'is_active' => true,
        ]);

        $all = $this->reports->build($this->program, [], [], null, $this->admin);
        $mine = $this->reports->build($this->program, [], [], null, $employeeA);

        $this->assertSame(count($leaves), $all['row_count']);
        $this->assertSame(1, $mine['row_count'], 'An employee sees only their own department.');
    }

    public function test_a_hierarchy_filter_cannot_widen_department_scope(): void
    {
        ['leaves' => $leaves] = $this->buildProgram(3, 2);
        $deptA = $this->makeDepartment('Dept A');
        $deptB = $this->makeDepartment('Dept B');
        $workflow = app(WorkflowService::class);
        foreach ($leaves as $i => $leaf) {
            $workflow->assign($leaf, $this->program, $this->admin, $i === 0 ? $deptA : $deptB, null,
                now()->addMonth()->toDateString(), null, null, null);
        }

        $employeeA = $this->makeUser('employee', $deptA->id);
        ProgramUserRole::create([
            'compliance_program_id' => $this->program->id, 'user_id' => $employeeA->id,
            'role_key' => 'employee', 'department_id' => $deptA->id, 'is_active' => true,
        ]);

        // Filtering to the ROOT (everything) still yields only their own rows.
        $root = ComplianceNode::where('compliance_program_id', $this->program->id)
            ->whereNull('parent_id')->first();

        $filtered = $this->reports->build($this->program, [], ['node_id' => $root->id], null, $employeeA);
        $this->assertLessThanOrEqual(1, $filtered['row_count']);
    }
}
