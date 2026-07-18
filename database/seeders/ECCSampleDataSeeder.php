<?php

namespace Database\Seeders;

use App\Models\AssessmentCycle;
use App\Models\ComplianceContentVersion;
use App\Models\ComplianceProgram;
use App\Models\Department;
use App\Models\User;
use App\Services\ComplianceNodeService;
use Illuminate\Database\Seeder;

/**
 * Development/testing-ONLY sample hierarchy for ECC — clearly marked as
 * test content in every visible name, never presented as official ECC
 * regulatory content. No official ECC domains, controls, subcontrols,
 * implementation guidance, evidence descriptions, or scoring formula exist
 * in this repository, and none are invented here. See
 * docs/programs/ecc/test-data.md and known-limitations.md.
 *
 * Deliberately NOT called from DatabaseSeeder — run explicitly via
 * `php artisan system:ecc-sample-data` (development/E2E environments
 * only), mirroring SumoudSampleDataSeeder's `system:sumoud-sample-data`.
 *
 * Demonstrates the full four-level hierarchy (domain -> subdomain ->
 * control -> subcontrol) through ComplianceNodeService — the same
 * generic write path an approved official import would use.
 */
class ECCSampleDataSeeder extends Seeder
{
    public function run(): void
    {
        $program = ComplianceProgram::where('code', 'ECC')->first();
        if (! $program) {
            return;
        }

        $admin = User::where('username', 'superadmin')->first();
        $it = Department::where('name_en', 'Information Technology')->first();
        $hr = Department::where('name_en', 'Human Resources')->first();
        $nodes = app(ComplianceNodeService::class);

        $contentVersion = ComplianceContentVersion::firstOrCreate(
            ['compliance_program_id' => $program->id, 'version_label' => 'DEV-TEST-V1'],
            [
                'source_name' => 'Development/testing sample content (not an official ECC source)',
                'source_date' => now()->toDateString(),
                'imported_by' => $admin?->id,
                'file_hash' => hash('sha256', 'ecc-dev-sample-v1'),
                'template_version' => '1',
                'status' => 'active',
                'effective_date' => now()->toDateString(),
                'change_summary' => 'Initial development-only sample content — not official ECC framework content.',
            ],
        );

        $cycle = AssessmentCycle::firstOrCreate(
            ['compliance_program_id' => $program->id, 'name' => 'الدورة التجريبية للأمن السيبراني 2026'],
            [
                'content_version_id' => $contentVersion->id,
                'name_ar' => 'الدورة التجريبية للأمن السيبراني 2026',
                'name_en' => 'ECC Test Cycle 2026',
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

        $domain = $nodes->createNode($program, 'domain', 'ECC-D1', 'مجال تجريبي للأمن السيبراني', null, $cycle, $contentVersion, $admin, [
            'name_en' => 'ECC Test Domain', 'description_ar' => 'بيانات تجريبية لأغراض الاختبار فقط.',
        ]);

        $subdomain = $nodes->createNode($program, 'subdomain', 'ECC-D1-S1', 'مجال فرعي تجريبي', $domain, $cycle, $contentVersion, $admin, [
            'name_en' => 'ECC Test Subdomain', 'description_ar' => 'بيانات تجريبية لأغراض الاختبار فقط.',
        ]);

        $control1 = $nodes->createAssessableNode($program, 'control', 'ECC-D1-S1-C1', 'ضابط تجريبي 1', $subdomain, $cycle, $contentVersion, $admin, [
            'name_en' => 'ECC Test Control 1',
            'description_ar' => 'بيانات تجريبية لأغراض الاختبار فقط — ليست محتوى رسمياً معتمداً.',
            'guidance_ar' => 'إرشاد تجريبي — Development/testing sample only.',
            'evidence_requirements_ar' => 'إرفاق أي مستند لأغراض اختبار مسار الأدلة.',
            'weight' => 10,
            'due_date' => now()->addDays(30)->toDateString(),
        ]);

        $nodes->createAssessableNode($program, 'subcontrol', 'ECC-D1-S1-C1-SC1', 'ضابط فرعي تجريبي', $control1, $cycle, $contentVersion, $admin, [
            'name_en' => 'ECC Test Subcontrol', 'description_ar' => 'بيانات تجريبية لأغراض الاختبار فقط.',
            'weight' => 5, 'due_date' => now()->addDays(30)->toDateString(),
        ]);

        $control2 = $nodes->createAssessableNode($program, 'control', 'ECC-D1-S1-C2', 'ضابط تجريبي 2', $subdomain, $cycle, $contentVersion, $admin, [
            'name_en' => 'ECC Test Control 2',
            'description_ar' => 'بيانات تجريبية لأغراض الاختبار فقط — ليست محتوى رسمياً معتمداً.',
            'evidence_requirements_ar' => 'إرفاق أي مستند لأغراض اختبار مسار الأدلة.',
            'weight' => 10,
            'due_date' => now()->addDays(30)->toDateString(),
        ]);

        foreach ([$control1, $control2] as $i => $control) {
            $dept = $i === 0 ? $it : $hr;
            if ($dept && $control->standard) {
                $control->standard->departments()->syncWithoutDetaching([
                    $dept->id => ['assigned_at' => now(), 'assigned_by' => $admin->id],
                ]);
            }
        }
    }
}
