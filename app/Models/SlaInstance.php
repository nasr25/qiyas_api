<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One row per stage occurrence. See docs/sla-design.md. Written by
 * SlaService only.
 */
class SlaInstance extends Model
{
    protected $fillable = [
        'compliance_program_id', 'program_cycle_id', 'requirement_assignment_id', 'evidence_submission_id',
        'stage', 'responsible_user_id', 'responsible_department_id',
        'started_at', 'due_at', 'completed_at', 'breached_at', 'status',
        'elapsed_minutes', 'business_elapsed_minutes', 'settings_snapshot',
    ];

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'due_at' => 'datetime',
            'completed_at' => 'datetime',
            'breached_at' => 'datetime',
            'settings_snapshot' => 'array',
        ];
    }

    public function assignment()
    {
        return $this->belongsTo(RequirementAssignment::class, 'requirement_assignment_id');
    }

    public function submission()
    {
        return $this->belongsTo(EvidenceSubmission::class, 'evidence_submission_id');
    }

    public function responsibleUser()
    {
        return $this->belongsTo(User::class, 'responsible_user_id');
    }

    public function responsibleDepartment()
    {
        return $this->belongsTo(Department::class, 'responsible_department_id');
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    public function isBreached(): bool
    {
        return $this->status === 'breached' || ($this->isActive() && $this->due_at && now()->greaterThan($this->due_at));
    }

    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }
}
