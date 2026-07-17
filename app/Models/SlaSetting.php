<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One row per ComplianceProgram. See docs/sla-design.md.
 */
class SlaSetting extends Model
{
    protected $fillable = [
        'compliance_program_id',
        'employee_submission_sla_value', 'employee_submission_sla_unit',
        'department_manager_review_sla_value', 'department_manager_review_sla_unit',
        'auditor_review_sla_value', 'auditor_review_sla_unit',
        'program_manager_review_sla_value', 'program_manager_review_sla_unit',
        'use_business_days', 'working_days', 'working_day_start', 'working_day_end', 'timezone',
        'pause_sla_during_returned_revision', 'pause_sla_during_pending_extension',
        'warning_threshold_percentage', 'is_enabled', 'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'working_days' => 'array',
            'use_business_days' => 'boolean',
            'pause_sla_during_returned_revision' => 'boolean',
            'pause_sla_during_pending_extension' => 'boolean',
            'is_enabled' => 'boolean',
        ];
    }

    public function program()
    {
        return $this->belongsTo(ComplianceProgram::class, 'compliance_program_id');
    }

    /** Value/unit pair for a given stage, as ['value' => int, 'unit' => 'hours'|'days']. */
    public function forStage(string $stage): array
    {
        return match ($stage) {
            'employee' => ['value' => $this->employee_submission_sla_value, 'unit' => $this->employee_submission_sla_unit],
            'department_manager' => ['value' => $this->department_manager_review_sla_value, 'unit' => $this->department_manager_review_sla_unit],
            'auditor' => ['value' => $this->auditor_review_sla_value, 'unit' => $this->auditor_review_sla_unit],
            'program_manager' => ['value' => $this->program_manager_review_sla_value, 'unit' => $this->program_manager_review_sla_unit],
            default => ['value' => 0, 'unit' => 'days'],
        };
    }

    public static function defaultWorkingDays(): array
    {
        // Sunday(0) - Thursday(4): regional default, fully configurable per program.
        return [0, 1, 2, 3, 4];
    }
}
