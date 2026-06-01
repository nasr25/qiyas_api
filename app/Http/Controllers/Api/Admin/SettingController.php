<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use App\Services\AuditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/**
 * System settings management: SMTP, branding, upload limits, localization.
 */
class SettingController extends Controller
{
    /**
     * Returns all settings grouped by category.
     * GET /api/v1/admin/settings
     */
    public function index(): JsonResponse
    {
        $settings = Setting::all()->groupBy('group')->map(fn($group) =>
            $group->mapWithKeys(fn($s) => [$s->key => $this->castValue($s)])
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
            ->mapWithKeys(fn($s) => [$s->key => $this->castValue($s)]);

        return response()->json(['success' => true, 'data' => $settings]);
    }

    /**
     * Updates multiple settings at once.
     * POST /api/v1/admin/settings
     */
    public function update(Request $request): JsonResponse
    {
        $data = $request->validate([
            'settings'         => ['required', 'array'],
            'settings.*.group' => ['required', 'string', 'max:100'],
            'settings.*.key'   => ['required', 'string', 'max:100'],
            'settings.*.value' => ['nullable'],
            'settings.*.type'  => ['nullable', 'in:string,boolean,integer,float,json'],
        ]);

        foreach ($data['settings'] as $setting) {
            Setting::set($setting['group'], $setting['key'], $setting['value'], $setting['type'] ?? 'string');
        }

        AuditService::log('settings.updated', 'System settings updated');

        return response()->json(['success' => true, 'message' => 'Settings saved.']);
    }

    /**
     * Uploads a branding asset (logo, favicon).
     * POST /api/v1/admin/settings/branding/upload
     */
    public function uploadBranding(Request $request): JsonResponse
    {
        $request->validate([
            'type' => ['required', 'in:logo,favicon'],
            'file' => ['required', 'image', 'max:2048'],
        ]);

        $type    = $request->type;
        $path    = $request->file('file')->store("branding", 'public');
        $url     = Storage::disk('public')->url($path);

        Setting::set('branding', $type, $path, 'string');
        AuditService::log('settings.branding', "Branding {$type} updated");

        return response()->json([
            'success' => true,
            'data'    => ['url' => $url, 'path' => $path],
        ]);
    }

    /** Casts a setting value to its declared type. */
    private function castValue(Setting $setting): mixed
    {
        return match ($setting->type) {
            'boolean' => (bool) $setting->value,
            'integer' => (int) $setting->value,
            'float'   => (float) $setting->value,
            'json'    => json_decode($setting->value, true),
            default   => $setting->value,
        };
    }
}
