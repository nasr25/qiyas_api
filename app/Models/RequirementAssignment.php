<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Gives one Department (and optionally one Employee) primary responsibility
 * for a Requirement within a ProgramCycle. See docs/qiyas-workflow.md.
 */
class RequirementAssignment extends Model
{
    use HasFactory;

    protected $fillable = [
        'compliance_program_id', 'program_cycle_id', 'requirement_id', 'department_id',
        'employee_id', 'assigned_by', 'assigned_at', 'original_due_date', 'effective_due_date',
        'status', 'priority', 'instructions_ar', 'instructions_en',
        'previous_assignment_id', 'reassignment_reason', 'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'assigned_at' => 'datetime',
            'original_due_date' => 'date',
            'effective_due_date' => 'date',
            'completed_at' => 'datetime',
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

    public function requirement()
    {
        return $this->belongsTo(Standard::class, 'requirement_id');
    }

    public function department()
    {
        return $this->belongsTo(Department::class);
    }

    public function employee()
    {
        return $this->belongsTo(User::class, 'employee_id');
    }

    public function assignedBy()
    {
        return $this->belongsTo(User::class, 'assigned_by');
    }

    public function previousAssignment()
    {
        return $this->belongsTo(self::class, 'previous_assignment_id');
    }

    public function submissions()
    {
        return $this->hasMany(EvidenceSubmission::class)->orderByDesc('version_number');
    }

    public function currentSubmission()
    {
        return $this->hasOne(EvidenceSubmission::class)->ofMany('version_number', 'max');
    }

    public function events()
    {
        return $this->hasMany(WorkflowEvent::class)->orderBy('created_at');
    }

    public function extensionRequests()
    {
        return $this->hasMany(ExtensionRequest::class);
    }

    public function slaInstances()
    {
        return $this->hasMany(SlaInstance::class);
    }

    // ─── Helpers ─────────────────────────────────────────────────────────────

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    /** Combined display status: unassigned/assigned are assignment-level, everything else comes from the latest submission. */
    public function displayStatus(): string
    {
        $submission = $this->relationLoaded('currentSubmission') ? $this->currentSubmission : $this->currentSubmission()->first();

        return $submission?->status ?? 'assigned';
    }

    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    public function scopeForProgram($query, ComplianceProgram $program)
    {
        return $query->where('compliance_program_id', $program->id);
    }
}
