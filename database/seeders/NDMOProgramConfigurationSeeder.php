<?php

namespace Database\Seeders;

use App\Exports\Qiyas\MetadataSheet;
use App\Models\ComplianceProgram;
use App\Services\ProgramConfigurationService;
use Illuminate\Database\Seeder;

/**
 * Seeds NDMO's own program_configurations rows — entirely separate
 * (compliance_program_id, category, version) records from Qiyas's,
 * Sumoud's, and ECC's. The `hierarchy` category defines NDMO's five-level
 * shape (domain -> policy -> standard -> requirement -> subrequirement),
 * proving the generic hierarchy engine built for ECC in Phase 6 supports
 * a DIFFERENT depth/shape with zero code change — see
 * docs/programs/ndmo/hierarchy.md. The `responsibilities` category is new
 * this phase — see docs/programs/ndmo/responsibilities.md.
 */
class NDMOProgramConfigurationSeeder extends Seeder
{
    public function run(): void
    {
        $program = ComplianceProgram::where('code', 'NDMO')->first();
        if (! $program) {
            return;
        }

        $service = app(ProgramConfigurationService::class);

        $service->set($program, 'terminology', [
            'domain' => ['ar' => 'المجال', 'en' => 'Domain'],
            'category' => ['ar' => 'السياسة', 'en' => 'Policy'],
            'requirement' => ['ar' => 'المتطلب', 'en' => 'Requirement'],
            'evidence' => ['ar' => 'مستند الإثبات', 'en' => 'Evidence Document'],
            'cycle' => ['ar' => 'دورة التقييم', 'en' => 'Assessment Cycle'],
        ]);

        // NDMO's five-level shape: Domain -> Policy -> Standard ->
        // Requirement (assessable) -> Subrequirement (assessable). Both
        // Requirement and Subrequirement are marked assessable so an
        // approved source that stops at Requirement level still works —
        // ComplianceNodeService never assumes a fixed depth or shape.
        $service->set($program, 'hierarchy', [
            'levels' => [
                ['node_type' => 'domain', 'label_ar' => 'المجال', 'label_en' => 'Domain', 'parent_type' => null, 'is_assessable' => false],
                ['node_type' => 'policy', 'label_ar' => 'السياسة', 'label_en' => 'Policy', 'parent_type' => 'domain', 'is_assessable' => false],
                ['node_type' => 'standard', 'label_ar' => 'المعيار', 'label_en' => 'Standard', 'parent_type' => 'policy', 'is_assessable' => false],
                ['node_type' => 'requirement', 'label_ar' => 'المتطلب', 'label_en' => 'Requirement', 'parent_type' => 'standard', 'is_assessable' => true],
                ['node_type' => 'subrequirement', 'label_ar' => 'المتطلب الفرعي', 'label_en' => 'Subrequirement', 'parent_type' => 'requirement', 'is_assessable' => true],
            ],
            'max_depth' => 5,
        ]);

        // Same initial pattern as Qiyas/Sumoud/ECC — an internal
        // operational workflow, not an official regulatory approval
        // process.
        $service->set($program, 'extensions', [
            'requester_role' => 'employee',
            'reviewer_role' => 'auditor',
            'rejection_reason_required' => true,
            'allow_multiple_pending' => false,
        ]);

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

        // Data Owner / Data Steward are enabled for NDMO per the Phase 7
        // brief's explicit examples — informational labels only, never
        // authorization (see ResponsibilityService's class doc).
        $service->set($program, 'responsibilities', [
            'enabled_types' => ['data_owner', 'data_steward'],
            'types' => [
                ['type' => 'data_owner', 'label_ar' => 'مالك البيانات', 'label_en' => 'Data Owner'],
                ['type' => 'data_steward', 'label_ar' => 'مسؤول البيانات', 'label_en' => 'Data Steward'],
            ],
        ]);

        // NDMO's own import template definition — visible columns
        // suggested by the Phase 7 brief, generic machine column
        // identifiers, subject to the honest limitation documented in
        // docs/programs/ndmo/xlsx-import.md (the importer targets the
        // flat Requirement level, not the full five-level tree, this phase).
        $service->set($program, 'import', [
            'program_code' => 'NDMO',
            'template_version' => MetadataSheet::TEMPLATE_VERSION,
            'schema_version' => '1',
            'columns' => [
                ['key' => 'perspective', 'label_ar' => 'رمز المجال', 'label_en' => 'Domain Code', 'required' => false],
                ['key' => 'axis', 'label_ar' => 'رمز السياسة', 'label_en' => 'Policy Code', 'required' => false],
                ['key' => 'standard_number', 'label_ar' => 'رمز المتطلب', 'label_en' => 'Requirement Code', 'required' => true],
                ['key' => 'name_ar', 'label_ar' => 'اسم المتطلب (عربي)', 'label_en' => 'Requirement Name (Arabic)', 'required' => true],
                ['key' => 'name_en', 'label_ar' => 'اسم المتطلب (إنجليزي)', 'label_en' => 'Requirement Name (English)', 'required' => false],
                ['key' => 'description', 'label_ar' => 'الوصف', 'label_en' => 'Description', 'required' => false],
                ['key' => 'application_requirements', 'label_ar' => 'الإرشادات', 'label_en' => 'Guidance', 'required' => false],
                ['key' => 'evidence_documents', 'label_ar' => 'متطلبات الإثبات', 'label_en' => 'Evidence Requirements', 'required' => false],
                ['key' => 'weight', 'label_ar' => 'الوزن', 'label_en' => 'Weight', 'required' => false],
                ['key' => 'due_date', 'label_ar' => 'تاريخ الاستحقاق الافتراضي', 'label_en' => 'Default Due Date', 'required' => false],
            ],
        ]);

        // scoring_enabled = false: no approved NDMO scoring formula
        // exists. not_applicable_enabled = false: deferred, no approved
        // business rule this phase. assessment_result_enabled = false:
        // the compliant/partially_compliant/non_compliant model described
        // in the brief is deferred alongside Not Applicable — see
        // docs/programs/ndmo/known-limitations.md.
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
            'assessment_result_enabled' => false,
        ]);
    }
}
