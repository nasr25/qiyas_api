<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * ComplianceProgram is the top-level entity of the multi-program platform.
 * Every ProgramCycle (AssessmentCycle) and its Requirements (Standards),
 * Evidence Submissions (Documents) and related records belong to exactly
 * one ComplianceProgram. Only QIYAS is active in Phase 1.
 */
class ComplianceProgram extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'code', 'name_ar', 'name_en', 'description_ar', 'description_en',
        'logo', 'icon', 'status', 'sort_order', 'primary_color', 'secondary_color',
        'settings', 'is_active', 'created_by', 'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'settings' => 'array',
            'is_active' => 'boolean',
        ];
    }

    // ─── Relationships ───────────────────────────────────────────────────────

    public function cycles()
    {
        return $this->hasMany(AssessmentCycle::class, 'compliance_program_id');
    }

    public function standards()
    {
        return $this->hasMany(Standard::class, 'compliance_program_id');
    }

    public function documents()
    {
        return $this->hasMany(Document::class, 'compliance_program_id');
    }

    public function userRoles()
    {
        return $this->hasMany(ProgramUserRole::class, 'compliance_program_id');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    // ─── Accessors ───────────────────────────────────────────────────────────

    public function getNameAttribute(): string
    {
        return app()->getLocale() === 'ar'
            ? ($this->name_ar ?: $this->name_en ?: '')
            : ($this->name_en ?: $this->name_ar ?: '');
    }

    public function getDescriptionAttribute(): ?string
    {
        return app()->getLocale() === 'ar'
            ? ($this->description_ar ?: $this->description_en)
            : ($this->description_en ?: $this->description_ar);
    }

    /** Program-specific terminology labels (Domain/Category/Requirement/...), keyed by field then locale. */
    public function getTerminologyAttribute(): array
    {
        return $this->settings['terminology'] ?? [];
    }

    public function currentCycle()
    {
        return $this->hasOne(AssessmentCycle::class, 'compliance_program_id')->where('is_current', true);
    }

    // ─── Scopes ──────────────────────────────────────────────────────────────

    public function scopeActive($query)
    {
        return $query->where('is_active', true)->where('status', 'active');
    }
}
