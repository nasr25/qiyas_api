<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Phase 1 renames the overall platform from "Qiyas Platform" to the generic
 * "Government Compliance Management Platform" — Qiyas becomes one compliance
 * program among (eventually) several, not the platform's identity. Only the
 * global branding.platform_name(_en) setting is touched; the QIYAS
 * compliance_programs row keeps its own name (قياس / Qiyas) unchanged.
 */
return new class extends Migration
{
    private const OLD_AR = 'منصة قياس';

    private const OLD_EN = 'Qiyas Platform';

    private const NEW_AR = 'منصة إدارة الامتثال والحوكمة الحكومية';

    private const NEW_EN = 'Government Compliance Management Platform';

    public function up(): void
    {
        DB::table('settings')
            ->where('group', 'branding')->where('key', 'platform_name')->where('value', self::OLD_AR)
            ->update(['value' => self::NEW_AR]);

        DB::table('settings')
            ->where('group', 'branding')->where('key', 'platform_name_en')->where('value', self::OLD_EN)
            ->update(['value' => self::NEW_EN]);

        $this->bustCache();
    }

    public function down(): void
    {
        DB::table('settings')
            ->where('group', 'branding')->where('key', 'platform_name')->where('value', self::NEW_AR)
            ->update(['value' => self::OLD_AR]);

        DB::table('settings')
            ->where('group', 'branding')->where('key', 'platform_name_en')->where('value', self::NEW_EN)
            ->update(['value' => self::OLD_EN]);

        $this->bustCache();
    }

    /**
     * Setting::get() caches values for an hour (see App\Models\Setting);
     * a raw DB update here would otherwise leave stale branding cached
     * until the TTL expires.
     */
    private function bustCache(): void
    {
        Cache::forget('setting.branding.platform_name');
        Cache::forget('setting.branding.platform_name_en');
    }
};
