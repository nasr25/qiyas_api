<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * AssessmentCycle represents an annual Qiyas assessment period.
 * Only one cycle can be Active at a time.
 */
class AssessmentCycle extends Model
{
    use HasFactory;

    protected $fillable = [
        'name', 'year', 'start_date', 'end_date',
        'status', 'final_score', 'closing_notes',
        'activated_at', 'closed_at', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'start_date'   => 'date',
            'end_date'     => 'date',
            'activated_at' => 'datetime',
            'closed_at'    => 'datetime',
            'final_score'  => 'decimal:2',
        ];
    }

    // ─── Relationships ───────────────────────────────────────────────────────

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function standards()
    {
        return $this->hasMany(Standard::class, 'cycle_id');
    }

    public function documents()
    {
        return $this->hasMany(Document::class, 'cycle_id');
    }

    // ─── Helpers ─────────────────────────────────────────────────────────────

    /** Returns true if this cycle is currently active. */
    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    /** Returns true if this cycle is closed or archived (read-only). */
    public function isReadOnly(): bool
    {
        return in_array($this->status, ['closed', 'archived']);
    }

    // ─── Scopes ──────────────────────────────────────────────────────────────

    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    public function scopeDraft($query)
    {
        return $query->where('status', 'draft');
    }
}
