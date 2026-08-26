<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Phase 5: registers Sumoud as the platform's second active compliance
 * program, mirroring exactly how QIYAS was bootstrapped in
 * 2026_07_17_000001_create_compliance_programs_table.php — inserted here
 * (not a seeder) so its existence is guaranteed, in order, on every
 * environment, and every later Sumoud seeder (program configuration,
 * workflow definition, program memberships) can resolve it by `code`
 * without racing seeder order.
 *
 * No official Sumoud domains/controls/requirements exist yet — see
 * docs/programs/sumoud/overview.md. Only the program identity and
 * generic default terminology (also configuration-driven, see
 * SumoudProgramConfigurationSeeder) are set here.
 */
return new class extends Migration
{
    public function up(): void
    {
        $terminology = [
            'domain' => ['ar' => 'المجال', 'en' => 'Domain'],
            'category' => ['ar' => 'الفئة', 'en' => 'Category'],
            'requirement' => ['ar' => 'المتطلب', 'en' => 'Requirement'],
            'evidence' => ['ar' => 'مستند الإثبات', 'en' => 'Evidence Document'],
            'cycle' => ['ar' => 'دورة البرنامج', 'en' => 'Program Cycle'],
        ];

        DB::table('compliance_programs')->updateOrInsert(
            ['code' => 'SUMOUD'],
            [
                'name_ar' => 'صمود',
                'name_en' => 'Sumoud',
                'description_ar' => 'برنامج صمود لإدارة الامتثال — التسمية والمصطلحات النهائية والمحتوى الرسمي بانتظار الاعتماد من الجهة المعنية.',
                'description_en' => 'Sumoud compliance program — official terminology and content are pending approval from the responsible organization; generic defaults are in effect.',
                'icon' => 'shield-half',
                'status' => 'active',
                'sort_order' => 2,
                'primary_color' => '#7c2d12',
                'secondary_color' => '#9a3412',
                'settings' => json_encode(['terminology' => $terminology]),
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        );
    }

    public function down(): void
    {
        DB::table('compliance_programs')->where('code', 'SUMOUD')->delete();
    }
};
