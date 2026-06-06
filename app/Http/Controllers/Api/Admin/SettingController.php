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
     * Public branding info (platform name + logo/favicon URLs) for the login
     * page and app shell. No auth required.
     * GET /api/v1/branding
     */
    public function branding(): JsonResponse
    {
        $logo    = Setting::get('branding', 'logo');
        $favicon = Setting::get('branding', 'favicon');

        $allowed = collect(explode(',', (string) Setting::get('upload', 'allowed_types', 'pdf,doc,docx,xls,xlsx,ppt,pptx,zip,jpg,jpeg,png')))
            ->map(fn ($t) => trim(strtolower($t)))->filter()->values()->all();

        return response()->json([
            'success' => true,
            'data'    => [
                'platform_name'    => Setting::get('branding', 'platform_name'),
                'platform_name_en' => Setting::get('branding', 'platform_name_en'),
                'logo_url'         => $logo ? Storage::disk('public')->url($logo) : null,
                'favicon_url'      => $favicon ? Storage::disk('public')->url($favicon) : null,
                'upload'           => [
                    'allowed_types' => $allowed,
                    'max_size_mb'   => (int) Setting::get('upload', 'max_size_mb', 20),
                ],
            ],
        ]);
    }

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
        // Validate by extension rather than the `image` rule: SVG files are
        // frequently mis-detected by finfo (text/plain or text/xml), which makes
        // the `image`/`mimes` rules reject otherwise-valid SVG logos.
        $request->validate([
            'type' => ['required', 'in:logo,favicon'],
            'file' => ['required', 'file', 'max:2048'],
        ]);

        $file    = $request->file('file');
        $ext     = strtolower($file->getClientOriginalExtension());
        $allowed = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg', 'ico'];

        if (!in_array($ext, $allowed, true)) {
            return response()->json([
                'success' => false,
                'message' => 'Unsupported image type. Allowed: ' . implode(', ', $allowed) . '.',
                'errors'  => ['file' => ['Unsupported image type.']],
            ], 422);
        }

        $type    = $request->type;
        $path    = $file->store('branding', 'public');
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
