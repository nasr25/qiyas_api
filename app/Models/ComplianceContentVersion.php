<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A framework's official-content version (e.g. an ECC controls catalog
 * release) — see docs/programs/ecc/content-versioning.md. A cycle is tied
 * to exactly one content version for its entire lifetime
 * (AssessmentCycle::content_version_id); publishing a new version never
 * alters an existing cycle's hierarchy.
 */
class ComplianceContentVersion extends Model
{
    protected $fillable = [
        'compliance_program_id', 'version_label', 'source_name', 'source_date',
        'imported_by', 'file_hash', 'template_version', 'status', 'effective_date',
        'previous_version_id', 'change_summary',
    ];

    protected function casts(): array
    {
        return [
            'source_date' => 'date',
            'effective_date' => 'date',
        ];
    }

    public function program()
    {
        return $this->belongsTo(ComplianceProgram::class, 'compliance_program_id');
    }

    public function importer()
    {
        return $this->belongsTo(User::class, 'imported_by');
    }

    public function previousVersion()
    {
        return $this->belongsTo(self::class, 'previous_version_id');
    }

    public function nodes()
    {
        return $this->hasMany(ComplianceNode::class, 'content_version_id');
    }

    public function cycles()
    {
        return $this->hasMany(AssessmentCycle::class, 'content_version_id');
    }

    public function scopeForProgram($query, ComplianceProgram $program)
    {
        return $query->where('compliance_program_id', $program->id);
    }
}
