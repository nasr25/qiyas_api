<?php

namespace Database\Seeders;

use App\Models\AssessmentCycle;
use App\Models\ComplianceNode;
use App\Models\ComplianceProgram;
use App\Models\Department;
use App\Models\ProgramUserRole;
use App\Models\SlaSetting;
use App\Models\User;
use App\Models\WorkflowDefinition;
use App\Services\HierarchyDefinitionService;
use App\Services\ProgramConfigurationService;
use App\Services\WorkflowService;
use Database\Seeders\Concerns\NonProductionSeeder;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Three isolated test programs at 3, 5 and 7 levels, used by the Playwright
 * suites and the depth-independence proofs.
 *
 * The point of these fixtures is negative evidence: they exist to show the
 * engine needs NO program-specific code. Every program below is built by
 * the same loop from nothing but a depth number — same structure service,
 * same node model, same workflow engine, same XLSX contract. If a future
 * change made any of them require a special case, this seeder would be
 * where that becomes obvious.
 *
 * Clearly marked TEST3 / TEST5 / TEST7 and never presented as regulatory
 * content. Development and testing environments only.
 */
class TestHierarchyFixtureSeeder extends Seeder
{
    use NonProductionSeeder;

    /**
     * Depth => program code. Nothing else differs between them.
     *
     * TESTX is a fourth 5-level program reserved for tests that MUTATE a
     * structure (adding a level, superseding a version). Those tests would
     * otherwise poison the shared fixtures for every spec that runs after
     * them — which is exactly what happened before it existed.
     */
    public const FIXTURES = [3 => 'TEST3', 5 => 'TEST5', 7 => 'TEST7'];

    public const MUTABLE_FIXTURE = 'TESTX';

    public const PASSWORD = 'Password123!';

    public function run(): void
    {
        $this->guardAgainstProduction();

        $admin = User::where('username', 'superadmin')->first();
        if (! $admin) {
            $this->command?->warn('No superadmin — skipped.');

            return;
        }

        foreach (self::FIXTURES as $depth => $code) {
            $this->buildFixture($code, $depth, $admin);
        }

        // Same construction, different program — see MUTABLE_FIXTURE.
        $this->buildFixture(self::MUTABLE_FIXTURE, 5, $admin);
    }

    private function buildFixture(string $code, int $depth, User $admin): void
    {
        $program = ComplianceProgram::firstOrCreate(
            ['code' => $code],
            [
                'name_ar' => "برنامج اختبار ({$depth} مستويات)",
                'name_en' => "Test Program ({$depth} levels)",
                'description_en' => 'Development/testing fixture — not regulatory content.',
                'status' => 'active', 'is_active' => true,
                'created_by' => $admin->id, 'updated_by' => $admin->id,
            ],
        );

        $structures = app(HierarchyDefinitionService::class);

        if (! $structures->activeDefinition($program)) {
            $draft = $structures->openDraft($program, $admin);
            for ($i = 1; $i <= $depth; $i++) {
                $isLeaf = $i === $depth;
                // The three semantic levels the brief asks each fixture to
                // exercise land on distinct levels wherever depth allows:
                // assignable and assessable one above the leaf, evidence on
                // the leaf. At depth 3 the leaf carries all three.
                $structures->addLevel($draft, [
                    'key' => "level_{$i}",
                    'name_ar' => "المستوى {$i}",
                    'name_en' => "Level {$i}",
                    'plural_name_ar' => "المستويات {$i}",
                    'plural_name_en' => "Level {$i}s",
                    'is_required' => true,
                    'is_assignable' => $isLeaf,
                    'is_assessable' => $isLeaf,
                    'accepts_evidence' => $isLeaf,
                    'appears_in_dashboard' => ! $isLeaf,
                    'appears_in_reports' => true,
                    'appears_in_filters' => ! $isLeaf,
                    'appears_in_breadcrumb' => true,
                    'weight_enabled' => $isLeaf,
                    'due_date_enabled' => $isLeaf,
                    'instructions_enabled' => $isLeaf,
                    'objective_enabled' => $isLeaf,
                ], $admin);
            }
            $structures->activate($draft->fresh(), $admin);
        }

        $this->seedWorkflowDefinition($program);
        $this->seedConfiguration($program);

        $cycle = AssessmentCycle::firstOrCreate(
            ['compliance_program_id' => $program->id, 'is_current' => true],
            [
                'structure_version_id' => $structures->currentStructureVersion($program)?->id,
                'name' => "دورة {$code} 2026", 'year' => 2026,
                'start_date' => now()->startOfYear()->toDateString(),
                'end_date' => now()->endOfYear()->toDateString(),
                'status' => 'active', 'created_by' => $admin->id,
            ],
        );

        $departments = $this->departmentsFor($code);
        $users = $this->usersFor($program, $code, $departments);

        $leaves = $this->buildTree($program, $cycle, $structures, $admin);
        $this->exerciseWorkflow($program, $leaves, $departments, $users);

        $this->command?->info(sprintf(
            '  %s: %d level(s), %d node(s), %d leaf assignment(s).',
            $code, $depth, ComplianceNode::where('compliance_program_id', $program->id)->count(), count($leaves),
        ));
    }

    /** Identical workflow shape for every fixture — copied from no program. */
    private function seedWorkflowDefinition(ComplianceProgram $program): void
    {
        $definition = WorkflowDefinition::updateOrCreate(
            ['compliance_program_id' => $program->id, 'key' => 'requirement_review'],
            ['name_ar' => 'مراجعة', 'name_en' => 'Review', 'version' => 1, 'is_active' => true],
        );

        foreach ([
            ['stage_key' => 'employee', 'sort_order' => 0, 'name_ar' => 'الموظف', 'name_en' => 'Employee', 'responsible_role_key' => 'employee', 'requires_rejection_reason' => false, 'sla_applies' => true, 'notifications_enabled' => true, 'is_final' => false],
            ['stage_key' => 'department_manager', 'sort_order' => 1, 'name_ar' => 'مدير الإدارة', 'name_en' => 'Department Manager', 'responsible_role_key' => 'department-manager', 'requires_rejection_reason' => true, 'sla_applies' => true, 'notifications_enabled' => true, 'is_final' => false],
            ['stage_key' => 'auditor', 'sort_order' => 2, 'name_ar' => 'المدقق', 'name_en' => 'Auditor', 'responsible_role_key' => 'auditor', 'requires_rejection_reason' => true, 'sla_applies' => true, 'notifications_enabled' => true, 'is_final' => false],
            ['stage_key' => 'program_manager', 'sort_order' => 3, 'name_ar' => 'مدير البرنامج', 'name_en' => 'Program Manager', 'responsible_role_key' => 'program-manager', 'requires_rejection_reason' => true, 'sla_applies' => true, 'notifications_enabled' => true, 'is_final' => false],
            ['stage_key' => 'approved', 'sort_order' => 4, 'name_ar' => 'معتمد', 'name_en' => 'Approved', 'responsible_role_key' => null, 'requires_rejection_reason' => false, 'sla_applies' => false, 'notifications_enabled' => true, 'is_final' => true],
        ] as $stage) {
            $definition->stages()->updateOrCreate(['stage_key' => $stage['stage_key']], $stage + ['requires_comment' => false]);
        }

        foreach ([
            ['employee', 'submit', 'department_manager'],
            ['department_manager', 'approve', 'auditor'],
            ['department_manager', 'reject', 'employee'],
            ['auditor', 'approve', 'program_manager'],
            ['auditor', 'reject', 'employee'],
            ['program_manager', 'approve', 'approved'],
            ['program_manager', 'reject', 'employee'],
        ] as [$from, $action, $to]) {
            $definition->transitions()->updateOrCreate(
                ['from_stage_key' => $from, 'action' => $action],
                ['to_stage_key' => $to],
            );
        }

        // Every SLA column carries a schema default, so the fixture takes
        // the platform defaults rather than inventing its own numbers.
        SlaSetting::firstOrCreate(['compliance_program_id' => $program->id]);
    }

    private function seedConfiguration(ComplianceProgram $program): void
    {
        $config = app(ProgramConfigurationService::class);

        $config->set($program, 'terminology', [
            'domain' => ['ar' => 'المستوى الأول', 'en' => 'Level 1'],
            'category' => ['ar' => 'المستوى الثاني', 'en' => 'Level 2'],
            'requirement' => ['ar' => 'المتطلب', 'en' => 'Requirement'],
            'evidence' => ['ar' => 'مستند الإثبات', 'en' => 'Evidence Document'],
            'cycle' => ['ar' => 'دورة', 'en' => 'Cycle'],
        ]);
        $config->set($program, 'extensions', [
            'requester_role' => 'employee', 'reviewer_role' => 'auditor',
            'rejection_reason_required' => true, 'allow_multiple_pending' => false,
        ]);
        $config->set($program, 'evidence', [
            'allowed_extensions' => ['pdf', 'docx', 'xlsx', 'png', 'jpg'],
            'max_file_size_mb' => 20, 'max_files_per_submission' => 10,
            'max_total_submission_size_mb' => 100,
        ]);
        $config->set($program, 'assignment', [
            'department_required' => true, 'employee_assignment_required' => false,
            'reassignment_reason_required' => true, 'due_date_required' => false,
        ]);
    }

    /** @return array<int, Department> */
    private function departmentsFor(string $code): array
    {
        return [
            Department::firstOrCreate(['name_en' => "{$code} Department A"],
                ['name_ar' => "إدارة {$code} أ", 'is_active' => true]),
            Department::firstOrCreate(['name_en' => "{$code} Department B"],
                ['name_ar' => "إدارة {$code} ب", 'is_active' => true]),
        ];
    }

    /** @return array<string, User> */
    private function usersFor(ComplianceProgram $program, string $code, array $departments): array
    {
        $prefix = strtolower($code);
        $make = function (string $suffix, string $spatieRole, string $programRole, ?Department $department) use ($program, $prefix) {
            $user = User::firstOrCreate(
                ['username' => "{$prefix}_{$suffix}"],
                [
                    'name' => strtoupper($prefix)." {$suffix}",
                    'email' => "{$prefix}_{$suffix}@qiyas.local",
                    'password' => self::PASSWORD,
                    'auth_type' => 'local',
                    'department_id' => $department?->id,
                    'is_active' => true, 'must_change_password' => false, 'locale' => 'ar',
                ],
            );
            if (! $user->hasRole($spatieRole)) {
                $user->assignRole($spatieRole);
            }
            ProgramUserRole::firstOrCreate(
                ['compliance_program_id' => $program->id, 'user_id' => $user->id, 'role_key' => $programRole],
                ['department_id' => $department?->id, 'is_active' => true, 'assigned_at' => now()],
            );

            return $user;
        };

        return [
            'pm' => $make('pm', 'qiyas-admin', 'program-manager', null),
            'auditor' => $make('auditor', 'auditor', 'auditor', null),
            'dept_manager' => $make('dept_manager', 'coordinator', 'department-manager', $departments[0]),
            'employee' => $make('employee', 'employee', 'employee', $departments[0]),
            'employee_b' => $make('employee_b', 'employee', 'employee', $departments[1]),
        ];
    }

    /** Builds a small branching tree; returns the leaf nodes. */
    private function buildTree(ComplianceProgram $program, AssessmentCycle $cycle, HierarchyDefinitionService $structures, User $admin): array
    {
        if (ComplianceNode::where('compliance_program_id', $program->id)->exists()) {
            $levels = collect($structures->levels($program));

            return ComplianceNode::where('compliance_program_id', $program->id)
                ->where('hierarchy_level_id', $levels->last()->id)->get()->all();
        }

        $versionId = $structures->currentStructureVersion($program)?->id;

        return DB::transaction(function () use ($program, $cycle, $structures, $admin, $versionId) {
            $parents = [null];
            $levels = collect($structures->levels($program))->values();

            foreach ($levels as $depth => $level) {
                // Branch near the root only, so a 7-level tree stays small
                // enough for a browser test to walk end to end.
                $fanout = $depth < 2 ? 2 : 1;
                $next = [];
                foreach ($parents as $parent) {
                    for ($i = 1; $i <= $fanout; $i++) {
                        $code = $parent ? "{$parent->code}.{$i}" : "{$program->code}-{$i}";
                        $next[] = ComplianceNode::create([
                            'compliance_program_id' => $program->id,
                            'program_cycle_id' => $cycle->id,
                            'hierarchy_level_id' => $level->id,
                            'structure_version_id' => $versionId,
                            'parent_id' => $parent?->id,
                            'node_type' => $level->key,
                            'level' => $depth,
                            'code' => $code,
                            'name_ar' => "{$level->name_ar} {$code}",
                            'name_en' => "{$level->name_en} {$code}",
                            'objective_ar' => $level->objective_enabled ? "هدف {$code}" : null,
                            'guidance_ar' => $level->instructions_enabled ? "إرشادات {$code}" : null,
                            'weight' => $level->weight_enabled ? 10 : null,
                            'default_due_date' => $level->due_date_enabled ? now()->addMonths(3)->toDateString() : null,
                            'sort_order' => $i,
                            'is_assessable' => $level->is_assessable,
                            'status' => 'active',
                            'created_by' => $admin->id, 'updated_by' => $admin->id,
                        ]);
                    }
                }
                $parents = $next;
            }

            return $parents;
        });
    }

    /**
     * Drives assignments and evidence so dashboards, reports and review
     * queues all have data. Leaves are spread across both departments so
     * department-scope tests have something to isolate.
     */
    private function exerciseWorkflow(ComplianceProgram $program, array $leaves, array $departments, array $users): void
    {
        $workflow = app(WorkflowService::class);

        foreach (array_values($leaves) as $i => $leaf) {
            $department = $departments[$i % 2];
            $employee = $i % 2 === 0 ? $users['employee'] : $users['employee_b'];

            try {
                $assignment = $workflow->assign(
                    $leaf, $program, $users['pm'], $department, $employee,
                    now()->addMonths(2)->toDateString(), 'normal', null, null,
                );
            } catch (Throwable $e) {
                $this->command?->warn("  {$program->code} {$leaf->code}: {$e->getMessage()}");

                continue;
            }

            // Leave the first assignment of each department at draft so the
            // employee screens have something actionable; submit the rest so
            // review queues are populated too.
            if ($i >= 2) {
                try {
                    $submission = $workflow->getOrCreateDraft($assignment, $employee);
                    $workflow->submit($submission, $employee, null);
                } catch (Throwable) {
                    // A stage rule refused it; the assignment still stands.
                }
            }
        }
    }
}
