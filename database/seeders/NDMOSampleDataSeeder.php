<?php

namespace Database\Seeders;

use App\Models\AssessmentCycle;
use App\Models\ComplianceContentVersion;
use App\Models\ComplianceProgram;
use App\Models\Department;
use App\Models\User;
use App\Services\ComplianceNodeService;
use App\Services\ResponsibilityService;
use App\Services\WorkflowService;
use Illuminate\Database\Seeder;

/**
 * Development/testing-ONLY sample hierarchy for NDMO — clearly marked as
 * test content in every visible name, never presented as official NDMO
 * regulatory content. No official NDMO domains, policies, standards,
 * requirements, subrequirements, evidence descriptions, or scoring
 * formula exist in this repository, and none are invented here. See
 * docs/programs/ndmo/test-data.md and known-limitations.md.
 *
 * Deliberately NOT called from DatabaseSeeder — run explicitly via
 * `php artisan system:ndmo-sample-data` (development/E2E environments
 * only), mirroring the Sumoud/ECC sample-data commands.
 *
 * Demonstrates the full five-level hierarchy (domain -> policy ->
 * standard -> requirement -> subrequirement) AND the responsibility
 * engine (Data Owner/Data Steward assigned to a real assignment) through
 * the same generic write paths an approved official import and an
 * authorized organizational user would use.
 */
class NDMOSampleDataSeeder extends Seeder
{
    public function run(): void
    {
        $program = ComplianceProgram::where('code', 'NDMO')->first();
        if (! $program) {
            return;
        }

        $admin = User::where('username', 'superadmin')->first();
        $it = Department::where('name_en', 'Information Technology')->first();
        $nodes = app(ComplianceNodeService::class);

        $contentVersion = ComplianceContentVersion::firstOrCreate(
            ['compliance_program_id' => $program->id, 'version_label' => 'DEV-TEST-V1'],
            [
                'source_name' => 'Development/testing sample content (not an official NDMO source)',
                'source_date' => now()->toDateString(),
                'imported_by' => $admin?->id,
                'file_hash' => hash('sha256', 'ndmo-dev-sample-v1'),
                'template_version' => '1',
                'status' => 'active',
                'effective_date' => now()->toDateString(),
                'change_summary' => 'Initial development-only sample content — not official NDMO framework content.',
            ],
        );

        $cycle = AssessmentCycle::firstOrCreate(
            ['compliance_program_id' => $program->id, 'name' => 'الدورة التجريبية لحوكمة البيانات 2026'],
            [
                'content_version_id' => $contentVersion->id,
                'name_ar' => 'الدورة التجريبية لحوكمة البيانات 2026',
                'name_en' => 'NDMO Test Cycle 2026',
                'year' => (int) now()->year,
                'start_date' => now()->startOfYear()->toDateString(),
                'end_date' => now()->endOfYear()->toDateString(),
                'status' => 'active',
                'is_current' => true,
                'activated_at' => now(),
                'created_by' => $admin?->id,
            ],
        );

        if ($cycle->complianceNodes()->exists() || ! $admin) {
            return;
        }

        $domain = $nodes->createNode($program, 'domain', 'NDMO-D1', 'مجال تجريبي لحوكمة البيانات', null, $cycle, $contentVersion, $admin, [
            'name_en' => 'NDMO Test Domain',
        ]);
        $policy = $nodes->createNode($program, 'policy', 'NDMO-D1-P1', 'سياسة تجريبية', $domain, $cycle, $contentVersion, $admin, [
            'name_en' => 'Test Policy',
        ]);
        $standard = $nodes->createNode($program, 'standard', 'NDMO-D1-P1-S1', 'معيار تجريبي', $policy, $cycle, $contentVersion, $admin, [
            'name_en' => 'Test Standard',
        ]);
        $requirement = $nodes->createAssessableNode($program, 'requirement', 'NDMO-D1-P1-S1-R1', 'متطلب تجريبي', $standard, $cycle, $contentVersion, $admin, [
            'name_en' => 'Test Requirement',
            'description_ar' => 'بيانات تجريبية لأغراض الاختبار فقط — ليست محتوى رسمياً معتمداً.',
            'guidance_ar' => 'إرشاد تجريبي — Development/testing sample only.',
            'evidence_requirements_ar' => 'إرفاق أي مستند لأغراض اختبار مسار الأدلة.',
            'weight' => 10,
            'due_date' => now()->addDays(30)->toDateString(),
        ]);
        $nodes->createAssessableNode($program, 'subrequirement', 'NDMO-D1-P1-S1-R1-SR1', 'متطلب فرعي تجريبي', $requirement, $cycle, $contentVersion, $admin, [
            'name_en' => 'Test Subrequirement', 'weight' => 5, 'due_date' => now()->addDays(30)->toDateString(),
        ]);

        if ($it && $requirement->standard) {
            $requirement->standard->departments()->syncWithoutDetaching([
                $it->id => ['assigned_at' => now(), 'assigned_by' => $admin->id],
            ]);

            $dataOwner = User::where('username', 'ndmo_data_owner_a')->first();
            $dataSteward = User::where('username', 'ndmo_data_steward_a')->first();
            if ($dataOwner || $dataSteward) {
                $assignment = app(WorkflowService::class)->assign(
                    $requirement->standard, $program, $admin, $it, null, now()->addDays(30)->toDateString(), null,
                    'يرجى مراجعة متطلب حوكمة البيانات التجريبي.', 'Please review the test data-governance requirement.',
                );

                $responsibilities = app(ResponsibilityService::class);
                if ($dataOwner) {
                    $responsibilities->assign($assignment, 'data_owner', $admin, $dataOwner, null, 'بيانات تجريبية.');
                }
                if ($dataSteward) {
                    $responsibilities->assign($assignment, 'data_steward', $admin, $dataSteward, null, 'بيانات تجريبية.');
                }
            }
        }
    }
}
