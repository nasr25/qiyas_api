<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WorkflowStageDefinition extends Model
{
    protected $fillable = [
        'workflow_definition_id', 'stage_key', 'sort_order', 'name_ar', 'name_en',
        'responsible_role_key', 'requires_comment', 'requires_rejection_reason',
        'sla_applies', 'notifications_enabled', 'is_final',
    ];

    protected function casts(): array
    {
        return [
            'requires_comment' => 'boolean',
            'requires_rejection_reason' => 'boolean',
            'sla_applies' => 'boolean',
            'notifications_enabled' => 'boolean',
            'is_final' => 'boolean',
        ];
    }

    public function definition()
    {
        return $this->belongsTo(WorkflowDefinition::class, 'workflow_definition_id');
    }
}
