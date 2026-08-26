<?php

namespace App\Services;

use App\Exceptions\InvalidHierarchyException;
use App\Models\AssessmentCycle;
use App\Models\ComplianceContentVersion;
use App\Models\ComplianceNode;
use App\Models\ComplianceProgram;
use App\Models\HierarchyLevelDefinition;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * The single write path for the generic, arbitrary-depth hierarchy engine
 * (see ComplianceNode's class doc). Every validation the Phase 6 brief
 * requires ("Hierarchy Validation") is enforced here, not left to callers:
 * program match on parent/cycle/content-version, valid parent/child type
 * pairs (read from the program's own `hierarchy` configuration —
 * program-agnostic, not an ECC-specific if-branch), maximum configured
 * depth, and per-(program, content version) code uniqueness (enforced
 * again at the database level by a unique index, not only here).
 */
class ComplianceNodeService
{
    public function __construct(
        private readonly ProgramConfigurationService $config,
        private readonly HierarchyDefinitionService $structures,
    ) {}

    /**
     * @param  array{
     *   name_en?: ?string, description_ar?: ?string, description_en?: ?string,
     *   guidance_ar?: ?string, guidance_en?: ?string, sort_order?: int, metadata?: array,
     * }  $attributes
     */
    public function createNode(
        ComplianceProgram $program,
        string $nodeType,
        string $code,
        string $nameAr,
        ?ComplianceNode $parent,
        ?AssessmentCycle $cycle,
        ?ComplianceContentVersion $contentVersion,
        User $actor,
        array $attributes = [],
    ): ComplianceNode {
        $this->assertSameProgram($program, $parent, $cycle, $contentVersion);

        $levels = $this->levelDefinitions($program);
        $definition = $this->definitionFor($levels, $nodeType);
        $this->assertValidParentType($definition, $parent);
        $this->assertWithinMaxDepth($program, $parent);

        return DB::transaction(function () use ($program, $nodeType, $code, $nameAr, $parent, $cycle, $contentVersion, $actor, $attributes, $definition) {
            $levelModel = $this->structures->levelByKey($program, $nodeType);

            $node = ComplianceNode::create([
                'compliance_program_id' => $program->id,
                'program_cycle_id' => $cycle?->id,
                'content_version_id' => $contentVersion?->id,
                'parent_id' => $parent?->id,
                'hierarchy_level_id' => $levelModel?->id,
                'structure_version_id' => $this->structures->currentStructureVersion($program)?->id,
                'node_type' => $nodeType,
                'level' => $parent ? $parent->level + 1 : 0,
                'code' => $code,
                'name_ar' => $nameAr,
                'name_en' => $attributes['name_en'] ?? null,
                'description_ar' => $attributes['description_ar'] ?? null,
                'description_en' => $attributes['description_en'] ?? null,
                'guidance_ar' => $attributes['guidance_ar'] ?? null,
                'guidance_en' => $attributes['guidance_en'] ?? null,
                'sort_order' => $attributes['sort_order'] ?? 0,
                'is_assessable' => (bool) ($definition['is_assessable'] ?? false),
                'metadata' => $attributes['metadata'] ?? null,
                'created_by' => $actor->id,
                'updated_by' => $actor->id,
            ]);

            AuditService::log('compliance_node.created', "Hierarchy node '{$code}' ({$nodeType}) created", $node, complianceProgramId: $program->id);

            return $node;
        });
    }

    /**
     * Creates an assessable node.
     *
     * This method used to ALSO write a mirrored `standards` row, copying
     * only `$chain[0]` and `$chain[1]` into the fixed `perspective`/`axis`
     * columns and silently discarding every ancestor above index 1 — audit
     * finding C2, the defect that made deep ECC/NDMO hierarchies
     * unreportable. Assignments and evidence now reference
     * `compliance_nodes` directly, so the mirror has no remaining purpose
     * and is gone: there is exactly one representation of a requirement.
     *
     * The method is retained (rather than folded into createNode) because
     * it carries a distinct guarantee: it refuses to create a node at a
     * level the program did not mark assessable.
     */
    public function createAssessableNode(
        ComplianceProgram $program,
        string $nodeType,
        string $code,
        string $nameAr,
        ComplianceNode $parent,
        AssessmentCycle $cycle,
        ?ComplianceContentVersion $contentVersion,
        User $actor,
        array $attributes = [],
    ): ComplianceNode {
        $levels = $this->levelDefinitions($program);
        $definition = $this->definitionFor($levels, $nodeType);
        if (! ($definition['is_assessable'] ?? false)) {
            throw new InvalidHierarchyException("Node type '{$nodeType}' is not configured as assessable for program '{$program->code}'.");
        }

        return $this->createNode($program, $nodeType, $code, $nameAr, $parent, $cycle, $contentVersion, $actor, $attributes);
    }

    /**
     * The program's level definitions in the legacy array shape every caller
     * here already understands.
     *
     * Source of truth is now `hierarchy_level_definitions` (audit finding
     * C4). The `hierarchy` program-configuration blob remains as a fallback
     * only for a program that has not yet been given a definition, so
     * nothing breaks mid-transition; once every program has one, the
     * fallback becomes dead and is removed with the rest of the mirror.
     *
     * @return array<int, array{node_type:string,label_ar:string,label_en:string,parent_type:?string,is_assessable:bool}>
     */
    public function levelDefinitions(ComplianceProgram $program): array
    {
        $levels = collect($this->structures->levels($program));

        if ($levels->isEmpty()) {
            return $this->config->get($program, 'hierarchy', [])['levels'] ?? [];
        }

        $byId = $levels->keyBy('id');

        return $levels->map(fn (HierarchyLevelDefinition $l) => [
            'node_type' => $l->key,
            'label_ar' => $l->name_ar,
            'label_en' => $l->name_en,
            'parent_type' => $l->parent_level_id ? ($byId->get($l->parent_level_id)?->key) : null,
            'is_assessable' => (bool) $l->is_assessable,
            'is_assignable' => (bool) $l->is_assignable,
            'accepts_evidence' => (bool) $l->accepts_evidence,
        ])->values()->all();
    }

    private function definitionFor(array $levels, string $nodeType): array
    {
        foreach ($levels as $level) {
            if (($level['node_type'] ?? null) === $nodeType) {
                return $level;
            }
        }

        throw new InvalidHierarchyException("Node type '{$nodeType}' is not defined in this program's hierarchy configuration.");
    }

    private function assertValidParentType(array $definition, ?ComplianceNode $parent): void
    {
        $expectedParentType = $definition['parent_type'] ?? null;

        if ($expectedParentType === null && $parent !== null) {
            throw new InvalidHierarchyException("Node type '{$definition['node_type']}' must be a root node (no parent).");
        }
        if ($expectedParentType !== null && $parent?->node_type !== $expectedParentType) {
            throw new InvalidHierarchyException("Node type '{$definition['node_type']}' must have a parent of type '{$expectedParentType}'.");
        }
    }

    private function assertWithinMaxDepth(ComplianceProgram $program, ?ComplianceNode $parent): void
    {
        // max_depth is the number of allowed levels, so valid 0-based
        // levels are 0..(max_depth-1) — e.g. max_depth=4 permits levels
        // 0,1,2,3 (domain/subdomain/control/subcontrol).
        // With a dynamic definition, depth IS the level count — there is no
        // separate number to keep in sync. Only a program still on the legacy
        // config blob consults its explicit max_depth (audit finding H4).
        $dynamicLevels = collect($this->structures->levels($program));

        $maxDepth = $dynamicLevels->isNotEmpty()
            ? $dynamicLevels->count()
            : ($this->config->get($program, 'hierarchy', [])['max_depth'] ?? HierarchyDefinitionService::MAX_LEVELS);
        $nextLevel = $parent ? $parent->level + 1 : 0;

        if ($nextLevel >= $maxDepth) {
            throw new InvalidHierarchyException("Maximum configured hierarchy depth ({$maxDepth}) exceeded.");
        }
    }

    private function assertSameProgram(ComplianceProgram $program, ?ComplianceNode $parent, ?AssessmentCycle $cycle, ?ComplianceContentVersion $contentVersion): void
    {
        if ($parent && $parent->compliance_program_id !== $program->id) {
            throw new InvalidHierarchyException('Parent node belongs to a different program.');
        }
        if ($cycle && $cycle->compliance_program_id !== $program->id) {
            throw new InvalidHierarchyException('Cycle belongs to a different program.');
        }
        if ($contentVersion && $contentVersion->compliance_program_id !== $program->id) {
            throw new InvalidHierarchyException('Content version belongs to a different program.');
        }
    }
}
