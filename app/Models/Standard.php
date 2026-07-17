<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Standard represents a Qiyas compliance standard within an assessment cycle.
 */
class Standard extends Model
{
    use HasFactory;

    protected $fillable = [
        'cycle_id', 'compliance_program_id', 'standard_number', 'name_ar', 'name_en',
        'description', 'version', 'weight', 'due_date', 'is_active',
        // DGA Qiyas catalog fields
        'perspective', 'axis', 'application_requirements',
        'evidence_documents', 'scope', 'related_references', 'status',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'due_date' => 'date',
            'weight' => 'decimal:2',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $standard) {
            if (! $standard->compliance_program_id && $standard->cycle_id) {
                $standard->compliance_program_id = AssessmentCycle::whereKey($standard->cycle_id)
                    ->value('compliance_program_id');
            }
        });
    }

    // ─── Relationships ───────────────────────────────────────────────────────

    public function cycle()
    {
        return $this->belongsTo(AssessmentCycle::class, 'cycle_id');
    }

    public function program()
    {
        return $this->belongsTo(ComplianceProgram::class, 'compliance_program_id');
    }

    public function departments()
    {
        // Pivot tracks its own audit columns (assigned_at / assigned_by) and has
        // no created_at/updated_at, so withTimestamps() must NOT be used here —
        // it would try to write non-existent columns and break attach()/sync().
        return $this->belongsToMany(Department::class, 'department_standard')
            ->withPivot(['assigned_at', 'assigned_by']);
    }

    public function evidenceRequirements()
    {
        return $this->hasMany(EvidenceRequirement::class)->orderBy('sort_order');
    }

    public function assignments()
    {
        return $this->hasMany(RequirementAssignment::class, 'requirement_id');
    }

    /** The single current active assignment, if any (one primary department per requirement). */
    public function activeAssignment()
    {
        return $this->hasOne(RequirementAssignment::class, 'requirement_id')->where('status', 'active');
    }

    // ─── Accessors ───────────────────────────────────────────────────────────

    public function getNameAttribute(): string
    {
        // Fall back to the other language when the preferred one is empty
        // (imported Qiyas standards are Arabic-only, so name_en is null).
        return app()->getLocale() === 'ar'
            ? ($this->name_ar ?: $this->name_en ?: '')
            : ($this->name_en ?: $this->name_ar ?: '');
    }
}
