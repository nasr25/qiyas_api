<?php

namespace Database\Seeders;

use App\Models\Department;
use Illuminate\Database\Seeder;

/**
 * Seeds the 5 default departments. Idempotent (keyed on English name).
 */
class DepartmentsSeeder extends Seeder
{
    public const DEPARTMENTS = [
        ['name_ar' => 'تقنية المعلومات',   'name_en' => 'Information Technology'],
        ['name_ar' => 'الموارد البشرية',    'name_en' => 'Human Resources'],
        ['name_ar' => 'الشؤون المالية',     'name_en' => 'Finance'],
        ['name_ar' => 'الخدمات المشتركة',   'name_en' => 'Shared Services'],
        ['name_ar' => 'التخطيط والتطوير',   'name_en' => 'Planning & Development'],
    ];

    public function run(): void
    {
        foreach (self::DEPARTMENTS as $d) {
            Department::firstOrCreate(
                ['name_en' => $d['name_en']],
                ['name_ar' => $d['name_ar'], 'is_active' => true],
            );
        }
    }
}
