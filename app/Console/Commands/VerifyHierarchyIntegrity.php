<?php

namespace App\Console\Commands;

use App\Models\ComplianceNode;
use App\Models\ComplianceProgram;
use App\Models\EvidenceSubmission;
use App\Models\HierarchyDefinition;
use App\Models\ProgramStructureVersion;
use App\Models\RequirementAssignment;
use App\Services\HierarchyDefinitionService;
use Illuminate\Console\Command;

/**
 * Read-only integrity report for the dynamic hierarchy engine. Never
 * writes. Covers every check the Phase B brief lists under
 * "Data-Integrity Command", plus the two defects this engine replaced
 * (audit findings C2 and C6: silent depth truncation in the standards
 * mirror, and the never-populated back-reference).
 *
 *   php artisan compliance:verify-hierarchy
 *   php artisan compliance:verify-hierarchy --program=NDMO
 */
class VerifyHierarchyIntegrity extends Command
{
    protected $signature = 'compliance:verify-hierarchy {--program= : Limit the report to one program code}';

    protected $description = 'Read-only integrity report for program hierarchy structures and nodes.';

    private int $problems = 0;

    public function handle(HierarchyDefinitionService $structures): int
    {
        $this->info('Compliance hierarchy integrity verification');
        $this->line('(read-only — no data is modified by this command)');
        $this->newLine();

        $query = ComplianceProgram::query()->orderBy('code');
        if ($code = $this->option('program')) {
            $query->where('code', strtoupper($code));
        }
        $programs = $query->get();

        if ($programs->isEmpty()) {
            $this->error('No matching compliance program found.');

            return self::FAILURE;
        }

        foreach ($programs as $program) {
            $this->reportProgram($program, $structures);
        }

        $this->newLine();
        if ($this->problems === 0) {
            $this->info('All hierarchy integrity checks passed.');

            return self::SUCCESS;
        }

        $this->error("{$this->problems} hierarchy integrity problem(s) found.");

        return self::FAILURE;
    }

    private function reportProgram(ComplianceProgram $program, HierarchyDefinitionService $structures): void
    {
        $this->comment("── {$program->code} — {$program->name_en}");

        $definition = $structures->activeDefinition($program);
        $structureVersion = $structures->currentStructureVersion($program);
        $levels = $definition ? $definition->levels()->get() : collect();
        $activeLevels = $levels->where('is_active', true);

        $this->table(['Metric', 'Value'], [
            ['Active structure definition', $definition ? "v{$definition->version}" : '— none —'],
            ['Active structure version snapshot', $structureVersion ? "v{$structureVersion->version}" : '— none —'],
            ['Levels defined', $levels->count()],
            ['Levels enabled', $activeLevels->count()],
            ['Assignable levels', $activeLevels->where('is_assignable', true)->pluck('key')->implode(', ') ?: '—'],
            ['Assessable levels', $activeLevels->where('is_assessable', true)->pluck('key')->implode(', ') ?: '—'],
            ['Evidence-bearing levels', $activeLevels->where('accepts_evidence', true)->pluck('key')->implode(', ') ?: '—'],
            ['Dashboard levels', $activeLevels->where('appears_in_dashboard', true)->pluck('key')->implode(', ') ?: '—'],
            ['Report levels', $activeLevels->where('appears_in_reports', true)->pluck('key')->implode(', ') ?: '—'],
            ['Nodes', ComplianceNode::where('compliance_program_id', $program->id)->count()],
        ]);

        if (! $definition) {
            $this->check('Program has an active hierarchy definition', 1, 'no active definition');
            $this->newLine();

            return;
        }

        // ─── Structure-level checks ──────────────────────────────────────
        $this->check(
            'Exactly one active definition',
            max(0, HierarchyDefinition::forProgram($program)->active()->count() - 1),
        );
        $this->check('Structure snapshot exists for active definition', $structureVersion ? 0 : 1);
        $this->check(
            'Exactly one active structure version',
            max(0, ProgramStructureVersion::forProgram($program)->active()->count() - 1),
        );

        $validationErrors = $structures->validateDraft($definition);
        $this->check('Active structure passes validation', count($validationErrors), implode(' ', $validationErrors));

        $orders = $levels->pluck('level_order')->sort()->values()->all();
        $this->check('Level order is contiguous from 1', $orders === range(1, $levels->count()) ? 0 : 1);
        $this->check('Exactly one root level', $levels->whereNull('parent_level_id')->count() === 1 ? 0 : 1);

        // Circular parent references among level definitions.
        $this->check('No circular level parent references', $this->countLevelCycles($levels));

        // ─── Node-level checks ───────────────────────────────────────────
        $nodes = ComplianceNode::where('compliance_program_id', $program->id)->get();
        $levelIds = $levels->pluck('id')->all();
        $activeLevelIds = $activeLevels->pluck('id')->all();

        $this->check(
            'Every node is bound to a hierarchy level',
            $nodes->whereNull('hierarchy_level_id')->count(),
        );
        $this->check(
            'No node references a level outside its own program structure',
            $nodes->whereNotNull('hierarchy_level_id')->reject(fn ($n) => in_array($n->hierarchy_level_id, $levelIds, true))->count(),
        );
        $this->check(
            'No node sits on a disabled level',
            $nodes->whereNotNull('hierarchy_level_id')->reject(fn ($n) => in_array($n->hierarchy_level_id, $activeLevelIds, true))->count(),
        );
        $this->check('No orphan nodes (missing parent)', $this->countOrphans($nodes));
        $this->check('No circular node references', $this->countNodeCycles($nodes));
        $this->check('No cross-program parent links', $this->countCrossProgramParents($nodes));
        $this->check('Node parent sits on the parent level', $this->countInvalidParentLevels($nodes, $levels));

        // ─── Semantic checks (audit finding H7) ──────────────────────────
        // Read straight off the node now that assignments and evidence
        // reference compliance_nodes directly — no mirror to resolve through.
        $nonAssignable = $nodes->reject(fn (ComplianceNode $n) => $n->isAssignable())->pluck('id')->all();
        $nonEvidence = $nodes->reject(fn (ComplianceNode $n) => $n->acceptsEvidence())->pluck('id')->all();
        $nonAssessable = $nodes->reject(fn (ComplianceNode $n) => $n->isAssessable())->pluck('id')->all();

        $this->check(
            'No assignment on a non-assignable level',
            $nonAssignable
                ? RequirementAssignment::where('compliance_program_id', $program->id)
                    ->whereIn('compliance_node_id', $nonAssignable)->count()
                : 0,
        );
        $this->check(
            'No evidence on a non-evidence level',
            $nonEvidence
                ? EvidenceSubmission::where('compliance_program_id', $program->id)
                    ->whereIn('compliance_node_id', $nonEvidence)->count()
                : 0,
        );
        $this->check(
            'No workflow instance on a non-assessable node',
            $nonAssessable
                ? EvidenceSubmission::where('compliance_program_id', $program->id)
                    ->whereIn('compliance_node_id', $nonAssessable)->count()
                : 0,
        );

        $this->newLine();
    }

    private function countLevelCycles($levels): int
    {
        $byId = $levels->keyBy('id');
        $bad = 0;
        foreach ($levels as $level) {
            $seen = [];
            $cursor = $level;
            while ($cursor && $cursor->parent_level_id) {
                if (isset($seen[$cursor->id])) {
                    $bad++;
                    break;
                }
                $seen[$cursor->id] = true;
                $cursor = $byId->get($cursor->parent_level_id);
            }
        }

        return $bad;
    }

    private function countOrphans($nodes): int
    {
        $ids = $nodes->pluck('id')->flip();

        return $nodes->filter(fn ($n) => $n->parent_id !== null && ! $ids->has($n->parent_id))->count();
    }

    private function countNodeCycles($nodes): int
    {
        $byId = $nodes->keyBy('id');
        $bad = 0;
        foreach ($nodes as $node) {
            $seen = [];
            $cursor = $node;
            $hops = 0;
            while ($cursor && $cursor->parent_id && $hops++ <= HierarchyDefinitionService::MAX_LEVELS + 1) {
                if (isset($seen[$cursor->id])) {
                    $bad++;
                    break;
                }
                $seen[$cursor->id] = true;
                $cursor = $byId->get($cursor->parent_id);
            }
        }

        return $bad;
    }

    private function countCrossProgramParents($nodes): int
    {
        $byId = $nodes->keyBy('id');

        return $nodes->filter(function ($n) use ($byId) {
            $parent = $n->parent_id ? $byId->get($n->parent_id) : null;

            return $parent && $parent->compliance_program_id !== $n->compliance_program_id;
        })->count();
    }

    private function countInvalidParentLevels($nodes, $levels): int
    {
        $byId = $nodes->keyBy('id');
        $levelsById = $levels->keyBy('id');

        return $nodes->filter(function ($n) use ($byId, $levelsById) {
            $level = $n->hierarchy_level_id ? $levelsById->get($n->hierarchy_level_id) : null;
            if (! $level) {
                return false; // already counted by the binding check
            }
            $parent = $n->parent_id ? $byId->get($n->parent_id) : null;

            // A root-level node must have no parent, and vice versa.
            if ($level->parent_level_id === null) {
                return $parent !== null;
            }
            if (! $parent) {
                return true;
            }

            return $parent->hierarchy_level_id !== $level->parent_level_id;
        })->count();
    }

    private function check(string $label, int $problemCount, string $detail = ''): void
    {
        if ($problemCount === 0) {
            $this->line(sprintf(' <fg=green>[OK]</> %-62s %s', $label, '0'));

            return;
        }

        $this->problems += $problemCount;
        $this->line(sprintf(' <fg=red>[!!]</> %-62s %s', $label, $problemCount));
        if ($detail !== '') {
            $this->line("      <fg=yellow>{$detail}</>");
        }
    }
}
