<?php

namespace App\Services;

use App\Exceptions\InvalidHierarchyException;
use App\Models\AssessmentCycle;
use App\Models\ComplianceNode;
use App\Models\ComplianceProgram;
use App\Models\EvidenceSubmission;
use App\Models\HierarchyDefinition;
use App\Models\HierarchyLevelDefinition;
use App\Models\ProgramStructureVersion;
use App\Models\RequirementAssignment;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * The single write path for a program's hierarchy STRUCTURE (as opposed to
 * ComplianceNodeService, which writes the CONTENT placed inside that
 * structure).
 *
 * Replaces the seeder-only `hierarchy` program-configuration blob audited
 * as finding C4 in docs/compliance-hierarchy-audit.md. A Program Manager
 * edits a draft revision of their own program, previews the impact,
 * and activates — at which point an immutable ProgramStructureVersion
 * snapshot is frozen so historical cycles never silently re-render under
 * new terminology (finding C5).
 *
 * Every rule the brief calls "Safe Structure Changes" is enforced here, on
 * the backend, not merely hidden in the UI.
 */
class HierarchyDefinitionService
{
    /**
     * Platform ceiling on hierarchy depth. Previously this number was
     * hard-coded in three unrelated places with three different meanings
     * (audit finding H4: a `max:10` validation rule, a `?? 10` fallback and
     * an `ancestors($maxHops = 10)` silent truncation). It is now declared
     * once. 12 is a deliberate headroom choice: the deepest framework the
     * platform is expected to model is 7 levels, and the brief's acceptance
     * criterion is an 8-level program created without code changes.
     */
    public const MAX_LEVELS = 12;

    /** Changes that only affect presentation and are always safe, even mid-cycle. */
    public const DISPLAY_ONLY_FIELDS = [
        'name_ar', 'name_en', 'plural_name_ar', 'plural_name_en', 'icon',
        'appears_in_dashboard', 'appears_in_reports', 'appears_in_filters', 'appears_in_breadcrumb',
    ];

    // ─── Reading ─────────────────────────────────────────────────────────────

    public function activeDefinition(ComplianceProgram $program): ?HierarchyDefinition
    {
        return HierarchyDefinition::forProgram($program)->active()->with('levels')->first();
    }

    public function draftDefinition(ComplianceProgram $program): ?HierarchyDefinition
    {
        return HierarchyDefinition::forProgram($program)->draft()->with('levels')->latest('version')->first();
    }

    /** Active levels of the active definition, shallowest first. */
    public function levels(ComplianceProgram $program): iterable
    {
        return $this->activeDefinition($program)?->activeLevels()->get() ?? collect();
    }

    public function levelByKey(ComplianceProgram $program, string $key): ?HierarchyLevelDefinition
    {
        return collect($this->levels($program))->firstWhere('key', $key);
    }

    /** The level a child of $level would occupy, or null if $level is the deepest. */
    public function childLevelOf(HierarchyLevelDefinition $level): ?HierarchyLevelDefinition
    {
        return HierarchyLevelDefinition::where('hierarchy_definition_id', $level->hierarchy_definition_id)
            ->where('parent_level_id', $level->id)
            ->where('is_active', true)
            ->first();
    }

    public function rootLevel(ComplianceProgram $program): ?HierarchyLevelDefinition
    {
        return collect($this->levels($program))->firstWhere('parent_level_id', null);
    }

    // ─── Draft lifecycle ─────────────────────────────────────────────────────

    /**
     * Opens an editable draft for the program. If the program already has a
     * draft it is returned as-is; otherwise a new revision is created,
     * cloning the active structure so the manager edits a copy rather than
     * starting from an empty screen.
     */
    public function openDraft(ComplianceProgram $program, User $actor): HierarchyDefinition
    {
        if ($existing = $this->draftDefinition($program)) {
            return $existing;
        }

        return DB::transaction(function () use ($program, $actor) {
            $active = $this->activeDefinition($program);
            $nextVersion = (int) HierarchyDefinition::forProgram($program)->max('version') + 1;

            $draft = HierarchyDefinition::create([
                'compliance_program_id' => $program->id,
                'version' => $nextVersion,
                'name_ar' => $active?->name_ar,
                'name_en' => $active?->name_en,
                'status' => 'draft',
                'created_by' => $actor->id,
                'updated_by' => $actor->id,
            ]);

            if ($active) {
                $this->cloneLevels($active, $draft, $actor);
            }

            AuditService::log(
                'hierarchy_structure.draft_opened',
                "Opened structure draft v{$nextVersion} for program '{$program->code}'",
                $draft,
                complianceProgramId: $program->id,
            );

            return $draft->fresh('levels');
        });
    }

    /** Copies every level of $from onto $to, preserving order and re-linking parents. */
    private function cloneLevels(HierarchyDefinition $from, HierarchyDefinition $to, User $actor): void
    {
        $map = [];
        foreach ($from->levels()->get() as $level) {
            $clone = HierarchyLevelDefinition::create([
                ...collect($level->toSnapshot())->except(['id', 'parent_level_id'])->all(),
                'hierarchy_definition_id' => $to->id,
                'compliance_program_id' => $to->compliance_program_id,
                'parent_level_id' => $level->parent_level_id ? ($map[$level->parent_level_id] ?? null) : null,
                'created_by' => $actor->id,
                'updated_by' => $actor->id,
            ]);
            $map[$level->id] = $clone->id;
        }
    }

    /**
     * Discards a draft revision entirely.
     *
     * A manager who starts editing and changes their mind should be able to
     * throw the draft away rather than hand-undo each edit. Only a draft can
     * be discarded — an active or superseded revision is history.
     */
    public function discardDraft(HierarchyDefinition $draft, User $actor): void
    {
        $this->assertDraft($draft);

        DB::transaction(function () use ($draft) {
            AuditService::log(
                'hierarchy_structure.draft_discarded',
                "Discarded structure draft v{$draft->version} for program '{$draft->program->code}'",
                $draft,
                complianceProgramId: $draft->compliance_program_id,
            );

            $draft->levels()->delete();
            $draft->delete();
        });
    }

    /**
     * Appends a level to the end of a draft. Depth is a row count, so this
     * is the whole of "add a level" — no migration, no deploy.
     */
    public function addLevel(HierarchyDefinition $draft, array $attributes, User $actor): HierarchyLevelDefinition
    {
        $this->assertDraft($draft);

        $existing = $draft->levels()->get();
        if ($existing->count() >= self::MAX_LEVELS) {
            throw new InvalidHierarchyException(
                'A hierarchy may not exceed '.self::MAX_LEVELS.' levels.'
            );
        }
        if ($existing->contains('key', $attributes['key'] ?? null)) {
            throw new InvalidHierarchyException("Level key '{$attributes['key']}' is already used in this structure.");
        }

        $deepest = $existing->last();

        $level = HierarchyLevelDefinition::create([
            ...$attributes,
            'hierarchy_definition_id' => $draft->id,
            'compliance_program_id' => $draft->compliance_program_id,
            'level_order' => ($deepest?->level_order ?? 0) + 1,
            'parent_level_id' => $deepest?->id,
            'created_by' => $actor->id,
            'updated_by' => $actor->id,
        ]);

        AuditService::log(
            'hierarchy_structure.level_added',
            "Added level '{$level->key}' at position {$level->level_order} to draft v{$draft->version}",
            $level,
            null,
            $level->toSnapshot(),
            $draft->compliance_program_id,
        );

        return $level;
    }

    public function updateLevel(HierarchyLevelDefinition $level, array $attributes, User $actor): HierarchyLevelDefinition
    {
        $draft = $level->definition;
        $this->assertDraft($draft);

        $old = $level->toSnapshot();
        $level->update([...$attributes, 'updated_by' => $actor->id]);

        AuditService::log(
            'hierarchy_structure.level_updated',
            "Updated level '{$level->key}' in draft v{$draft->version}",
            $level,
            $old,
            $level->fresh()->toSnapshot(),
            $level->compliance_program_id,
        );

        return $level->fresh();
    }

    /**
     * Disables a level without deleting it — the non-destructive alternative
     * the brief requires when a level already holds production records.
     */
    public function disableLevel(HierarchyLevelDefinition $level, User $actor): HierarchyLevelDefinition
    {
        return $this->updateLevel($level, ['is_active' => false], $actor);
    }

    /**
     * Moves a level one position shallower or deeper within its draft,
     * rebuilding the parent chain so it stays linear and contiguous.
     */
    public function moveLevel(HierarchyLevelDefinition $level, string $direction, User $actor): HierarchyDefinition
    {
        $draft = $level->definition;
        $this->assertDraft($draft);

        $ordered = $draft->levels()->get()->values();
        $index = $ordered->search(fn ($l) => $l->id === $level->id);
        $target = $direction === 'up' ? $index - 1 : $index + 1;

        if ($index === false || $target < 0 || $target >= $ordered->count()) {
            throw new InvalidHierarchyException('The level cannot move further in that direction.');
        }

        $reordered = $ordered->all();
        [$reordered[$index], $reordered[$target]] = [$reordered[$target], $reordered[$index]];

        $this->rewriteChain($reordered, $actor);

        AuditService::log(
            'hierarchy_structure.level_reordered',
            "Moved level '{$level->key}' {$direction} in draft v{$draft->version}",
            $level,
            ['order' => $ordered->pluck('key')->all()],
            ['order' => collect($reordered)->pluck('key')->all()],
            $level->compliance_program_id,
        );

        return $draft->fresh('levels');
    }

    /**
     * Rebuilds a draft's chain from its current ordering — used after a level
     * is deleted from the middle, which would otherwise leave a gap in
     * level_order and a dangling parent_level_id.
     */
    public function normalise(HierarchyDefinition $draft, User $actor): HierarchyDefinition
    {
        $this->assertDraft($draft);
        $this->rewriteChain($draft->levels()->get()->all(), $actor);

        return $draft->fresh('levels');
    }

    /** Reassigns level_order 1..N and re-links parent_level_id down the list. */
    private function rewriteChain(array $orderedLevels, User $actor): void
    {
        DB::transaction(function () use ($orderedLevels, $actor) {
            // Two passes: park orders out of range first, because
            // (hierarchy_definition_id, level_order) is unique.
            foreach ($orderedLevels as $i => $level) {
                $level->update(['level_order' => 1000 + $i, 'updated_by' => $actor->id]);
            }
            $previousId = null;
            foreach ($orderedLevels as $i => $level) {
                $level->update([
                    'level_order' => $i + 1,
                    'parent_level_id' => $previousId,
                    'updated_by' => $actor->id,
                ]);
                $previousId = $level->id;
            }
        });
    }

    // ─── Validation ──────────────────────────────────────────────────────────

    /**
     * Structural rules a draft must satisfy before it can be activated.
     *
     * @return array<int, string> human-readable problems; empty means valid
     */
    public function validateDraft(HierarchyDefinition $draft): array
    {
        $levels = $draft->levels()->get();
        $errors = [];

        if ($levels->isEmpty()) {
            return ['A hierarchy must define at least one level.'];
        }
        if ($levels->count() > self::MAX_LEVELS) {
            $errors[] = 'A hierarchy may not exceed '.self::MAX_LEVELS.' levels.';
        }

        $active = $levels->where('is_active', true)->values();
        if ($active->isEmpty()) {
            $errors[] = 'At least one level must be enabled.';
        }

        // Exactly one root.
        $roots = $levels->whereNull('parent_level_id');
        if ($roots->count() !== 1) {
            $errors[] = "A hierarchy must have exactly one root level; found {$roots->count()}.";
        }

        // Contiguous 1..N ordering.
        $orders = $levels->pluck('level_order')->sort()->values()->all();
        if ($orders !== range(1, $levels->count())) {
            $errors[] = 'Level order must be contiguous starting at 1.';
        }

        // Linear, acyclic parent chain.
        $byId = $levels->keyBy('id');
        foreach ($levels as $level) {
            if ($level->parent_level_id === null) {
                continue;
            }
            $parent = $byId->get($level->parent_level_id);
            if (! $parent) {
                $errors[] = "Level '{$level->key}' references a parent outside this structure.";

                continue;
            }
            if ($parent->level_order >= $level->level_order) {
                $errors[] = "Level '{$level->key}' must sit deeper than its parent '{$parent->key}'.";
            }
        }

        // At least one assessable level, or nothing can ever enter a workflow.
        if ($active->where('is_assessable', true)->isEmpty()) {
            $errors[] = 'At least one enabled level must be assessable, otherwise no requirement can enter a workflow.';
        }

        // Evidence is collected through the review workflow, so an
        // evidence-bearing level that is not assessable can never be reviewed.
        foreach ($active->where('accepts_evidence', true) as $level) {
            if (! $level->is_assessable) {
                $errors[] = "Level '{$level->key}' accepts evidence but is not assessable; evidence would never reach a reviewer.";
            }
        }

        // An assignable level must be able to hold work.
        foreach ($active->where('is_assignable', true) as $level) {
            if (! $level->is_assessable) {
                $errors[] = "Level '{$level->key}' is assignable but not assessable; an assignment there could never be completed.";
            }
        }

        return $errors;
    }

    // ─── Impact preview ──────────────────────────────────────────────────────

    /**
     * What activating this draft would actually touch. Shown to the Program
     * Manager before activation, and re-checked server-side at activation
     * time so a stale preview cannot be used to force an unsafe change.
     */
    public function previewImpact(HierarchyDefinition $draft): array
    {
        $program = $draft->program;
        $active = $this->activeDefinition($program);

        $activeKeys = $active ? $active->levels()->get()->pluck('key')->all() : [];
        $draftKeys = $draft->levels()->get()->pluck('key')->all();

        $removed = array_values(array_diff($activeKeys, $draftKeys));
        $added = array_values(array_diff($draftKeys, $activeKeys));
        $reordered = $active && $activeKeys !== $draftKeys && ! $removed && ! $added;

        $nodeIds = ComplianceNode::where('compliance_program_id', $program->id)->pluck('id');
        $nodeCount = $nodeIds->count();

        $assignments = RequirementAssignment::where('compliance_program_id', $program->id)
            ->whereIn('compliance_node_id', $nodeIds)->count();
        $submissions = EvidenceSubmission::where('compliance_program_id', $program->id)
            ->whereIn('compliance_node_id', $nodeIds)->count();

        $activeCycles = AssessmentCycle::where('compliance_program_id', $program->id)
            ->where('status', 'active')->count();
        $historicalCycles = AssessmentCycle::where('compliance_program_id', $program->id)
            ->whereIn('status', ['closed', 'archived'])->count();

        $nodesOnRemovedLevels = $removed
            ? ComplianceNode::where('compliance_program_id', $program->id)->whereIn('node_type', $removed)->count()
            : 0;

        $classification = $this->classify($removed, $added, $reordered, $nodesOnRemovedLevels, $nodeCount, $activeCycles);

        return [
            'classification' => $classification['level'],
            'reasons' => $classification['reasons'],
            'blocking' => $classification['level'] === 'not_allowed',
            'changes' => [
                'levels_added' => $added,
                'levels_removed' => $removed,
                'levels_reordered' => $reordered,
            ],
            'affected' => [
                'nodes' => $nodeCount,
                'nodes_on_removed_levels' => $nodesOnRemovedLevels,
                'assignments' => $assignments,
                'evidence_submissions' => $submissions,
                'active_cycles' => $activeCycles,
                'historical_cycles' => $historicalCycles,
            ],
            'validation_errors' => $this->validateDraft($draft),
        ];
    }

    /**
     * Safe            — presentation-only, or a structure with no content yet.
     * requires_migration — structural change against existing content.
     * not_allowed     — would corrupt or orphan data in a running cycle.
     */
    private function classify(array $removed, array $added, bool $reordered, int $nodesOnRemoved, int $nodeCount, int $activeCycles): array
    {
        $reasons = [];

        // With no content yet, any structural change is free.
        if ($nodeCount === 0 && ! $removed) {
            return ['level' => 'safe', 'reasons' => ['The program has no hierarchy content yet, so structural changes are free.']];
        }

        if ($nodesOnRemoved > 0 && $activeCycles > 0) {
            $reasons[] = "Removing a level that holds {$nodesOnRemoved} node(s) while {$activeCycles} cycle(s) are active would orphan live content.";

            return ['level' => 'not_allowed', 'reasons' => $reasons];
        }

        if ($reordered && $nodeCount > 0 && $activeCycles > 0) {
            $reasons[] = "Reordering a populated hierarchy while {$activeCycles} cycle(s) are active would change what existing parent links mean.";

            return ['level' => 'not_allowed', 'reasons' => $reasons];
        }

        if ($added && $nodeCount > 0 && $activeCycles > 0) {
            $reasons[] = 'Inserting a level into a populated hierarchy during an active cycle requires a content migration.';

            return ['level' => 'requires_migration', 'reasons' => $reasons];
        }

        if ($nodesOnRemoved > 0) {
            $reasons[] = "{$nodesOnRemoved} node(s) sit on a level being removed and must be archived or re-parented first.";

            return ['level' => 'requires_migration', 'reasons' => $reasons];
        }

        if ($removed || $added || $reordered) {
            $reasons[] = 'Structural change against existing content; review the affected counts before activating.';

            return ['level' => 'requires_migration', 'reasons' => $reasons];
        }

        return ['level' => 'safe', 'reasons' => ['Presentation-only changes; no structural impact.']];
    }

    // ─── Activation ──────────────────────────────────────────────────────────

    /**
     * Promotes a validated draft to active, supersedes the previous
     * definition, and freezes an immutable ProgramStructureVersion snapshot.
     *
     * @param  bool  $acknowledgeMigration  the manager confirmed a
     *                                      `requires_migration` impact
     */
    public function activate(HierarchyDefinition $draft, User $actor, bool $acknowledgeMigration = false): HierarchyDefinition
    {
        $this->assertDraft($draft);

        $errors = $this->validateDraft($draft);
        if ($errors) {
            throw new InvalidHierarchyException('Structure is not valid: '.implode(' ', $errors));
        }

        // Re-evaluated server-side; a stale client preview cannot force this.
        $impact = $this->previewImpact($draft);
        if ($impact['blocking']) {
            AuditService::log(
                'hierarchy_structure.activation_rejected',
                "Activation of draft v{$draft->version} rejected: ".implode(' ', $impact['reasons']),
                $draft,
                complianceProgramId: $draft->compliance_program_id,
            );
            throw new InvalidHierarchyException(implode(' ', $impact['reasons']));
        }
        if ($impact['classification'] === 'requires_migration' && ! $acknowledgeMigration) {
            throw new InvalidHierarchyException(
                'This change requires migration and must be explicitly acknowledged: '.implode(' ', $impact['reasons'])
            );
        }

        return DB::transaction(function () use ($draft, $actor, $impact) {
            HierarchyDefinition::forProgram($draft->program)
                ->where('status', 'active')
                ->update(['status' => 'superseded', 'updated_by' => $actor->id]);

            ProgramStructureVersion::forProgram($draft->program)
                ->where('status', 'active')
                ->update(['status' => 'superseded']);

            $draft->update([
                'status' => 'active',
                'activated_at' => now(),
                'activated_by' => $actor->id,
                'updated_by' => $actor->id,
            ]);

            $version = ProgramStructureVersion::create([
                'compliance_program_id' => $draft->compliance_program_id,
                'hierarchy_definition_id' => $draft->id,
                'version' => $draft->version,
                'snapshot' => $draft->fresh('levels')->toSnapshot(),
                'status' => 'active',
                'activated_at' => now(),
                'created_by' => $actor->id,
                'change_summary' => $draft->change_summary,
            ]);

            AuditService::log(
                'hierarchy_structure.activated',
                "Activated structure v{$draft->version} for program '{$draft->program->code}' ({$impact['classification']})",
                $version,
                null,
                ['impact' => $impact['affected'], 'classification' => $impact['classification']],
                $draft->compliance_program_id,
            );

            return $draft->fresh('levels');
        });
    }

    public function currentStructureVersion(ComplianceProgram $program): ?ProgramStructureVersion
    {
        return ProgramStructureVersion::forProgram($program)->active()->latest('version')->first();
    }

    private function assertDraft(HierarchyDefinition $definition): void
    {
        if (! $definition->isEditable()) {
            throw new InvalidHierarchyException(
                'Only a draft structure can be edited. Open a new draft to change an active structure.'
            );
        }
    }
}
