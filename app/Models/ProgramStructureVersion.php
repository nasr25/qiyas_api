<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Immutable frozen snapshot of a hierarchy definition at activation time.
 * Never updated after insert except status active -> superseded.
 *
 * Exists so historical reporting stays reproducible after a Program Manager
 * renames or reorders a level (audit finding C5). A cycle pins itself here
 * via assessment_cycles.structure_version_id; a saved report should record
 * the same id so it can be flagged for review when the structure moves on.
 */
class ProgramStructureVersion extends Model
{
    protected $fillable = [
        'compliance_program_id', 'hierarchy_definition_id', 'version',
        'snapshot', 'status', 'activated_at', 'created_by', 'change_summary',
    ];

    protected function casts(): array
    {
        return [
            'snapshot' => 'array',
            'activated_at' => 'datetime',
            'version' => 'integer',
        ];
    }

    public function program()
    {
        return $this->belongsTo(ComplianceProgram::class, 'compliance_program_id');
    }

    public function definition()
    {
        return $this->belongsTo(HierarchyDefinition::class, 'hierarchy_definition_id');
    }

    public function cycles()
    {
        return $this->hasMany(AssessmentCycle::class, 'structure_version_id');
    }

    public function scopeForProgram($query, ComplianceProgram $program)
    {
        return $query->where('compliance_program_id', $program->id);
    }

    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    /** @return array<int, array<string, mixed>> the frozen level list, shallowest first */
    public function levels(): array
    {
        return $this->snapshot['levels'] ?? [];
    }
}
