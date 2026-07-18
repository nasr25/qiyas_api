<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Phase 6: registers ECC (Essential Cybersecurity Controls / الضوابط
 * الأساسية للأمن السيبراني) as the platform's third active compliance
 * program, mirroring exactly how QIYAS and SUMOUD were bootstrapped. No
 * official ECC content exists in this repository — see
 * docs/programs/ecc/overview.md and known-limitations.md.
 */
return new class extends Migration
{
    public function up(): void
    {
        $terminology = [
            'domain' => ['ar' => 'المجال الرئيسي', 'en' => 'Main Domain'],
            'category' => ['ar' => 'المجال الفرعي', 'en' => 'Subdomain'],
            'requirement' => ['ar' => 'الضابط', 'en' => 'Control'],
            'evidence' => ['ar' => 'مستند الإثبات', 'en' => 'Evidence Document'],
            'cycle' => ['ar' => 'دورة التقييم', 'en' => 'Assessment Cycle'],
        ];

        DB::table('compliance_programs')->updateOrInsert(
            ['code' => 'ECC'],
            [
                'name_ar' => 'الضوابط الأساسية للأمن السيبراني',
                'name_en' => 'Essential Cybersecurity Controls',
                'description_ar' => 'برنامج الضوابط الأساسية للأمن السيبراني — المحتوى الرسمي (المجالات والضوابط) بانتظار الاعتماد من الجهة المعنية؛ البنية التحتية والتهيئة جاهزة.',
                'description_en' => 'Essential Cybersecurity Controls program — official framework content (domains/controls) is pending approval from the responsible organization; the program infrastructure and configuration are ready.',
                'icon' => 'shield-lock',
                'status' => 'active',
                'sort_order' => 3,
                'primary_color' => '#1e3a8a',
                'secondary_color' => '#1d4ed8',
                'settings' => json_encode(['terminology' => $terminology]),
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        );
    }

    public function down(): void
    {
        DB::table('compliance_programs')->where('code', 'ECC')->delete();
    }
};
