<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Phase 7: registers NDMO (National Data Management Office / مكتب إدارة
 * البيانات الوطنية) as the platform's fourth active compliance program,
 * mirroring exactly how QIYAS, SUMOUD, and ECC were bootstrapped. No
 * official NDMO content exists in this repository — see
 * docs/programs/ndmo/overview.md and known-limitations.md.
 */
return new class extends Migration
{
    public function up(): void
    {
        $terminology = [
            'domain' => ['ar' => 'المجال', 'en' => 'Domain'],
            'category' => ['ar' => 'السياسة', 'en' => 'Policy'],
            'requirement' => ['ar' => 'المتطلب', 'en' => 'Requirement'],
            'evidence' => ['ar' => 'مستند الإثبات', 'en' => 'Evidence Document'],
            'cycle' => ['ar' => 'دورة التقييم', 'en' => 'Assessment Cycle'],
        ];

        DB::table('compliance_programs')->updateOrInsert(
            ['code' => 'NDMO'],
            [
                'name_ar' => 'مكتب إدارة البيانات الوطنية',
                'name_en' => 'National Data Management Office',
                'description_ar' => 'برنامج مكتب إدارة البيانات الوطنية — المحتوى الرسمي (المجالات والسياسات والمعايير والمتطلبات) بانتظار الاعتماد من الجهة المعنية؛ البنية التحتية والتهيئة جاهزة.',
                'description_en' => 'National Data Management Office program — official framework content (domains/policies/standards/requirements) is pending approval from the responsible organization; the program infrastructure and configuration are ready.',
                'icon' => 'database-lock',
                'status' => 'active',
                'sort_order' => 4,
                'primary_color' => '#065f46',
                'secondary_color' => '#047857',
                'settings' => json_encode(['terminology' => $terminology]),
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        );
    }

    public function down(): void
    {
        DB::table('compliance_programs')->where('code', 'NDMO')->delete();
    }
};
