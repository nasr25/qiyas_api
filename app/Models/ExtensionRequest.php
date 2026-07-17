<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * An Employee-requested due-date extension, decided by an Auditor only.
 * Two shapes coexist on this table: legacy (document_id set, Phase 1
 * Document-based flow) and Phase 2 (requirement_assignment_id set, the
 * RequirementAssignment/EvidenceSubmission workflow). See
 * docs/extension-request-workflow.md.
 */
class ExtensionRequest extends Model
{
    use HasFactory;

    public const STATUS_PENDING = 'pending';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_REJECTED = 'rejected';

    public const STATUS_CANCELLED = 'cancelled';

    protected $fillable = [
        'document_id', 'requirement_assignment_id', 'compliance_program_id', 'program_cycle_id',
        'requested_by', 'requested_date', 'current_due_date', 'requested_due_date',
        'reason', 'status', 'reviewed_by', 'reviewed_at', 'reviewer_notes',
    ];

    protected function casts(): array
    {
        return [
            'requested_date' => 'date',
            'current_due_date' => 'date',
            'requested_due_date' => 'date',
            'reviewed_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $extensionRequest) {
            if (! $extensionRequest->compliance_program_id && $extensionRequest->document_id) {
                $extensionRequest->compliance_program_id = Document::whereKey($extensionRequest->document_id)
                    ->value('compliance_program_id');
            }
        });
    }

    // ─── Relationships ───────────────────────────────────────────────────────

    public function document()
    {
        return $this->belongsTo(Document::class);
    }

    public function assignment()
    {
        return $this->belongsTo(RequirementAssignment::class, 'requirement_assignment_id');
    }

    public function program()
    {
        return $this->belongsTo(ComplianceProgram::class, 'compliance_program_id');
    }

    public function cycle()
    {
        return $this->belongsTo(AssessmentCycle::class, 'program_cycle_id');
    }

    public function requester()
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function reviewer()
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    // ─── Helpers ─────────────────────────────────────────────────────────────

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    public function isApproved(): bool
    {
        return $this->status === self::STATUS_APPROVED;
    }

    public function isRejected(): bool
    {
        return $this->status === self::STATUS_REJECTED;
    }

    public function scopePending($query)
    {
        return $query->where('status', self::STATUS_PENDING);
    }
}
