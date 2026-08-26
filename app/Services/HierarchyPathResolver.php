<?php

namespace App\Services;

use App\Models\ComplianceNode;
use App\Models\ComplianceProgram;
use Illuminate\Support\Collection;

/**
 * Resolves node ancestor paths in bulk.
 *
 * `ComplianceNode::ancestors()` walks `->parent` lazily: correct, but one
 * query per hop per node. Measured on the 9,336-node performance fixture
 * that turned a single report into ~3,500 queries and a hierarchy export
 * into ~7,000 — see docs/performance-evidence.md.
 *
 * This loads a program's nodes ONCE and resolves every path in memory, so
 * a report over N rows costs one query instead of N × depth. Reads scale
 * with node count rather than with row count × depth.
 */
class HierarchyPathResolver
{
    /** @var Collection<int, ComplianceNode> keyed by node id */
    private Collection $nodes;

    private array $cache = [];

    private function __construct(Collection $nodes)
    {
        $this->nodes = $nodes;
    }

    /** One query for the whole program (optionally one cycle). */
    public static function forProgram(ComplianceProgram $program, ?int $cycleId = null): self
    {
        return new self(
            ComplianceNode::where('compliance_program_id', $program->id)
                ->when($cycleId, fn ($q) => $q->where('program_cycle_id', $cycleId))
                ->with('hierarchyLevel')
                ->get()
                ->keyBy('id'),
        );
    }

    public function node(int $id): ?ComplianceNode
    {
        return $this->nodes->get($id);
    }

    /**
     * Root-first chain including the node itself.
     *
     * @return array<int, ComplianceNode>
     */
    public function chain(int $nodeId): array
    {
        if (isset($this->cache[$nodeId])) {
            return $this->cache[$nodeId];
        }

        $chain = [];
        $cursor = $this->nodes->get($nodeId);
        $guard = 0;

        while ($cursor && $guard++ <= HierarchyDefinitionService::MAX_LEVELS + 1) {
            array_unshift($chain, $cursor);
            $cursor = $cursor->parent_id ? $this->nodes->get($cursor->parent_id) : null;
        }

        return $this->cache[$nodeId] = $chain;
    }

    /**
     * The same shape ComplianceNode::pathLabels() returns, without the
     * per-hop queries.
     *
     * @return array<int, array{level_key:string,level_name:string,code:string,name:string}>
     */
    public function pathLabels(int $nodeId): array
    {
        return array_map(fn (ComplianceNode $n) => [
            'level_key' => $n->hierarchyLevel?->key ?? $n->node_type,
            'level_name' => $n->hierarchyLevel?->name ?? $n->node_type,
            'code' => $n->code,
            'name' => $n->name,
        ], $this->chain($nodeId));
    }

    /** Path filtered to breadcrumb-visible levels, matching ComplianceNode::breadcrumb(). */
    public function breadcrumb(int $nodeId): array
    {
        return array_values(array_map(
            fn (ComplianceNode $n) => [
                'id' => $n->id,
                'level_key' => $n->hierarchyLevel?->key ?? $n->node_type,
                'level_name' => $n->hierarchyLevel?->name ?? $n->node_type,
                'code' => $n->code,
                'name' => $n->name,
            ],
            array_filter($this->chain($nodeId), fn (ComplianceNode $n) => $n->hierarchyLevel?->appears_in_breadcrumb ?? true),
        ));
    }
}
