<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Department model representing organizational units.
 */
class Department extends Model
{
    use HasFactory;

    protected $fillable = ['name_ar', 'name_en', 'description', 'is_active'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    // ─── Relationships ───────────────────────────────────────────────────────

    public function users()
    {
        return $this->hasMany(User::class);
    }

    public function standards()
    {
        // Pivot has no created_at/updated_at (uses assigned_at / assigned_by),
        // so withTimestamps() must not be used — it breaks attach()/sync().
        return $this->belongsToMany(Standard::class, 'department_standard')
            ->withPivot(['assigned_at', 'assigned_by']);
    }

    public function documents()
    {
        return $this->hasMany(Document::class);
    }

    // ─── Scopes ──────────────────────────────────────────────────────────────

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    // ─── Accessors ───────────────────────────────────────────────────────────

    public function getNameAttribute(): string
    {
        return app()->getLocale() === 'ar' ? $this->name_ar : $this->name_en;
    }
}
