<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Append-only timeline row. Written exclusively by WorkflowService /
 * ExtensionService / SlaService — never by a controller directly.
 */
class WorkflowEvent extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'compliance_program_id', 'program_cycle_id', 'requirement_assignment_id',
        'evidence_submission_id', 'event_type', 'user_id', 'role', 'notes',
        'old_status', 'new_status', 'evidence_version', 'metadata', 'created_at',
    ];

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function assignment()
    {
        return $this->belongsTo(RequirementAssignment::class, 'requirement_assignment_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
