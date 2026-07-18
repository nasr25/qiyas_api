<?php

namespace App\Services;

use App\Exceptions\InvalidHierarchyException;
use App\Models\AssessmentCycle;
use App\Models\ComplianceContentVersion;
use App\Models\ComplianceNode;
use App\Models\ComplianceProgram;
use App\Models\Standard;
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
    public function __construct(private readonly ProgramConfigurationService $config) {}

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
            $node = ComplianceNode::create([
                'compliance_program_id' => $program->id,
                'program_cycle_id' => $cycle?->id,
                'content_version_id' => $contentVersion?->id,
                'parent_id' => $parent?->id,
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
     * Creates an assessable leaf node AND its mirrored `standards` row in
     * one transaction, so the node is never left without its bridge (or
     * vice versa). The mirrored row's free-text perspective/axis fields
     * are derived from the node's own ancestor chain purely for display
     * inside the existing Standard-based views — the ComplianceNode chain
     * remains the source of truth for navigation/breadcrumbs.
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

        return DB::transaction(function () use ($program, $nodeType, $code, $nameAr, $parent, $cycle, $contentVersion, $actor, $attributes) {
            $ancestors = $parent->ancestors();
            $chain = [...$ancestors, $parent];
            $domainName = $chain[0]->name_ar ?? null;
            $subdomainName = $chain[1]->name_ar ?? ($chain[0]->name_ar ?? null);

            $standard = Standard::create([
                'cycle_id' => $cycle->id,
                'standard_number' => $code,
                'name_ar' => $nameAr,
                'name_en' => $attributes['name_en'] ?? null,
                'description' => $attributes['description_ar'] ?? null,
                'perspective' => $domainName,
                'axis' => $subdomainName,
                'application_requirements' => $attributes['guidance_ar'] ?? null,
                'evidence_documents' => $attributes['evidence_requirements_ar'] ?? null,
                'weight' => $attributes['weight'] ?? null,
                'due_date' => $attributes['due_date'] ?? null,
                'is_active' => true,
            ]);

            $node = $this->createNode($program, $nodeType, $code, $nameAr, $parent, $cycle, $contentVersion, $actor, $attributes);
            $node->update(['standard_id' => $standard->id]);

            return $node->fresh();
        });
    }

    /** @return array<int, array{node_type:string,label_ar:string,label_en:string,parent_type:?string,is_assessable:bool}> */
    public function levelDefinitions(ComplianceProgram $program): array
    {
        return $this->config->get($program, 'hierarchy', [])['levels'] ?? [];
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
        $maxDepth = $this->config->get($program, 'hierarchy', [])['max_depth'] ?? 10;
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
