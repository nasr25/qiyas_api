<?php

namespace Database\Seeders;

use App\Models\AssessmentCycle;
use App\Models\ComplianceNode;
use App\Models\ComplianceProgram;
use App\Models\Department;
use App\Models\EvidenceSubmission;
use App\Models\ProgramUserRole;
use App\Models\RequirementAssignment;
use App\Models\SlaInstance;
use App\Models\SlaSetting;
use App\Models\User;
use App\Services\HierarchyDefinitionService;
use Database\Seeders\Concerns\NonProductionSeeder;
use Illuminate\Database\Seeder;

/**
 * Performance dataset: three programs at 3, 5 and 7 levels totalling well
 * over 5,000 compliance nodes, with departments, users, multiple cycles,
 * assignments, evidence submissions and SLA rows.
 *
 * Written with bulk inserts rather than the model layer: the point is to
 * produce a realistic READ workload to measure against, not to exercise the
 * write path (which the functional suites already cover). Building 5,000+
 * nodes through Eloquent one at a time would take minutes and measure
 * nothing useful.
 */
class PerformanceFixtureSeeder extends Seeder
{
    use NonProductionSeeder;

    /** depth => [code, fanout per level] — tuned to clear 5,000 nodes total. */
    private const PROGRAMS = [
        3 => ['PERF3', [12, 12, 12]],
        5 => ['PERF5', [6, 5, 4, 3, 3]],
        7 => ['PERF7', [4, 3, 3, 2, 2, 2, 2]],
    ];

    private const CYCLES_PER_PROGRAM = 2;

    public function run(): void
    {
        $this->guardAgainstProduction();

        $admin = User::where('username', 'superadmin')->first();
        if (! $admin) {
            return;
        }

        $departments = $this->departments();
        $structures = app(HierarchyDefinitionService::class);

        foreach (self::PROGRAMS as $depth => [$code, $fanout]) {
            $program = ComplianceProgram::firstOrCreate(
                ['code' => $code],
                [
                    'name_ar' => "برنامج قياس الأداء ({$depth})",
                    'name_en' => "Performance Fixture ({$depth} levels)",
                    'status' => 'active', 'is_active' => true, 'created_by' => $admin->id,
                ],
            );

            if (! $structures->activeDefinition($program)) {
                $draft = $structures->openDraft($program, $admin);
                for ($i = 1; $i <= $depth; $i++) {
                    $isLeaf = $i === $depth;
                    $structures->addLevel($draft, [
                        'key' => "level_{$i}", 'name_ar' => "المستوى {$i}", 'name_en' => "Level {$i}",
                        'is_required' => true,
                        'is_assignable' => $isLeaf, 'is_assessable' => $isLeaf, 'accepts_evidence' => $isLeaf,
                        'appears_in_dashboard' => ! $isLeaf, 'appears_in_reports' => true,
                        'appears_in_filters' => ! $isLeaf, 'appears_in_breadcrumb' => true,
                    ], $admin);
                }
                $structures->activate($draft->fresh(), $admin);
            }

            SlaSetting::firstOrCreate(['compliance_program_id' => $program->id]);
            $this->users($program, $code, $departments, $admin);

            for ($c = 1; $c <= self::CYCLES_PER_PROGRAM; $c++) {
                $cycle = AssessmentCycle::firstOrCreate(
                    ['compliance_program_id' => $program->id, 'name' => "{$code} Cycle {$c}"],
                    [
                        'structure_version_id' => $structures->currentStructureVersion($program)?->id,
                        'year' => 2025 + $c,
                        'start_date' => now()->subYear()->toDateString(),
                        'end_date' => now()->addYear()->toDateString(),
                        'status' => $c === 1 ? 'active' : 'closed',
                        'is_current' => $c === 1,
                        'created_by' => $admin->id,
                    ],
                );

                if (ComplianceNode::where('program_cycle_id', $cycle->id)->exists()) {
                    continue;
                }

                $leafIds = $this->bulkTree($program, $cycle, $structures, $admin, $fanout);
                $this->bulkWorkload($program, $cycle, $leafIds, $departments, $admin);
            }

            $this->command?->info(sprintf('  %s: %s node(s).', $code,
                number_format(ComplianceNode::where('compliance_program_id', $program->id)->count())));
        }

        $this->command?->info(sprintf('  TOTAL nodes: %s', number_format(ComplianceNode::count())));
    }

    /** @return array<int, Department> */
    private function departments(): array
    {
        $departments = [];
        foreach (['Perf Alpha', 'Perf Beta', 'Perf Gamma', 'Perf Delta'] as $name) {
            $departments[] = Department::firstOrCreate(['name_en' => $name],
                ['name_ar' => $name, 'is_active' => true]);
        }

        return $departments;
    }

    private function users(ComplianceProgram $program, string $code, array $departments, User $admin): void
    {
        $prefix = strtolower($code);
        foreach ([['pm', 'qiyas-admin', 'program-manager', null], ['auditor', 'auditor', 'auditor', null]] as [$suffix, $role, $programRole, $dept]) {
            $user = User::firstOrCreate(['username' => "{$prefix}_{$suffix}"], [
                'name' => "{$code} {$suffix}", 'email' => "{$prefix}_{$suffix}@qiyas.local",
                'password' => 'Password123!', 'auth_type' => 'local', 'is_active' => true,
                'must_change_password' => false, 'locale' => 'ar',
            ]);
            if (! $user->hasRole($role)) {
                $user->assignRole($role);
            }
            ProgramUserRole::firstOrCreate(
                ['compliance_program_id' => $program->id, 'user_id' => $user->id, 'role_key' => $programRole],
                ['is_active' => true, 'assigned_at' => now()],
            );
        }

        foreach ($departments as $i => $department) {
            $user = User::firstOrCreate(['username' => "{$prefix}_emp_{$i}"], [
                'name' => "{$code} employee {$i}", 'email' => "{$prefix}_emp_{$i}@qiyas.local",
                'password' => 'Password123!', 'auth_type' => 'local',
                'department_id' => $department->id, 'is_active' => true,
                'must_change_password' => false, 'locale' => 'ar',
            ]);
            if (! $user->hasRole('employee')) {
                $user->assignRole('employee');
            }
            ProgramUserRole::firstOrCreate(
                ['compliance_program_id' => $program->id, 'user_id' => $user->id, 'role_key' => 'employee'],
                ['department_id' => $department->id, 'is_active' => true, 'assigned_at' => now()],
            );
        }
    }

    /** Bulk-inserts the tree level by level; returns leaf node ids. */
    private function bulkTree(ComplianceProgram $program, AssessmentCycle $cycle, HierarchyDefinitionService $structures, User $admin, array $fanout): array
    {
        $levels = collect($structures->levels($program))->values();
        $versionId = $structures->currentStructureVersion($program)?->id;
        $now = now();

        $parents = [['id' => null, 'code' => $program->code]];

        foreach ($levels as $depth => $level) {
            $rows = [];
            foreach ($parents as $parent) {
                for ($i = 1; $i <= ($fanout[$depth] ?? 1); $i++) {
                    $rows[] = [
                        'compliance_program_id' => $program->id,
                        'program_cycle_id' => $cycle->id,
                        'hierarchy_level_id' => $level->id,
                        'structure_version_id' => $versionId,
                        'parent_id' => $parent['id'],
                        'node_type' => $level->key,
                        'level' => $depth,
                        'code' => "{$parent['code']}.{$i}",
                        'name_ar' => "عقدة {$parent['code']}.{$i}",
                        'name_en' => "Node {$parent['code']}.{$i}",
                        'sort_order' => $i,
                        'is_assessable' => $level->is_assessable,
                        'status' => 'active',
                        'created_by' => $admin->id, 'updated_by' => $admin->id,
                        'created_at' => $now, 'updated_at' => $now,
                    ];
                }
            }

            foreach (array_chunk($rows, 500) as $chunk) {
                ComplianceNode::insert($chunk);
            }

            // Re-read this level's ids to parent the next one.
            $parents = ComplianceNode::where('program_cycle_id', $cycle->id)
                ->where('hierarchy_level_id', $level->id)
                ->get(['id', 'code'])
                ->map(fn ($n) => ['id' => $n->id, 'code' => $n->code])->all();
        }

        return array_column($parents, 'id');
    }

    /** Assignments + evidence + SLA rows over a slice of the leaves. */
    private function bulkWorkload(ComplianceProgram $program, AssessmentCycle $cycle, array $leafIds, array $departments, User $admin): void
    {
        $now = now();
        // Half the leaves get workload — enough volume to measure, while
        // leaving unassigned items so "count_unassigned" is non-trivial.
        $target = array_slice($leafIds, 0, (int) ceil(count($leafIds) / 2));

        $assignments = [];
        foreach ($target as $i => $leafId) {
            $assignments[] = [
                'compliance_program_id' => $program->id,
                'program_cycle_id' => $cycle->id,
                'compliance_node_id' => $leafId,
                'department_id' => $departments[$i % count($departments)]->id,
                'employee_id' => null,
                'assigned_by' => $admin->id,
                'assigned_at' => $now,
                'original_due_date' => $now->copy()->addMonths(2)->toDateString(),
                'effective_due_date' => $now->copy()->addMonths(2)->toDateString(),
                'status' => 'active',
                'priority' => 'normal',
                'created_at' => $now, 'updated_at' => $now,
            ];
        }
        foreach (array_chunk($assignments, 500) as $chunk) {
            RequirementAssignment::insert($chunk);
        }

        $assignmentRows = RequirementAssignment::where('program_cycle_id', $cycle->id)
            ->get(['id', 'compliance_node_id', 'department_id']);

        $statuses = ['draft', 'pending_department_manager', 'pending_auditor', 'pending_program_manager', 'approved'];
        $submissions = [];
        $slaRows = [];
        foreach ($assignmentRows as $i => $assignment) {
            $status = $statuses[$i % count($statuses)];
            $submissions[] = [
                'compliance_program_id' => $program->id,
                'program_cycle_id' => $cycle->id,
                'compliance_node_id' => $assignment->compliance_node_id,
                'requirement_assignment_id' => $assignment->id,
                'department_id' => $assignment->department_id,
                'submitted_by' => $admin->id,
                'version_number' => 1,
                'status' => $status,
                'current_stage' => $status === 'approved' ? 'completed' : 'employee',
                'created_at' => $now, 'updated_at' => $now,
            ];
            $slaRows[] = [
                'compliance_program_id' => $program->id,
                'program_cycle_id' => $cycle->id,
                'requirement_assignment_id' => $assignment->id,
                'stage' => 'employee',
                'responsible_department_id' => $assignment->department_id,
                'started_at' => $now,
                'due_at' => $now->copy()->addDays(($i % 20) - 5),
                'status' => $i % 7 === 0 ? 'breached' : 'active',
                // NOT NULL with no default: SlaService normally stamps the
                // settings in force when the instance opened.
                'settings_snapshot' => '{}',
                'created_at' => $now, 'updated_at' => $now,
            ];
        }
        foreach (array_chunk($submissions, 500) as $chunk) {
            EvidenceSubmission::insert($chunk);
        }
        foreach (array_chunk($slaRows, 500) as $chunk) {
            SlaInstance::insert($chunk);
        }
    }
}
