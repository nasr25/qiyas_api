<?php

namespace Database\Seeders;

use App\Models\Setting;
use Illuminate\Database\Seeder;

/**
 * Seeds default platform settings.
 */
class DefaultSettingsSeeder extends Seeder
{
    public function run(): void
    {
        $defaults = [
            // Branding
            ['group' => 'branding', 'key' => 'platform_name',  'value' => 'منصة قياس',         'type' => 'string'],
            ['group' => 'branding', 'key' => 'platform_name_en','value' => 'Qiyas Platform',   'type' => 'string'],
            ['group' => 'branding', 'key' => 'footer_text',    'value' => 'جميع الحقوق محفوظة', 'type' => 'string'],
            ['group' => 'branding', 'key' => 'logo',           'value' => null,                 'type' => 'string'],
            ['group' => 'branding', 'key' => 'favicon',        'value' => null,                 'type' => 'string'],

            // Upload
            ['group' => 'upload', 'key' => 'allowed_types',   'value' => 'pdf,docx,xlsx,pptx,zip', 'type' => 'string'],
            ['group' => 'upload', 'key' => 'max_size_mb',     'value' => '20',                      'type' => 'integer'],

            // Notifications
            ['group' => 'notifications', 'key' => 'reminder_days',    'value' => '7',  'type' => 'integer'],
            ['group' => 'notifications', 'key' => 'enable_email',      'value' => '1',  'type' => 'boolean'],
            ['group' => 'notifications', 'key' => 'enable_in_app',     'value' => '1',  'type' => 'boolean'],

            // Localization
            ['group' => 'localization', 'key' => 'default_locale', 'value' => 'ar', 'type' => 'string'],
            ['group' => 'localization', 'key' => 'timezone',        'value' => 'Asia/Riyadh', 'type' => 'string'],
        ];

        foreach ($defaults as $setting) {
            Setting::firstOrCreate(
                ['group' => $setting['group'], 'key' => $setting['key']],
                ['value' => $setting['value'], 'type' => $setting['type']]
            );
        }

        $this->command->info('Default settings seeded.');
    }
}
