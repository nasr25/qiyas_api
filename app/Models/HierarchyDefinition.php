<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One revision of a compliance program's hierarchy structure. Replaces the
 * `hierarchy` program-configuration JSON blob (audit finding C4): depth,
 * terminology and per-level behaviour are now rows a Program Manager can
 * edit for their own program, not seeder-only configuration requiring a
 * deploy.
 *
 * Lifecycle: draft -> active -> superseded. Exactly one active revision per
 * program, enforced in HierarchyDefinitionService::activate().
 */
class HierarchyDefinition extends Model
{
    protected $fillable = [
        'compliance_program_id', 'version', 'name_ar', 'name_en', 'status',
        'activated_at', 'activated_by', 'change_summary', 'created_by', 'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'activated_at' => 'datetime',
            'version' => 'integer',
        ];
    }

    // ─── Relationships ───────────────────────────────────────────────────────

    public function program()
    {
        return $this->belongsTo(ComplianceProgram::class, 'compliance_program_id');
    }

    /** All levels, shallowest first — the canonical order everything else relies on. */
    public function levels()
    {
        return $this->hasMany(HierarchyLevelDefinition::class, 'hierarchy_definition_id')
            ->orderBy('level_order');
    }

    public function activeLevels()
    {
        return $this->levels()->where('is_active', true);
    }

    public function structureVersions()
    {
        return $this->hasMany(ProgramStructureVersion::class, 'hierarchy_definition_id');
    }

    // ─── Scopes ──────────────────────────────────────────────────────────────

    public function scopeForProgram($query, ComplianceProgram $program)
    {
        return $query->where('compliance_program_id', $program->id);
    }

    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    public function scopeDraft($query)
    {
        return $query->where('status', 'draft');
    }

    // ─── Helpers ─────────────────────────────────────────────────────────────

    public function isEditable(): bool
    {
        return $this->status === 'draft';
    }

    /** Depth of this structure, counting only enabled levels. */
    public function depth(): int
    {
        return $this->activeLevels()->count();
    }

    /**
     * Frozen representation written into program_structure_versions.snapshot
     * on activation. Everything a historical consumer needs to render the
     * structure without reading the (mutable) level rows.
     */
    public function toSnapshot(): array
    {
        return [
            'definition_version' => $this->version,
            'program_id' => $this->compliance_program_id,
            'name_ar' => $this->name_ar,
            'name_en' => $this->name_en,
            'levels' => $this->levels()->get()->map(fn (HierarchyLevelDefinition $l) => $l->toSnapshot())->values()->all(),
        ];
    }
}
