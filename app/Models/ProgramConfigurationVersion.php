<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Append-only history row for a single program_configurations change —
 * never updated or deleted. Written by ProgramConfigurationService::set().
 */
class ProgramConfigurationVersion extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'compliance_program_id', 'category', 'version', 'value', 'changed_by', 'created_at',
    ];

    protected function casts(): array
    {
        return [
            'value' => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function program()
    {
        return $this->belongsTo(ComplianceProgram::class, 'compliance_program_id');
    }

    public function changedBy()
    {
        return $this->belongsTo(User::class, 'changed_by');
    }
}
