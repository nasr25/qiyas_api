<?php

namespace Database\Seeders;

use App\Models\ComplianceProgram;
use App\Models\WorkflowDefinition;
use Illuminate\Database\Seeder;

/**
 * Seeds ECC's initial workflow as its OWN workflow_definitions/
 * workflow_stage_definitions/workflow_transition_definitions rows —
 * entirely separate records from Qiyas's and Sumoud's. This is an
 * ORGANIZATIONAL IMPLEMENTATION WORKFLOW, not a claim about the official
 * ECC regulatory assessment procedure — see docs/programs/ecc/workflow.md.
 * Same initial pattern as Qiyas/Sumoud (Employee -> Department Manager ->
 * Auditor -> Program Manager, rejection always returns to Employee,
 * resubmission always restarts at Department Manager) per the Phase 6
 * brief's explicit instruction, until an approved ECC-specific workflow is
 * supplied.
 */
class ECCWorkflowDefinitionSeeder extends Seeder
{
    public function run(): void
    {
        $program = ComplianceProgram::where('code', 'ECC')->first();
        if (! $program) {
            return;
        }

        $definition = WorkflowDefinition::updateOrCreate(
            ['compliance_program_id' => $program->id, 'key' => 'requirement_review'],
            ['name_ar' => 'مراجعة الضابط', 'name_en' => 'Control Review', 'version' => 1, 'is_active' => true],
        );

        $stages = [
            ['stage_key' => 'employee', 'sort_order' => 0, 'name_ar' => 'مالك الضابط', 'name_en' => 'Control Owner', 'responsible_role_key' => 'employee', 'requires_comment' => false, 'requires_rejection_reason' => false, 'sla_applies' => true, 'notifications_enabled' => true, 'is_final' => false],
            ['stage_key' => 'department_manager', 'sort_order' => 1, 'name_ar' => 'مدير الإدارة', 'name_en' => 'Department Manager', 'responsible_role_key' => 'department-manager', 'requires_comment' => false, 'requires_rejection_reason' => true, 'sla_applies' => true, 'notifications_enabled' => true, 'is_final' => false],
            ['stage_key' => 'auditor', 'sort_order' => 2, 'name_ar' => 'مدقق الضوابط', 'name_en' => 'ECC Auditor', 'responsible_role_key' => 'auditor', 'requires_comment' => false, 'requires_rejection_reason' => true, 'sla_applies' => true, 'notifications_enabled' => true, 'is_final' => false],
            ['stage_key' => 'program_manager', 'sort_order' => 3, 'name_ar' => 'مدير البرنامج', 'name_en' => 'ECC Program Manager', 'responsible_role_key' => 'program-manager', 'requires_comment' => false, 'requires_rejection_reason' => true, 'sla_applies' => true, 'notifications_enabled' => true, 'is_final' => false],
            ['stage_key' => 'approved', 'sort_order' => 4, 'name_ar' => 'معتمد', 'name_en' => 'Approved', 'responsible_role_key' => null, 'requires_comment' => false, 'requires_rejection_reason' => false, 'sla_applies' => false, 'notifications_enabled' => true, 'is_final' => true],
        ];

        foreach ($stages as $stage) {
            $definition->stages()->updateOrCreate(['stage_key' => $stage['stage_key']], $stage);
        }

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
