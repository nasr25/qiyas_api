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
        'document_id', 'requested_by', 'requested_date',
        'reason', 'status', 'reviewed_by', 'reviewed_at', 'reviewer_notes',
    ];

    protected function casts(): array
    {
        return [
            'requested_date' => 'date',
            'reviewed_at'    => 'datetime',
        ];
    }

    // ─── Relationships ───────────────────────────────────────────────────────

    public function document()
    {
        return $this->belongsTo(Document::class);
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

    public function isPending(): bool  { return $this->status === 'pending'; }
    public function isApproved(): bool { return $this->status === 'approved'; }
    public function isRejected(): bool { return $this->status === 'rejected'; }
}
