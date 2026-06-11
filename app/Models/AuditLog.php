<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * AuditLog records all significant user actions for compliance and security.
 */
class AuditLog extends Model
{
    public $timestamps = false;
    public $updatable = false;

    protected $fillable = [
        'user_id', 'role', 'department_id', 'action', 'model_type', 'model_id',
        'old_values', 'new_values', 'description',
        'ip_address', 'user_agent', 'created_at',
    ];

    protected function casts(): array
    {
        return [
            'old_values' => 'array',
            'new_values' => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /** Returns the audited model instance if it still exists. */
    public function auditable()
    {
        return $this->morphTo('model');
    }
}
