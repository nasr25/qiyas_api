<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Append-only. Never update or delete a WorkflowDecision — see
 * WorkflowService, the only writer of this table.
 */
class WorkflowDecision extends Model
{
    use HasFactory;

    protected $fillable = [
        'compliance_program_id', 'program_cycle_id', 'evidence_submission_id',
        'stage', 'decision', 'reviewer_id', 'reviewer_role', 'notes',
        'rejection_reason', 'decided_at', 'metadata',
    ];

    protected function casts(): array
    {
        return [
            'decided_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    public function submission()
    {
        return $this->belongsTo(EvidenceSubmission::class, 'evidence_submission_id');
    }

    public function reviewer()
    {
        return $this->belongsTo(User::class, 'reviewer_id');
    }

    public function isApproved(): bool
    {
        return $this->decision === 'approved';
    }
}
