<?php

namespace App\Models;

use App\Services\HierarchyDefinitionService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Generic, self-referential, arbitrary-depth hierarchy node — see
 * database/migrations/2026_07_21_000002_create_compliance_nodes_table.php
 * for why this exists instead of forcing ECC's
 * (Main Domain -> Subdomain -> Control -> Subcontrol) shape into Qiyas's
 * fixed two-level (Perspective -> Axis) free-text fields.
 *
 * This is the single authoritative model for compliance content. The
 * former `standards` mirror was removed: assignments, evidence and
 * workflow reference a node directly.
 */
class ComplianceNode extends Model
{
    protected $fillable = [
        'compliance_program_id', 'program_cycle_id', 'content_version_id', 'parent_id',
        'hierarchy_level_id', 'structure_version_id',
        'node_type', 'level', 'code', 'name_ar', 'name_en',
        'description_ar', 'description_en', 'objective_ar', 'objective_en',
        'guidance_ar', 'guidance_en', 'weight', 'default_due_date',
        'sort_order', 'is_assessable', 'status', 'archived_at', 'metadata', 'standard_id',
        'is_assignable_override', 'is_assessable_override', 'accepts_evidence_override',
        'created_by', 'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'is_assessable' => 'boolean',
            'is_assignable_override' => 'boolean',
            'is_assessable_override' => 'boolean',
            'accepts_evidence_override' => 'boolean',
            'metadata' => 'array',
            'level' => 'integer',
            'weight' => 'decimal:2',
            'default_due_date' => 'date',
            'archived_at' => 'datetime',
        ];
    }

    // ─── Relationships ───────────────────────────────────────────────────────

    public function program()
    {
        return $this->belongsTo(ComplianceProgram::class, 'compliance_program_id');
    }

    public function cycle()
    {
        return $this->belongsTo(AssessmentCycle::class, 'program_cycle_id');
    }

    public function contentVersion()
    {
        return $this->belongsTo(ComplianceContentVersion::class, 'content_version_id');
    }

    public function parent()
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children()
    {
        return $this->hasMany(self::class, 'parent_id')->orderBy('sort_order');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function hierarchyLevel()
    {
        return $this->belongsTo(HierarchyLevelDefinition::class, 'hierarchy_level_id');
    }

    public function structureVersion()
    {
        return $this->belongsTo(ProgramStructureVersion::class, 'structure_version_id');
    }

    // ─── Display ─────────────────────────────────────────────────────────────

    /**
     * Locale-aware display name, matching the accessor every other model in
     * the platform exposes (ComplianceProgram, Standard, Department).
     * Callers that previously read `Standard::$name` keep working unchanged
     * once their relation is repointed here.
     */
    public function getNameAttribute(): string
    {
        return app()->getLocale() === 'ar'
            ? ($this->name_ar ?: $this->name_en ?: $this->code)
            : ($this->name_en ?: $this->name_ar ?: $this->code);
    }

    /**
     * The node's full path, root-first, as level-keyed labels. This is the
     * hierarchy-neutral replacement for the fixed `perspective`/`axis` pair
     * that every workflow response used to carry (audit findings C2, H3).
     *
     * Depth is whatever the program configured — three entries for Sumoud,
     * six for NDMO — so no consumer may assume a length.
     *
     * @return array<int, array{level_key:string,level_name:string,code:string,name:string}>
     */
    public function pathLabels(): array
    {
        $chain = [...$this->ancestors(), $this];

        return array_map(fn (self $n) => [
            'level_key' => $n->hierarchyLevel?->key ?? $n->node_type,
            'level_name' => $n->hierarchyLevel?->name ?? $n->node_type,
            'code' => $n->code,
            'name' => $n->name,
        ], $chain);
    }

    /**
     * The path filtered to levels the program marked as breadcrumb-visible,
     * including this node. Arbitrary depth — the same component renders
     * Sumoud's three entries and NDMO's six.
     */
    public function breadcrumb(): array
    {
        $chain = [...$this->ancestors(), $this];

        return array_values(array_map(
            fn (self $n) => [
                'id' => $n->id,
                'level_key' => $n->hierarchyLevel?->key ?? $n->node_type,
                'level_name' => $n->hierarchyLevel?->name ?? $n->node_type,
                'code' => $n->code,
                'name' => $n->name,
            ],
            array_filter($chain, fn (self $n) => $n->hierarchyLevel?->appears_in_breadcrumb ?? true),
        ));
    }

    // ─── Effective semantics ─────────────────────────────────────────────────

    /**
     * Resolves a behavioural flag: the node's own override if it set one,
     * otherwise the level definition's value, otherwise false. This is the
     * ONLY place these three questions should be answered — assignment,
     * evidence and workflow services must call these rather than test
     * `is_assessable` directly (audit finding H7).
     */
    private function effectiveFlag(string $flag, ?bool $override): bool
    {
        if ($override !== null) {
            return $override;
        }

        return (bool) ($this->hierarchyLevel?->{$flag} ?? false);
    }

    public function isAssignable(): bool
    {
        return $this->effectiveFlag('is_assignable', $this->is_assignable_override);
    }

    public function isAssessable(): bool
    {
        // Falls back to the legacy denormalised column for nodes created
        // before hierarchy_level_id existed, so pre-existing rows keep
        // behaving as they did until the clean test data replaces them.
        if ($this->is_assessable_override !== null) {
            return $this->is_assessable_override;
        }

        return (bool) ($this->hierarchyLevel?->is_assessable ?? $this->is_assessable);
    }

    public function acceptsEvidence(): bool
    {
        return $this->effectiveFlag('accepts_evidence', $this->accepts_evidence_override);
    }

    // ─── Tree traversal ──────────────────────────────────────────────────────

    /**
     * Ids of $rootId and every descendant beneath it, at any depth, in one
     * query via a MySQL 8 recursive CTE.
     *
     * This is what makes "filter by any hierarchy level" possible without a
     * per-level implementation: a caller passes whichever node the user
     * picked — a Perspective, a Policy, a Subcontrol — and gets the whole
     * subtree regardless of how deep the program's structure runs. The
     * previous approach could only filter on two fixed columns
     * (`perspective`, `axis`) and had no answer for level 3 and below
     * (audit findings H3, M2).
     *
     * The depth guard is a safety net against a cycle that slipped past
     * `compliance:verify-hierarchy`; valid trees never approach it.
     *
     * @return array<int, int>
     */
    public static function subtreeIds(int $rootId): array
    {
        $maxDepth = HierarchyDefinitionService::MAX_LEVELS + 1;

        $rows = DB::select(
            'WITH RECURSIVE subtree (id, depth) AS ('
            .'  SELECT id, 0 FROM compliance_nodes WHERE id = ?'
            .'  UNION ALL'
            .'  SELECT n.id, s.depth + 1 FROM compliance_nodes n'
            .'    JOIN subtree s ON n.parent_id = s.id'
            .'   WHERE s.depth < ?'
            .') SELECT id FROM subtree',
            [$rootId, $maxDepth],
        );

        return array_map(static fn ($r) => (int) $r->id, $rows);
    }

    // ─── Scopes ──────────────────────────────────────────────────────────────

    public function scopeForProgram($query, ComplianceProgram $program)
    {
        return $query->where('compliance_program_id', $program->id);
    }

    public function scopeAssessable($query)
    {
        return $query->where('is_assessable', true);
    }

    public function scopeRoots($query)
    {
        return $query->whereNull('parent_id');
    }

    /**
     * Ancestor chain root-first, read-only, bounded to avoid runaway
     * recursion on a corrupted tree.
     *
     * The bound was previously 10 — the same magic number that appeared,
     * with three different meanings, in two other files (audit finding H4).
     * It now derives from the platform ceiling and sits one hop ABOVE the
     * deepest legal tree, so a valid hierarchy can never be silently
     * truncated; only genuinely cyclic data stops early.
     */
    public function ancestors(?int $maxHops = null): array
    {
        $maxHops ??= HierarchyDefinitionService::MAX_LEVELS + 1;

        $chain = [];
        $node = $this->parent;
        $hops = 0;
        while ($node && $hops < $maxHops) {
            array_unshift($chain, $node);
            $node = $node->parent;
            $hops++;
        }

        return $chain;
    }
}
