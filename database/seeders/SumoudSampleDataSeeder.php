<?php

namespace Database\Seeders;

use App\Models\AssessmentCycle;
use App\Models\ComplianceProgram;
use App\Models\Department;
use App\Models\EvidenceRequirement;
use App\Models\Standard;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * Development/testing-ONLY sample hierarchy for Sumoud — clearly marked as
 * test content in every visible name, never presented as official Sumoud
 * regulatory content. No official Sumoud domains, controls, requirements,
 * evidence descriptions, scoring formulas, or regulatory text exist in this
 * repository, and none are invented here. See
 * docs/programs/sumoud/test-data.md and docs/programs/sumoud/known-limitations.md.
 *
 * Deliberately NOT called from DatabaseSeeder — a fresh production
 * `migrate --seed` creates the Sumoud PROGRAM (configuration, workflow,
 * empty hierarchy support) but never this fabricated content. Run
 * explicitly via `php artisan system:sumoud-sample-data` (development/E2E
 * environments only), mirroring DemoDataCommand's `system:demo-data`.
 */
class SumoudSampleDataSeeder extends Seeder
{
    public function run(): void
    {
        $program = ComplianceProgram::where('code', 'SUMOUD')->first();
        if (! $program) {
            return;
        }

        $admin = User::where('username', 'superadmin')->first();
        $it = Department::where('name_en', 'Information Technology')->first();
        $hr = Department::where('name_en', 'Human Resources')->first();

        $cycle = AssessmentCycle::firstOrCreate(
            ['compliance_program_id' => $program->id, 'name' => 'الدورة التجريبية لصمود 2026'],
            [
                'name_ar' => 'الدورة التجريبية لصمود 2026',
                'name_en' => 'Sumoud Test Cycle 2026',
                'year' => (int) now()->year,
                'start_date' => now()->startOfYear()->toDateString(),
                'end_date' => now()->endOfYear()->toDateString(),
                'status' => 'active',
                'is_current' => true,
                'activated_at' => now(),
                'created_by' => $admin?->id,
            ],
        );

        $samples = [
            ['SMD-1.1', 'متطلب تجريبي لصمود 1', 'Sumoud Test Requirement 1'],
            ['SMD-1.2', 'متطلب تجريبي لصمود 2', 'Sumoud Test Requirement 2'],
        ];

        foreach ($samples as $i => [$code, $ar, $en]) {
            $standard = Standard::updateOrCreate(
                ['cycle_id' => $cycle->id, 'standard_number' => $code],
                [
                    'compliance_program_id' => $program->id,
                    'perspective' => 'منظور تجريبي لصمود',
                    'axis' => 'محور تجريبي لصمود',
                    'name_ar' => $ar,
                    'name_en' => $en,
                    'description' => 'بيانات تجريبية لأغراض الاختبار فقط — ليست محتوى رسمياً معتمداً لبرنامج صمود.',
                    'application_requirements' => 'Development/testing sample only — not approved official Sumoud content.',
                    'evidence_documents' => 'إرفاق أي مستند لأغراض اختبار مسار الأدلة.',
                    'weight' => 10,
                    'due_date' => now()->addDays(30)->toDateString(),
                    'is_active' => true,
                ],
            );

            foreach ($it ? [$it] : [] as $dept) {
                $standard->departments()->syncWithoutDetaching([
                    $dept->id => ['assigned_at' => now(), 'assigned_by' => $admin?->id],
                ]);
            }
            if ($i === 1 && $hr) {
                $standard->departments()->syncWithoutDetaching([
                    $hr->id => ['assigned_at' => now(), 'assigned_by' => $admin?->id],
                ]);
            }

            EvidenceRequirement::firstOrCreate(
                ['standard_id' => $standard->id, 'sort_order' => 1],
                [
                    'title_ar' => 'متطلب إثبات تجريبي',
                    'title_en' => 'Sample test evidence requirement',
                    'description' => 'مستند تجريبي فقط.',
                    'is_mandatory' => true,
                ],
            );
        }
    }
}
