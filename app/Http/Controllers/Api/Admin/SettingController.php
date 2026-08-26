<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Api\Auth\AuthController;
use App\Http\Controllers\Controller;
use App\Models\Setting;
use App\Services\AuditService;
use App\Services\BrandingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/**
 * System settings management: SMTP, branding, upload limits, localization.
 */
class SettingController extends Controller
{
    /**
     * Public branding info (platform name + logo/favicon URLs) for the login
     * page and app shell. No auth required.
     * GET /api/v1/branding
     */
    public function branding(): JsonResponse
    {
        // Active, versioned branding assets are the source of truth; the
        // old flat `Setting` logo/favicon keys are only a fallback for an
        // asset type that has never had a version uploaded through the
        // new Super Admin branding engine. See BrandingService::activeUrls().
        $active = app(BrandingService::class)->activeUrls();
        $logo = Setting::get('branding', 'logo');
        $favicon = Setting::get('branding', 'favicon');

        $allowed = collect(explode(',', (string) Setting::get('upload', 'allowed_types', 'pdf,doc,docx,xls,xlsx,ppt,pptx,zip,jpg,jpeg,png')))
            ->map(fn ($t) => trim(strtolower($t)))->filter()->values()->all();

        return response()->json([
            'success' => true,
            'data' => [
                'platform_name' => Setting::get('branding', 'platform_name'),
                'platform_name_en' => Setting::get('branding', 'platform_name_en'),
                'logo_url' => $active['logo_primary'] ?? ($logo ? Storage::disk('public')->url($logo) : null),
                'logo_dark_url' => $active['logo_dark'] ?? null,
                'logo_login_url' => $active['logo_login'] ?? ($active['logo_primary'] ?? null),
                'logo_header_url' => $active['logo_header'] ?? ($active['logo_primary'] ?? null),
                'logo_compact_url' => $active['logo_compact'] ?? null,
                'favicon_url' => $active['favicon'] ?? ($favicon ? Storage::disk('public')->url($favicon) : null),
                'upload' => [
                    'allowed_types' => $allowed,
                    'max_size_mb' => (int) Setting::get('upload', 'max_size_mb', 20),
                ],
                'quick_login' => AuthController::quickLoginEnabled(),
            ],
        ]);
    }

    /**
     * Returns all settings grouped by category.
     * GET /api/v1/admin/settings
     */
    public function index(): JsonResponse
    {
        $settings = Setting::all()->groupBy('group')->map(fn ($group) => $group->mapWithKeys(fn ($s) => [$s->key => $this->castValue($s)])
        );

        return response()->json(['success' => true, 'data' => $settings]);
    }

    /**
     * Returns settings for a specific group.
     * GET /api/v1/admin/settings/{group}
     */
    public function group(string $group): JsonResponse
    {
        $settings = Setting::where('group', $group)
            ->get()
            ->mapWithKeys(fn ($s) => [$s->key => $this->castValue($s)]);

        return response()->json(['success' => true, 'data' => $settings]);
    }

    /**
     * Updates multiple settings at once.
     * POST /api/v1/admin/settings
     */
    public function update(Request $request): JsonResponse
    {
        $data = $request->validate([
            'settings' => ['required', 'array'],
            'settings.*.group' => ['required', 'string', 'max:100'],
            'settings.*.key' => ['required', 'string', 'max:100'],
            'settings.*.value' => ['nullable'],
            'settings.*.type' => ['nullable', 'in:string,boolean,integer,float,json'],
        ]);

        foreach ($data['settings'] as $setting) {
            Setting::set($setting['group'], $setting['key'], $setting['value'], $setting['type'] ?? 'string');
        }

        AuditService::log('settings.updated', 'System settings updated');

        return response()->json(['success' => true, 'message' => 'Settings saved.']);
    }

    /** Casts a setting value to its declared type. */
    private function castValue(Setting $setting): mixed
    {
        return match ($setting->type) {
            'boolean' => (bool) $setting->value,
            'integer' => (int) $setting->value,
            'float' => (float) $setting->value,
            'json' => json_decode($setting->value, true),
            default => $setting->value,
        };
    }
}
