<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Document tracks a department's submission for an evidence requirement.
 * Supports full versioning history.
 */
class Document extends Model
{
    use HasFactory;

    protected $fillable = [
        'requirement_id', 'department_id', 'cycle_id', 'title',
        'status', 'current_version',
        'submitted_by', 'submitted_at',
        'reviewed_by', 'reviewed_at',
        'rejection_reason',
    ];

    protected function casts(): array
    {
        return [
            'submitted_at' => 'datetime',
            'reviewed_at'  => 'datetime',
        ];
    }

    // ─── Relationships ───────────────────────────────────────────────────────

    public function requirement()
    {
        return $this->belongsTo(EvidenceRequirement::class, 'requirement_id');
    }

    public function department()
    {
        return $this->belongsTo(Department::class);
    }

    public function cycle()
    {
        return $this->belongsTo(AssessmentCycle::class, 'cycle_id');
    }

    public function submitter()
    {
        return $this->belongsTo(User::class, 'submitted_by');
    }

    public function reviewer()
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function versions()
    {
        return $this->hasMany(DocumentVersion::class)->orderByDesc('version_number');
    }

    public function currentVersionFile()
    {
        return $this->hasOne(DocumentVersion::class)->ofMany('version_number', 'max');
    }

    public function extensionRequests()
    {
        return $this->hasMany(ExtensionRequest::class);
    }

    public function comments()
    {
        return $this->morphMany(Comment::class, 'commentable')->whereNull('parent_id')->orderBy('created_at');
    }

    // ─── Helpers ─────────────────────────────────────────────────────────────

    public function isDraft(): bool      { return $this->status === 'draft'; }
    public function isUnderReview(): bool { return $this->status === 'under_review'; }
    public function isApproved(): bool   { return $this->status === 'approved'; }
    public function isRejected(): bool   { return $this->status === 'rejected'; }
    public function isOverdue(): bool    { return $this->status === 'overdue'; }
}
