<?php

namespace App\Http\Controllers\Api\Workflow;

use App\Http\Controllers\Controller;
use App\Models\ComplianceProgram;
use App\Services\AuditService;
use App\Services\SlaService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Program-scoped SLA configuration. Only that program's Program Manager
 * (or Super Admin) may view/edit — see docs/sla-design.md.
 */
class SlaSettingController extends Controller
{
    public function __construct(private readonly SlaService $sla) {}

    /** GET /api/v1/programs/{program}/sla-settings */
    public function show(Request $request): JsonResponse
    {
        $program = $this->program($request);
        $setting = $this->sla->settingsFor($program);

        if ($request->user()->cannot('manage', $setting)) {
            return response()->json(['success' => false, 'message' => 'Forbidden.'], 403);
        }

        return response()->json(['success' => true, 'data' => $setting]);
    }

    /** PUT /api/v1/programs/{program}/sla-settings */
    public function update(Request $request): JsonResponse
    {
        $program = $this->program($request);
        $setting = $this->sla->settingsFor($program);

        if ($request->user()->cannot('manage', $setting)) {
            return response()->json(['success' => false, 'message' => 'Forbidden.'], 403);
        }

        $data = $request->validate([
            'employee_submission_sla_value' => ['required', 'integer', 'min:1'],
            'employee_submission_sla_unit' => ['required', 'in:hours,days'],
            'department_manager_review_sla_value' => ['required', 'integer', 'min:1'],
            'department_manager_review_sla_unit' => ['required', 'in:hours,days'],
            'auditor_review_sla_value' => ['required', 'integer', 'min:1'],
            'auditor_review_sla_unit' => ['required', 'in:hours,days'],
            'program_manager_review_sla_value' => ['required', 'integer', 'min:1'],
            'program_manager_review_sla_unit' => ['required', 'in:hours,days'],
            'use_business_days' => ['required', 'boolean'],
            'working_days' => ['required', 'array', 'min:1'],
            'working_days.*' => ['integer', 'min:0', 'max:6'],
            'working_day_start' => ['required', 'date_format:H:i'],
            'working_day_end' => ['required', 'date_format:H:i', 'after:working_day_start'],
            'timezone' => ['required', 'string', 'timezone'],
            'pause_sla_during_returned_revision' => ['required', 'boolean'],
            'pause_sla_during_pending_extension' => ['required', 'boolean'],
            'warning_threshold_percentage' => ['required', 'integer', 'min:1', 'max:100'],
            'is_enabled' => ['required', 'boolean'],
        ]);

        $old = $setting->getAttributes();
        $setting->update(array_merge($data, ['updated_by' => $request->user()->id]));

        AuditService::log('sla_settings.updated', "Updated SLA settings for program '{$program->code}'", $setting, $old, $data, complianceProgramId: $program->id);

        return response()->json(['success' => true, 'data' => $setting->fresh()]);
    }

    private function program(Request $request): ComplianceProgram
    {
        return $request->attributes->get('compliance_program');
    }
}
