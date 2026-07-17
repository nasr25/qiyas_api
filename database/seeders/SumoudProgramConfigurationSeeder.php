<?php

namespace Database\Seeders;

use App\Exports\Qiyas\MetadataSheet;
use App\Models\ComplianceProgram;
use App\Services\ProgramConfigurationService;
use Illuminate\Database\Seeder;

/**
 * Seeds Sumoud's own program_configurations rows — entirely separate
 * (compliance_program_id, category, version) records from Qiyas's (see
 * QiyasProgramConfigurationSeeder). Every value here is either the generic
 * organizational default named explicitly in the Phase 5 brief, or a
 * deliberate "same initial pattern as Qiyas" choice — never invented
 * official Sumoud regulatory content. See
 * docs/programs/sumoud/configuration.md for the source of each value.
 *
 * `scoring` is intentionally left unconfigured (feature flag disabled) —
 * no approved Sumoud scoring formula exists yet. See
 * docs/programs/sumoud/known-limitations.md.
 */
class SumoudProgramConfigurationSeeder extends Seeder
{
    public function run(): void
    {
        $program = ComplianceProgram::where('code', 'SUMOUD')->first();
        if (! $program) {
            return;
        }

        $service = app(ProgramConfigurationService::class);

        // Generic default terminology, exactly as specified in the Phase 5
        // brief — no approved Sumoud-specific terms exist yet. A Super
        // Admin can update these through the Program Configuration Engine
        // without touching generic backend entity names.
        $service->set($program, 'terminology', [
            'domain' => ['ar' => 'المجال', 'en' => 'Domain'],
            'category' => ['ar' => 'الفئة', 'en' => 'Category'],
            'requirement' => ['ar' => 'المتطلب', 'en' => 'Requirement'],
            'evidence' => ['ar' => 'مستند الإثبات', 'en' => 'Evidence Document'],
            'cycle' => ['ar' => 'دورة البرنامج', 'en' => 'Program Cycle'],
        ]);

        // Same initial shape as Qiyas (Employee requests, Auditor decides),
        // stored as Sumoud's own independent configuration row — changing
        // it later never touches Qiyas's 'extensions' row.
        $service->set($program, 'extensions', [
            'requester_role' => 'employee',
            'reviewer_role' => 'auditor',
            'rejection_reason_required' => true,
            'allow_multiple_pending' => false,
        ]);

        // Organizational default, not an official Sumoud file policy —
        // documented as such. Independently changeable from Qiyas's.
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

        // Sumoud's own import template definition — the visible columns
        // suggested in the Phase 5 brief, using the same generic machine
        // column identifiers the RequirementsSheet/QiyasImportValidator
        // pipeline already reads from configuration (see
        // docs/import-export-engine.md). Bilingual headings only; no
        // translated title is ever used as an importer key.
        $service->set($program, 'import', [
            'program_code' => 'SUMOUD',
            'template_version' => MetadataSheet::TEMPLATE_VERSION,
            'schema_version' => '1',
            'columns' => [
                ['key' => 'perspective', 'label_ar' => 'رمز المجال', 'label_en' => 'Domain Code', 'required' => false],
                ['key' => 'axis', 'label_ar' => 'رمز الفئة', 'label_en' => 'Category Code', 'required' => false],
                ['key' => 'standard_number', 'label_ar' => 'رمز المتطلب', 'label_en' => 'Requirement Code', 'required' => true],
                ['key' => 'name_ar', 'label_ar' => 'اسم المتطلب (عربي)', 'label_en' => 'Requirement Name (Arabic)', 'required' => true],
                ['key' => 'name_en', 'label_ar' => 'اسم المتطلب (إنجليزي)', 'label_en' => 'Requirement Name (English)', 'required' => false],
                ['key' => 'description', 'label_ar' => 'الوصف', 'label_en' => 'Description', 'required' => false],
                ['key' => 'application_requirements', 'label_ar' => 'الهدف', 'label_en' => 'Objective', 'required' => false],
                ['key' => 'evidence_documents', 'label_ar' => 'متطلبات الإثبات', 'label_en' => 'Evidence Requirements', 'required' => false],
                ['key' => 'weight', 'label_ar' => 'الوزن', 'label_en' => 'Weight', 'required' => false],
                ['key' => 'due_date', 'label_ar' => 'تاريخ الاستحقاق الافتراضي', 'label_en' => 'Default Due Date', 'required' => false],
            ],
        ]);

        // scoring_enabled = false: no approved Sumoud scoring formula
        // exists. Every other feature Sumoud actually exercises this
        // phase is enabled. Qiyas's 'features' row (scoring_enabled =
        // true) is untouched.
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
        ]);
    }
}
