<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * The current, active configuration value for one (program, category) pair.
 * Never write to `value`/`version` directly — always go through
 * ProgramConfigurationService::set(), which validates, versions, and
 * audits the change. See docs/program-configuration.md.
 */
class ProgramConfiguration extends Model
{
    /** The only categories the engine accepts — see docs/program-configuration.md. */
    public const CATEGORIES = [
        'identity', 'terminology', 'hierarchy', 'workflow', 'assignment',
        'evidence', 'review', 'deadlines', 'extensions', 'sla',
        'notifications', 'import', 'export', 'dashboards', 'reports',
        'scoring', 'security', 'features',
    ];

    protected $fillable = [
        'compliance_program_id', 'category', 'value', 'version', 'is_active', 'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'value' => 'array',
            'is_active' => 'boolean',
        ];
    }

    public function program()
    {
        return $this->belongsTo(ComplianceProgram::class, 'compliance_program_id');
    }

    public function updater()
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function versions()
    {
        return $this->hasMany(ProgramConfigurationVersion::class, 'compliance_program_id', 'compliance_program_id')
            ->where('category', $this->category);
    }
}
