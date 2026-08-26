<?php

namespace Database\Seeders;

use App\Models\AssessmentCycle;
use App\Models\ComplianceNode;
use App\Models\ComplianceProgram;
use App\Models\Department;
use App\Models\User;
use App\Services\HierarchyDefinitionService;
use Database\Seeders\Concerns\NonProductionSeeder;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Development/testing content for every compliance program, built ENTIRELY
 * from each program's own hierarchy definition.
 *
 * This class contains no program name, no level key and no depth. It reads
 * whatever structure a program has — Sumoud's three levels, NDMO's six —
 * and builds a tree to match. That is the point: it replaces the four
 * hand-written, program-specific sample seeders that previously encoded
 * ECC's and NDMO's shapes in PHP (audit finding M3, "program-specific
 * duplicated code"). If this seeder ever needed a per-program branch, the
 * engine would not be generic.
 *
 * All content is clearly marked as development test data and is never
 * presented as official regulatory content from any authority.
 */
class HierarchyContentSeeder extends Seeder
{
    use NonProductionSeeder;

    /**
     * Children created per parent, by depth. Tapering keeps a six-level
     * tree at a sane size while still branching near the root where
     * dashboards aggregate.
     */
    private const FANOUT = [2, 2, 2, 1, 1, 1, 1, 1, 1, 1, 1, 1];

    public function run(): void
    {
        $this->guardAgainstProduction();

        $actor = User::where('username', 'superadmin')->first();
        if (! $actor) {
            return;
        }

        $structures = app(HierarchyDefinitionService::class);

        foreach (ComplianceProgram::orderBy('code')->get() as $program) {
            $levels = collect($structures->levels($program));
            if ($levels->isEmpty()) {
                $this->command?->warn("  {$program->code}: no active structure — skipped.");

                continue;
            }

            $cycle = $this->cycleFor($program, $actor, $structures);
            $created = $this->buildTree($program, $cycle, $levels, $actor);

            $this->command?->info("  {$program->code}: {$created} node(s) across {$levels->count()} level(s).");
        }
    }

    private function cycleFor(ComplianceProgram $program, User $actor, HierarchyDefinitionService $structures): AssessmentCycle
    {
        $existing = AssessmentCycle::where('compliance_program_id', $program->id)->where('is_current', true)->first();
        if ($existing) {
            return $existing;
        }

        return AssessmentCycle::create([
            'compliance_program_id' => $program->id,
            // Pins the cycle to the structure in force right now, so later
            // renames never rewrite this cycle's reporting (finding C5).
            'structure_version_id' => $structures->currentStructureVersion($program)?->id,
            'name' => "دورة {$program->code} التجريبية 2026",
            'year' => 2026,
            'start_date' => now()->startOfYear()->toDateString(),
            'end_date' => now()->endOfYear()->toDateString(),
            'status' => 'active',
            'is_current' => true,
            'created_by' => $actor->id,
        ]);
    }

    /** @return int nodes created */
    private function buildTree(ComplianceProgram $program, AssessmentCycle $cycle, $levels, User $actor): int
    {
        $versionId = app(HierarchyDefinitionService::class)->currentStructureVersion($program)?->id;
        $count = 0;

        return DB::transaction(function () use ($program, $cycle, $levels, $actor, $versionId, &$count) {
            // Breadth-first: every node of level N is created before level N+1,
            // so a parent always exists when its children are built.
            $parents = [null];

            foreach ($levels->values() as $depth => $level) {
                $fanout = self::FANOUT[$depth] ?? 1;
                $next = [];

                foreach ($parents as $parent) {
                    for ($i = 1; $i <= $fanout; $i++) {
                        $code = $parent
                            ? "{$parent->code}.{$i}"
                            : "{$program->code}-".str_pad((string) $i, 2, '0', STR_PAD_LEFT);

                        $node = ComplianceNode::create([
                            'compliance_program_id' => $program->id,
                            'program_cycle_id' => $cycle->id,
                            'hierarchy_level_id' => $level->id,
                            'structure_version_id' => $versionId,
                            'parent_id' => $parent?->id,
                            'node_type' => $level->key,
                            'level' => $depth,
                            'code' => $code,
                            'name_ar' => "{$level->name_ar} تجريبي {$code}",
                            'name_en' => "Test {$level->name_en} {$code}",
                            'description_ar' => $level->description_enabled ? "وصف تجريبي لـ {$code}" : null,
                            'objective_ar' => $level->objective_enabled ? "هدف تجريبي لـ {$code}" : null,
                            'guidance_ar' => $level->instructions_enabled ? "إرشادات تجريبية لـ {$code}" : null,
                            'weight' => $level->weight_enabled ? 10 : null,
                            'default_due_date' => $level->due_date_enabled ? now()->addMonths(3)->toDateString() : null,
                            'sort_order' => $i,
                            'is_assessable' => $level->is_assessable,
                            'status' => 'active',
                            'created_by' => $actor->id,
                            'updated_by' => $actor->id,
                        ]);

                        $count++;
                        $next[] = $node;
                    }
                }

                $parents = $next;
            }

            return $count;
        });
    }

    /**
     * The first department, used by the companion workflow seeder. Kept here
     * so both read the same notion of "a department that exists".
     */
    public static function defaultDepartment(): ?Department
    {
        return Department::active()->orderBy('id')->first();
    }
}
