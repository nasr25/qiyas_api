<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * AssessmentCycle represents an annual Qiyas assessment period and serves as
 * the ProgramCycle entity in the generic hierarchy (ComplianceProgram →
 * ProgramCycle → ...). Only one cycle per program can be current at a time.
 */
class AssessmentCycle extends Model
{
    use HasFactory;

    protected $fillable = [
        'compliance_program_id', 'name', 'name_ar', 'name_en', 'year', 'start_date', 'end_date',
        'status', 'is_current', 'final_score', 'closing_notes', 'settings',
        'activated_at', 'closed_at', 'closed_by', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'start_date' => 'date',
            'end_date' => 'date',
            'activated_at' => 'datetime',
            'closed_at' => 'datetime',
            'final_score' => 'decimal:2',
            'is_current' => 'boolean',
            'settings' => 'array',
        ];
    }

    // ─── Relationships ───────────────────────────────────────────────────────

    public function program()
    {
        return $this->belongsTo(ComplianceProgram::class, 'compliance_program_id');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function closer()
    {
        return $this->belongsTo(User::class, 'closed_by');
    }

    public function standards()
    {
        return $this->hasMany(Standard::class, 'cycle_id');
    }

    public function documents()
    {
        return $this->hasMany(Document::class, 'cycle_id');
    }

    // ─── Helpers ─────────────────────────────────────────────────────────────

    /** Returns true if this cycle is currently active. */
    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    /** Returns true if this cycle is closed or archived (read-only). */
    public function isReadOnly(): bool
    {
        return in_array($this->status, ['closed', 'archived']);
    }

    // ─── Scopes ──────────────────────────────────────────────────────────────

    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    public function scopeDraft($query)
    {
        return $query->where('status', 'draft');
    }

    public function scopeForProgram($query, ComplianceProgram $program)
    {
        return $query->where('compliance_program_id', $program->id);
    }
}
