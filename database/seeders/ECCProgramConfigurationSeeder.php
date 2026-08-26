<?php

namespace Database\Seeders;

use App\Models\ComplianceProgram;
use App\Services\ProgramConfigurationService;
use Illuminate\Database\Seeder;

/**
 * Seeds ECC's own program_configurations rows — entirely separate
 * (compliance_program_id, category, version) records from Qiyas's and
 * Sumoud's. The `hierarchy` category is new this phase (Phase 4/5 never
 * needed it — Qiyas/Sumoud use free-text perspective/axis fields) and
 * describes ECC's four-level shape for ComplianceNodeService's validation.
 * See docs/programs/ecc/configuration.md and hierarchy.md.
 */
class ECCProgramConfigurationSeeder extends Seeder
{
    public function run(): void
    {
        $program = ComplianceProgram::where('code', 'ECC')->first();
        if (! $program) {
            return;
        }

        $service = app(ProgramConfigurationService::class);

        $service->set($program, 'terminology', [
            'domain' => ['ar' => 'المجال الرئيسي', 'en' => 'Main Domain'],
            'category' => ['ar' => 'المجال الفرعي', 'en' => 'Subdomain'],
            'requirement' => ['ar' => 'الضابط', 'en' => 'Control'],
            'evidence' => ['ar' => 'مستند الإثبات', 'en' => 'Evidence Document'],
            'cycle' => ['ar' => 'دورة التقييم', 'en' => 'Assessment Cycle'],
        ]);

        // ECC's real shape: Main Domain -> Subdomain -> Control (assessable)
        // -> Subcontrol (assessable). Both Control and Subcontrol are
        // marked assessable so an approved source that stops at Control
        // level still works — ComplianceNodeService never assumes a fixed
        // depth. parent_type=null marks a root level.
        $service->set($program, 'hierarchy', [
            'levels' => [
                ['node_type' => 'domain', 'label_ar' => 'المجال الرئيسي', 'label_en' => 'Main Domain', 'parent_type' => null, 'is_assessable' => false],
                ['node_type' => 'subdomain', 'label_ar' => 'المجال الفرعي', 'label_en' => 'Subdomain', 'parent_type' => 'domain', 'is_assessable' => false],
                ['node_type' => 'control', 'label_ar' => 'الضابط', 'label_en' => 'Control', 'parent_type' => 'subdomain', 'is_assessable' => true],
                ['node_type' => 'subcontrol', 'label_ar' => 'الضابط الفرعي', 'label_en' => 'Subcontrol', 'parent_type' => 'control', 'is_assessable' => true],
            ],
            'max_depth' => 4,
        ]);

        // Same initial pattern as Qiyas/Sumoud — an organizational
        // implementation workflow, not the official regulatory procedure.
        $service->set($program, 'extensions', [
            'requester_role' => 'employee',
            'reviewer_role' => 'auditor',
            'rejection_reason_required' => true,
            'allow_multiple_pending' => false,
        ]);

        // Organizational default, not an official ECC file policy.
        $service->set($program, 'evidence', [
            'allowed_extensions' => ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'zip', 'jpg', 'jpeg', 'png'],
            'max_file_size_mb' => 20,
            'max_files_per_submission' => 10,
            'max_total_submission_size_mb' => 100,
        ]);

        $service->set($program, 'assignment', [
            'department_required' => true,
            'employee_assignment_required' => false,
            'reassignment_reason_required' => true,
            'due_date_required' => false,
        ]);

        // scoring_enabled = false: no approved ECC scoring formula exists.
        // not_applicable_enabled = false: deferred, no approved business
        // rule for a Not Applicable process this phase — see
        // docs/programs/ecc/known-limitations.md.
        $service->set($program, 'features', [
            'evidence_enabled' => true,
            'extension_requests_enabled' => true,
            'sla_enabled' => true,
            'xlsx_import_enabled' => true,
            'xlsx_export_enabled' => true,
            'employee_assignment_enabled' => true,
            'in_app_notifications_enabled' => true,
            'email_notifications_enabled' => true,
            'scoring_enabled' => false,
            'executive_dashboard_enabled' => true,
            'not_applicable_enabled' => false,
        ]);
    }
}
