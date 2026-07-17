<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WorkflowTransitionDefinition extends Model
{
    protected $fillable = [
        'workflow_definition_id', 'from_stage_key', 'action', 'to_stage_key',
    ];

    public function definition()
    {
        return $this->belongsTo(WorkflowDefinition::class, 'workflow_definition_id');
    }
}
