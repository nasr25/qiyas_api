<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WorkflowDefinition extends Model
{
    protected $fillable = [
        'compliance_program_id', 'key', 'name_ar', 'name_en', 'version', 'is_active',
    ];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function program()
    {
        return $this->belongsTo(ComplianceProgram::class, 'compliance_program_id');
    }

    public function stages()
    {
        return $this->hasMany(WorkflowStageDefinition::class)->orderBy('sort_order');
    }

    public function transitions()
    {
        return $this->hasMany(WorkflowTransitionDefinition::class);
    }
}
