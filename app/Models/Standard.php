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
        'cycle_id', 'standard_number', 'name_ar', 'name_en',
        'description', 'version', 'weight', 'due_date', 'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'due_date'  => 'date',
            'weight'    => 'decimal:2',
        ];
    }

    // ─── Relationships ───────────────────────────────────────────────────────

    public function cycle()
    {
        return $this->belongsTo(AssessmentCycle::class, 'cycle_id');
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

    // ─── Accessors ───────────────────────────────────────────────────────────

    public function getNameAttribute(): string
    {
        return app()->getLocale() === 'ar' ? $this->name_ar : $this->name_en;
    }
}
