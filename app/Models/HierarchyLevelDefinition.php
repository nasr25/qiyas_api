<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One level of one hierarchy definition. Every flag on this model replaces
 * something the platform previously hard-coded — see
 * docs/compliance-hierarchy-audit.md:
 *
 *   is_assignable / is_assessable / accepts_evidence   -> finding H7
 *   appears_in_dashboard / _reports / _filters         -> findings H1, H2, H3
 *   appears_in_breadcrumb                              -> finding H5
 *   *_enabled form flags                               -> finding H6
 *   is_active / level_order / parent_level_id          -> finding M6
 */
class HierarchyLevelDefinition extends Model
{
    /** Every behavioural flag, in one place, so callers never guess a column name. */
    public const BEHAVIOUR_FLAGS = [
        'is_required', 'is_active', 'allow_children',
        'is_assignable', 'is_assessable', 'accepts_evidence',
        'appears_in_dashboard', 'appears_in_reports', 'appears_in_filters', 'appears_in_breadcrumb',
        'code_required', 'description_enabled', 'objective_enabled',
        'weight_enabled', 'due_date_enabled', 'instructions_enabled',
    ];

    protected $fillable = [
        'hierarchy_definition_id', 'compliance_program_id', 'key',
        'name_ar', 'name_en', 'plural_name_ar', 'plural_name_en',
        'level_order', 'parent_level_id',
        ...self::BEHAVIOUR_FLAGS,
        'icon', 'metadata_schema', 'created_by', 'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'level_order' => 'integer',
            'metadata_schema' => 'array',
            ...array_fill_keys(self::BEHAVIOUR_FLAGS, 'boolean'),
        ];
    }

    // ─── Relationships ───────────────────────────────────────────────────────

    public function definition()
    {
        return $this->belongsTo(HierarchyDefinition::class, 'hierarchy_definition_id');
    }

    public function program()
    {
        return $this->belongsTo(ComplianceProgram::class, 'compliance_program_id');
    }

    public function parentLevel()
    {
        return $this->belongsTo(self::class, 'parent_level_id');
    }

    public function childLevel()
    {
        return $this->hasOne(self::class, 'parent_level_id');
    }

    public function nodes()
    {
        return $this->hasMany(ComplianceNode::class, 'hierarchy_level_id');
    }

    // ─── Scopes ──────────────────────────────────────────────────────────────

    public function scopeEnabled($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeOrdered($query)
    {
        return $query->orderBy('level_order');
    }

    // ─── Display ─────────────────────────────────────────────────────────────

    /**
     * Locale-aware display name. Fixes audit finding H5, where the frontend
     * rendered `label_ar` unconditionally and English users saw Arabic.
     * Falls back to the other language rather than rendering an empty label.
     */
    public function getNameAttribute(): string
    {
        return app()->getLocale() === 'ar'
            ? ($this->name_ar ?: $this->name_en ?: $this->key)
            : ($this->name_en ?: $this->name_ar ?: $this->key);
    }

    public function getPluralNameAttribute(): string
    {
        $plural = app()->getLocale() === 'ar'
            ? ($this->plural_name_ar ?: $this->plural_name_en)
            : ($this->plural_name_en ?: $this->plural_name_ar);

        return $plural ?: $this->name;
    }

    public function isRoot(): bool
    {
        return $this->parent_level_id === null;
    }

    public function toSnapshot(): array
    {
        return [
            'id' => $this->id,
            'key' => $this->key,
            'name_ar' => $this->name_ar,
            'name_en' => $this->name_en,
            'plural_name_ar' => $this->plural_name_ar,
            'plural_name_en' => $this->plural_name_en,
            'level_order' => $this->level_order,
            'parent_level_id' => $this->parent_level_id,
            'icon' => $this->icon,
            'metadata_schema' => $this->metadata_schema,
            ...array_map(fn ($f) => (bool) $this->{$f}, array_combine(self::BEHAVIOUR_FLAGS, self::BEHAVIOUR_FLAGS)),
        ];
    }
}
