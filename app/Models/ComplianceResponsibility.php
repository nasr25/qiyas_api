<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A purely informational responsibility label (Data Owner, Data Steward,
 * Supporting Department, Observer, ...) attached to a RequirementAssignment
 * — see the migration's class doc for why this NEVER grants workflow
 * authority on its own. Only `program_user_roles` (checked via
 * User::hasProgramRole()) authorizes workflow actions.
 */
class ComplianceResponsibility extends Model
{
    protected $fillable = [
        'compliance_program_id', 'program_cycle_id', 'requirement_assignment_id',
        'department_id', 'user_id', 'responsibility_type',
        'start_date', 'end_date', 'is_active', 'reason', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'start_date' => 'date',
            'end_date' => 'date',
            'is_active' => 'boolean',
        ];
    }

    public function program()
    {
        return $this->belongsTo(ComplianceProgram::class, 'compliance_program_id');
    }

    public function cycle()
    {
        return $this->belongsTo(AssessmentCycle::class, 'program_cycle_id');
    }

    public function assignment()
    {
        return $this->belongsTo(RequirementAssignment::class, 'requirement_assignment_id');
    }

    public function department()
    {
        return $this->belongsTo(Department::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }
}
