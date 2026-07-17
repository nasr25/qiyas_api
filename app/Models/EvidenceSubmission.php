<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * One VERSION of a requirement's submission. Status is the single
 * authoritative workflow status — see docs/qiyas-workflow.md. Never mutated
 * directly by a controller; all transitions go through WorkflowService.
 */
class EvidenceSubmission extends Model
{
    use HasFactory;

    public const STATUSES = [
        'draft', 'pending_department_manager', 'pending_auditor',
        'pending_program_manager', 'returned_for_revision', 'approved',
    ];

    protected $fillable = [
        'compliance_program_id', 'program_cycle_id', 'requirement_id', 'requirement_assignment_id',
        'department_id', 'submitted_by', 'version_number', 'status', 'current_stage',
        'employee_comment', 'submitted_at', 'returned_at', 'approved_at',
    ];

    protected function casts(): array
    {
        return [
            'submitted_at' => 'datetime',
            'returned_at' => 'datetime',
            'approved_at' => 'datetime',
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

    public function assignment()
    {
        return $this->belongsTo(RequirementAssignment::class, 'requirement_assignment_id');
    }

    public function department()
    {
        return $this->belongsTo(Department::class);
    }

    public function submitter()
    {
        return $this->belongsTo(User::class, 'submitted_by');
    }

    public function files()
    {
        return $this->hasMany(EvidenceFile::class)->orderByDesc('uploaded_at');
    }

    public function activeFiles()
    {
        return $this->hasMany(EvidenceFile::class)->where('is_active', true)->orderBy('uploaded_at');
    }

    public function decisions()
    {
        return $this->hasMany(WorkflowDecision::class)->orderBy('decided_at');
    }

    public function slaInstances()
    {
        return $this->hasMany(SlaInstance::class);
    }

    // ─── Helpers ─────────────────────────────────────────────────────────────

    public function isDraft(): bool
    {
        return $this->status === 'draft';
    }

    public function isEditableByEmployee(): bool
    {
        return in_array($this->status, ['draft', 'returned_for_revision'], true);
    }

    public function isPending(): bool
    {
        return str_starts_with($this->status, 'pending_');
    }
}
