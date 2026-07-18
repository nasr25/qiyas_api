<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\SettingVersion;
use App\Services\SmtpSettingsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Super-Admin-only SMTP configuration management. Route-gated by
 * `role:super-admin`. Every response is built by hand (never an Eloquent
 * ->toArray()/API Resource fallthrough) specifically so the encrypted
 * password column can never accidentally leak — see
 * docs/security/smtp-security.md.
 */
class SmtpSettingsController extends Controller
{
    public function __construct(private readonly SmtpSettingsService $smtp) {}

    /** GET /api/v1/admin/smtp-settings */
    public function show(): JsonResponse
    {
        return response()->json(['success' => true, 'data' => $this->summarize()]);
    }

    /** PUT /api/v1/admin/smtp-settings */
    public function update(Request $request): JsonResponse
    {
        $data = $request->validate([
            'is_enabled' => ['required', 'boolean'],
            'host' => ['nullable', 'string', 'max:255'],
            'port' => ['nullable', 'integer', 'min:1', 'max:65535'],
            'encryption' => ['required', 'in:none,starttls,tls'],
            'auth_enabled' => ['required', 'boolean'],
            'username' => ['nullable', 'string', 'max:255'],
            'password' => ['nullable', 'string', 'max:255'],
            'from_email' => ['nullable', 'email', 'max:255'],
            'from_name_ar' => ['nullable', 'string', 'max:255'],
            'from_name_en' => ['nullable', 'string', 'max:255'],
            'reply_to_email' => ['nullable', 'email', 'max:255'],
            'reply_to_name' => ['nullable', 'string', 'max:255'],
            'connection_timeout' => ['required', 'integer', 'min:1', 'max:120'],
            'send_timeout' => ['nullable', 'integer', 'min:1', 'max:300'],
            'verify_certificate' => ['required', 'boolean'],
            'queue_enabled' => ['required', 'boolean'],
            'retry_count' => ['required', 'integer', 'min:0', 'max:10'],
            'retry_delay' => ['required', 'integer', 'min:0', 'max:3600'],
            'environment_label' => ['nullable', 'string', 'max:100'],
            'internal_relay_mode' => ['required', 'boolean'],
        ]);

        // Non-approved-relay + no-encryption is rejected here (backend is
        // the enforcement point, not just a frontend hint) — "none" is
        // only accepted when internal_relay_mode is explicitly set.
        if (($data['encryption'] ?? null) === 'none' && empty($data['internal_relay_mode'])) {
            return response()->json(['success' => false, 'message' => 'Unencrypted SMTP is only permitted for an explicitly approved trusted internal relay.'], 422);
        }

        $this->smtp->save($data, $request->user());

        return response()->json(['success' => true, 'data' => $this->summarize()]);
    }

    /** POST /api/v1/admin/smtp-settings/test */
    public function test(Request $request): JsonResponse
    {
        $data = $request->validate([
            'host' => ['required', 'string', 'max:255'],
            'port' => ['required', 'integer', 'min:1', 'max:65535'],
            'encryption' => ['required', 'in:none,starttls,tls'],
            'auth_enabled' => ['required', 'boolean'],
            'username' => ['nullable', 'string', 'max:255'],
            'password' => ['nullable', 'string', 'max:255'],
            'use_saved_password' => ['sometimes', 'boolean'],
            'from_email' => ['nullable', 'email', 'max:255'],
            'test_recipient' => ['nullable', 'email', 'max:255'],
        ]);

        $result = $this->smtp->testConnection($data, $data['test_recipient'] ?? null, $request->user());

        return response()->json(['success' => $result['success'], 'message' => $result['message']]);
    }

    /** GET /api/v1/admin/smtp-settings/history */
    public function history(): JsonResponse
    {
        $versions = SettingVersion::where('group', 'smtp')
            ->with('changedBy')
            ->orderByDesc('changed_at')
            ->limit(100)
            ->get();

        return response()->json([
            'success' => true,
            'data' => $versions->map(fn (SettingVersion $v) => [
                'key' => $v->key,
                'is_secret' => $v->is_secret,
                'old_value' => $v->is_secret ? null : $v->old_value,
                'new_value' => $v->is_secret ? null : $v->new_value,
                'secret_action' => $v->secret_action,
                'changed_by' => $v->changedBy?->name,
                'changed_at' => $v->changed_at?->toIso8601String(),
            ])->values(),
        ]);
    }

    private function summarize(): array
    {
        $setting = $this->smtp->current();

        return [
            'is_enabled' => $setting->is_enabled,
            'host' => $setting->host,
            'port' => $setting->port,
            'encryption' => $setting->encryption,
            'auth_enabled' => $setting->auth_enabled,
            'username' => $setting->username,
            'password_configured' => $setting->hasPassword(),
            'password_last_changed_at' => $setting->password_set_at?->toIso8601String(),
            'from_email' => $setting->from_email,
            'from_name_ar' => $setting->from_name_ar,
            'from_name_en' => $setting->from_name_en,
            'reply_to_email' => $setting->reply_to_email,
            'reply_to_name' => $setting->reply_to_name,
            'connection_timeout' => $setting->connection_timeout,
            'send_timeout' => $setting->send_timeout,
            'verify_certificate' => $setting->verify_certificate,
            'queue_enabled' => $setting->queue_enabled,
            'retry_count' => $setting->retry_count,
            'retry_delay' => $setting->retry_delay,
            'environment_label' => $setting->environment_label,
            'internal_relay_mode' => $setting->internal_relay_mode,
            'updated_at' => $setting->updated_at?->toIso8601String(),
            // Deliberately absent from every response: password_encrypted.
        ];
    }
}
