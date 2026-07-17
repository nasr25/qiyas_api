<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * ExtensionRequest allows departments to request deadline extensions.
 * Must be approved by an Auditor.
 */
class ExtensionRequest extends Model
{
    use HasFactory;

    protected $fillable = [
        'document_id', 'compliance_program_id', 'requested_by', 'requested_date',
        'reason', 'status', 'reviewed_by', 'reviewed_at', 'reviewer_notes',
    ];

    protected function casts(): array
    {
        return [
            'requested_date' => 'date',
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

    public function program()
    {
        return $this->belongsTo(ComplianceProgram::class, 'compliance_program_id');
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
        return $this->status === 'pending';
    }

    public function isApproved(): bool
    {
        return $this->status === 'approved';
    }

    public function isRejected(): bool
    {
        return $this->status === 'rejected';
    }
}
