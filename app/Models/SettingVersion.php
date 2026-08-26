<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Append-only settings change history. See the migration's class doc —
 * secret fields never populate old_value/new_value, only secret_action.
 */
class SettingVersion extends Model
{
    protected $fillable = [
        'group', 'key', 'is_secret', 'old_value', 'new_value',
        'secret_action', 'changed_by', 'changed_at', 'reason',
    ];

    protected function casts(): array
    {
        return [
            'is_secret' => 'boolean',
            'changed_at' => 'datetime',
        ];
    }

    public function changedBy()
    {
        return $this->belongsTo(User::class, 'changed_by');
    }

    public function scopeForSetting($query, string $group, string $key)
    {
        return $query->where('group', $group)->where('key', $key);
    }
}
