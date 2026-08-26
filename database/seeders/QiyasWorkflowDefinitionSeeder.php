<?php

namespace Database\Seeders;

use App\Models\ComplianceProgram;
use App\Models\WorkflowDefinition;
use Illuminate\Database\Seeder;

/**
 * Seeds Qiyas's workflow as DATA (workflow_definitions/
 * workflow_stage_definitions/workflow_transition_definitions) instead of
 * the PHP constants WorkflowService used to hardcode — see
 * docs/workflow-engine.md and docs/qiyas-engine-configuration.md. The
 * stages, transitions, and rules below are an exact, deliberate
 * transcription of the pre-Phase-4 NEXT_STAGE/STATUS_FOR_STAGE constants —
 * Qiyas's business process is not changed by this seeder, only its
 * representation.
 */
class QiyasWorkflowDefinitionSeeder extends Seeder
{
    public function run(): void
    {
        $program = ComplianceProgram::where('code', 'QIYAS')->first();
        if (! $program) {
            return;
        }

        $definition = WorkflowDefinition::updateOrCreate(
            ['compliance_program_id' => $program->id, 'key' => 'requirement_review'],
            ['name_ar' => 'مراجعة المعيار', 'name_en' => 'Requirement Review', 'version' => 1, 'is_active' => true],
        );

        $stages = [
            ['stage_key' => 'employee', 'sort_order' => 0, 'name_ar' => 'الموظف', 'name_en' => 'Employee', 'responsible_role_key' => 'employee', 'requires_comment' => false, 'requires_rejection_reason' => false, 'sla_applies' => true, 'notifications_enabled' => true, 'is_final' => false],
            ['stage_key' => 'department_manager', 'sort_order' => 1, 'name_ar' => 'مدير الإدارة', 'name_en' => 'Department Manager', 'responsible_role_key' => 'department-manager', 'requires_comment' => false, 'requires_rejection_reason' => true, 'sla_applies' => true, 'notifications_enabled' => true, 'is_final' => false],
            ['stage_key' => 'auditor', 'sort_order' => 2, 'name_ar' => 'المدقق', 'name_en' => 'Auditor', 'responsible_role_key' => 'auditor', 'requires_comment' => false, 'requires_rejection_reason' => true, 'sla_applies' => true, 'notifications_enabled' => true, 'is_final' => false],
            ['stage_key' => 'program_manager', 'sort_order' => 3, 'name_ar' => 'مدير البرنامج', 'name_en' => 'Program Manager', 'responsible_role_key' => 'program-manager', 'requires_comment' => false, 'requires_rejection_reason' => true, 'sla_applies' => true, 'notifications_enabled' => true, 'is_final' => false],
            ['stage_key' => 'approved', 'sort_order' => 4, 'name_ar' => 'معتمد', 'name_en' => 'Approved', 'responsible_role_key' => null, 'requires_comment' => false, 'requires_rejection_reason' => false, 'sla_applies' => false, 'notifications_enabled' => true, 'is_final' => true],
        ];

        foreach ($stages as $stage) {
            $definition->stages()->updateOrCreate(
                ['stage_key' => $stage['stage_key']],
                $stage,
            );
        }

        // Every rejection returns directly to 'employee', regardless of
        // which review stage rejected it — and every employee submission
        // (first submit or resubmission after correction) always targets
        // 'department_manager', never skipping to whichever stage last
        // rejected it. These two invariants are the Qiyas business rules
        // this phase is explicitly forbidden from changing.
        $transitions = [
            ['from_stage_key' => 'employee', 'action' => 'submit', 'to_stage_key' => 'department_manager'],
            ['from_stage_key' => 'department_manager', 'action' => 'approve', 'to_stage_key' => 'auditor'],
            ['from_stage_key' => 'department_manager', 'action' => 'reject', 'to_stage_key' => 'employee'],
            ['from_stage_key' => 'auditor', 'action' => 'approve', 'to_stage_key' => 'program_manager'],
            ['from_stage_key' => 'auditor', 'action' => 'reject', 'to_stage_key' => 'employee'],
            ['from_stage_key' => 'program_manager', 'action' => 'approve', 'to_stage_key' => 'approved'],
            ['from_stage_key' => 'program_manager', 'action' => 'reject', 'to_stage_key' => 'employee'],
        ];

        foreach ($transitions as $transition) {
            $definition->transitions()->updateOrCreate(
                ['from_stage_key' => $transition['from_stage_key'], 'action' => $transition['action']],
                $transition,
            );
        }
    }
}
