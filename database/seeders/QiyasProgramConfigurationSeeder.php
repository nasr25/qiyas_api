<?php

namespace Database\Seeders;

use App\Models\ComplianceProgram;
use App\Services\ProgramConfigurationService;
use Illuminate\Database\Seeder;

/**
 * Seeds Qiyas's program_configurations rows from the values that were
 * previously hardcoded across EvidenceUploadValidator, ExtensionRequestPolicy,
 * and the frontend i18n files. Every value below is transcribed from the
 * pre-Phase-4 code, not invented — see docs/qiyas-engine-configuration.md
 * for the exact source of each value.
 */
class QiyasProgramConfigurationSeeder extends Seeder
{
    public function run(): void
    {
        $program = ComplianceProgram::where('code', 'QIYAS')->first();
        if (! $program) {
            return;
        }

        $service = app(ProgramConfigurationService::class);

        // Transcribed from the Arabic/English labels already used
        // throughout docs/qiyas-workflow.md and the frontend i18n files.
        $service->set($program, 'terminology', [
            'domain' => ['ar' => 'المنظور', 'en' => 'Perspective'],
            'category' => ['ar' => 'المحور', 'en' => 'Axis'],
            'requirement' => ['ar' => 'المعيار', 'en' => 'Standard'],
            'evidence' => ['ar' => 'مستند الإثبات', 'en' => 'Evidence Document'],
            'cycle' => ['ar' => 'دورة القياس', 'en' => 'Qiyas Cycle'],
        ]);

        // Transcribed from the pre-Phase-4 hardcoded literal 'auditor' role
        // key in ExtensionRequestPolicy::decide() — see finding #3 in
        // docs/compliance-engine-migration.md. Behavior is unchanged; only
        // the mechanism moved from code to configuration.
        $service->set($program, 'extensions', [
            'requester_role' => 'employee',
            'reviewer_role' => 'auditor',
            'rejection_reason_required' => true,
            'allow_multiple_pending' => false,
        ]);

        // Transcribed from EvidenceUploadValidator's DEFAULT_* constants
        // (which were themselves overridable only through the
        // platform-wide `Setting` group — now program-scoped instead).
        $service->set($program, 'evidence', [
            'allowed_extensions' => ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'zip', 'jpg', 'jpeg', 'png'],
            'max_file_size_mb' => 20,
            'max_files_per_submission' => 10,
            'max_total_submission_size_mb' => 100,
        ]);

        // Transcribed from WorkflowService::assign()'s actual parameter
        // requirements (department is a required argument; employee, due
        // date, priority, and instructions are all nullable).
        $service->set($program, 'assignment', [
            'department_required' => true,
            'employee_assignment_required' => false,
            'reassignment_reason_required' => true,
            'due_date_required' => false,
        ]);

        // Every feature Qiyas currently uses is enabled; none are new.
        $service->set($program, 'features', [
            'evidence_enabled' => true,
            'extension_requests_enabled' => true,
            'sla_enabled' => true,
            'xlsx_import_enabled' => true,
            'xlsx_export_enabled' => true,
            'employee_assignment_enabled' => true,
            'in_app_notifications_enabled' => true,
            'email_notifications_enabled' => true,
            'scoring_enabled' => true,
            'executive_dashboard_enabled' => true,
        ]);
    }
}
