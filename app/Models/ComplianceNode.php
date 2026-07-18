<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Generic, self-referential, arbitrary-depth hierarchy node — see
 * database/migrations/2026_07_21_000002_create_compliance_nodes_table.php
 * for why this exists instead of forcing ECC's
 * (Main Domain -> Subdomain -> Control -> Subcontrol) shape into Qiyas's
 * fixed two-level (Perspective -> Axis) free-text fields.
 *
 * An `is_assessable` leaf node mirrors itself into `standards` (via
 * `standard_id`) so the entire pre-existing assignment/evidence/review/
 * SLA/extension/notification/dashboard/report engine works unmodified —
 * see ComplianceNodeService::createAssessableNode().
 */
class ComplianceNode extends Model
{
    protected $fillable = [
        'compliance_program_id', 'program_cycle_id', 'content_version_id', 'parent_id',
        'node_type', 'level', 'code', 'name_ar', 'name_en',
        'description_ar', 'description_en', 'guidance_ar', 'guidance_en',
        'sort_order', 'is_assessable', 'status', 'metadata', 'standard_id',
        'created_by', 'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'is_assessable' => 'boolean',
            'metadata' => 'array',
            'level' => 'integer',
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

    public function standard()
    {
        return $this->belongsTo(Standard::class, 'standard_id');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
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

    /** Ancestor chain root-first (e.g. [domain, subdomain]), read-only, bounded to avoid runaway recursion. */
    public function ancestors(int $maxHops = 10): array
    {
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
