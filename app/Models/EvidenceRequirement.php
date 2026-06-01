<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * EvidenceRequirement defines what evidence must be provided for a Standard.
 */
class EvidenceRequirement extends Model
{
    use HasFactory;

    protected $fillable = [
        'standard_id', 'title_ar', 'title_en',
        'description', 'is_mandatory', 'sort_order',
    ];

    protected function casts(): array
    {
        return ['is_mandatory' => 'boolean'];
    }

    // ─── Relationships ───────────────────────────────────────────────────────

    public function standard()
    {
        return $this->belongsTo(Standard::class);
    }

    public function documents()
    {
        return $this->hasMany(Document::class, 'requirement_id');
    }

    // ─── Accessors ───────────────────────────────────────────────────────────

    public function getTitleAttribute(): string
    {
        return app()->getLocale() === 'ar' ? $this->title_ar : $this->title_en;
    }
}
