<?php

namespace Database\Seeders;

use App\Models\AssessmentCycle;
use App\Models\Standard;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * Seeds the 89 DGA Qiyas digital-government standards from
 * database/seeders/data/qiyas_standards.json into a dedicated draft cycle.
 *
 * Idempotent: re-running updates existing rows (unique on cycle_id + standard_number).
 * Departments are intentionally left unassigned — assign them per standard in-system.
 */
class StandardsCatalogSeeder extends Seeder
{
    public function run(): void
    {
        $path = database_path('seeders/data/qiyas_standards.json');

        if (! file_exists($path)) {
            $this->command?->warn("qiyas_standards.json not found at {$path} — skipping.");

            return;
        }

        $records = json_decode(file_get_contents($path), true) ?: [];
        if (empty($records)) {
            $this->command?->warn('qiyas_standards.json is empty — skipping.');

            return;
        }

        // created_by is required on assessment_cycles — needs a user (run after SuperAdminSeeder).
        $creator = User::query()->orderBy('id')->first();
        if (! $creator) {
            $this->command?->warn('No users found. Run SuperAdminSeeder first — skipping standards catalog.');

            return;
        }

        $cycle = AssessmentCycle::firstOrCreate(
            ['name' => 'معايير قياس للتحول الرقمي (مستوردة)'],
            [
                'year' => (int) now()->year,
                'start_date' => now()->startOfYear()->toDateString(),
                'end_date' => now()->endOfYear()->toDateString(),
                'status' => 'draft',
                'created_by' => $creator->id,
            ],
        );

        $count = 0;
        foreach ($records as $r) {
            $number = trim((string) ($r['standard_number'] ?? ''));
            if ($number === '') {
                continue;
            }

            Standard::updateOrCreate(
                ['cycle_id' => $cycle->id, 'standard_number' => $number],
                [
                    'perspective' => $r['perspective'] ?? null,
                    'axis' => $r['axis'] ?? null,
                    'name_ar' => $r['name_ar'] ?? $number,
                    'name_en' => null,
                    'description' => $r['objective'] ?? null, // الهدف
                    'application_requirements' => $r['application_requirements'] ?? null,
                    'evidence_documents' => $r['evidence_documents'] ?? null,
                    'scope' => $r['scope'] ?? null,
                    'related_references' => $r['related_references'] ?? null,
                    'status' => $r['status'] ?? null,
                    'is_active' => true,
                ],
            );
            $count++;
        }

        $this->command?->info("Seeded {$count} Qiyas standards into cycle #{$cycle->id} ({$cycle->name}). Departments left unassigned.");
    }
}
