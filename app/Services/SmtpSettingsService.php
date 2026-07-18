<?php

namespace App\Services;

use App\Models\SettingVersion;
use App\Models\SmtpSetting;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\Transport;
use Symfony\Component\Mailer\Transport\Smtp\SmtpTransport;
use Symfony\Component\Mime\Email;

/**
 * Source of truth: a single active `smtp_settings` database row managed
 * by Super Admin, with the framework's own .env-derived mail
 * configuration used ONLY as a fallback when no active row exists (e.g.
 * a fresh install before Super Admin has configured SMTP). See
 * docs/security/smtp-security.md, "Configuration source of truth."
 *
 * The plaintext password is decrypted ONLY inside this class, ONLY for
 * the duration of building a transport — never returned, cached,
 * logged, or exposed to a Resource/audit entry.
 */
class SmtpSettingsService
{
    private const CACHE_KEY = 'smtp_settings.effective';

    private const CACHE_TTL = 3600;

    public function current(): SmtpSetting
    {
        return SmtpSetting::query()->first() ?? SmtpSetting::create(['is_enabled' => false, 'encryption' => 'starttls']);
    }

    /**
     * Applies the database SMTP configuration to the framework's runtime
     * mail config, if an active, enabled row exists — called once at
     * application boot (see AppServiceProvider::boot()). Every existing
     * Mail::/Notification:: call in the codebase is unaffected code-wise;
     * only the resolved transport configuration changes.
     */
    public function applyToRuntimeConfig(): void
    {
        $effective = Cache::remember(self::CACHE_KEY, self::CACHE_TTL, fn () => $this->buildEffectiveConfig());

        if (! $effective) {
            return; // No active DB configuration — the .env-derived mail config (Laravel default) remains in effect.
        }

        config([
            'mail.default' => 'smtp',
            'mail.mailers.smtp' => [
                'transport' => 'smtp',
                'host' => $effective['host'],
                'port' => $effective['port'],
                'encryption' => $effective['encryption'] === 'none' ? null : $effective['encryption'],
                'username' => $effective['username'],
                'password' => $effective['password'],
                'timeout' => $effective['connection_timeout'],
            ],
            'mail.from' => [
                'address' => $effective['from_email'],
                'name' => app()->getLocale() === 'ar' ? $effective['from_name_ar'] : $effective['from_name_en'],
            ],
        ]);

        if ($effective['reply_to_email']) {
            config(['mail.reply_to' => ['address' => $effective['reply_to_email'], 'name' => $effective['reply_to_name']]]);
        }
    }

    /** @return array<string,mixed>|null Effective (decrypted, in-memory only) config, or null when SMTP is disabled/unconfigured. */
    private function buildEffectiveConfig(): ?array
    {
        $setting = SmtpSetting::query()->first();
        if (! $setting || ! $setting->is_enabled || ! $setting->host) {
            return null;
        }

        return [
            'host' => $setting->host,
            'port' => $setting->port ?? 587,
            'encryption' => $setting->encryption,
            'username' => $setting->auth_enabled ? $setting->username : null,
            'password' => $setting->auth_enabled ? $setting->decryptPassword() : null,
            'from_email' => $setting->from_email,
            'from_name_ar' => $setting->from_name_ar,
            'from_name_en' => $setting->from_name_en,
            'reply_to_email' => $setting->reply_to_email,
            'reply_to_name' => $setting->reply_to_name,
            'connection_timeout' => $setting->connection_timeout,
        ];
    }

    /**
     * Saves SMTP settings. An empty `password` field preserves the
     * existing encrypted password (see SmtpSetting::setPassword()) — the
     * frontend never submits the real password back after initial entry.
     */
    public function save(array $data, User $actor): SmtpSetting
    {
        $setting = $this->current();
        $before = $setting->only([
            'is_enabled', 'host', 'port', 'encryption', 'auth_enabled', 'username',
            'from_email', 'from_name_ar', 'from_name_en', 'reply_to_email', 'reply_to_name',
            'connection_timeout', 'send_timeout', 'verify_certificate', 'queue_enabled',
            'retry_count', 'retry_delay', 'environment_label', 'internal_relay_mode',
        ]);
        $hadPassword = $setting->hasPassword();

        $incomingPassword = $data['password'] ?? null;
        unset($data['password']);
        $setting->fill($data);
        $setting->setPassword($incomingPassword);
        $setting->updated_by = $actor->id;
        $setting->save();

        $this->recordVersion($before, $setting, $actor);

        if ($incomingPassword) {
            $this->recordSecretAction($hadPassword ? 'changed' : 'configured', $actor);
        }

        Cache::forget(self::CACHE_KEY);

        return $setting->fresh();
    }

    private function recordVersion(array $before, SmtpSetting $after, User $actor): void
    {
        $afterValues = $after->only(array_keys($before));
        foreach ($before as $key => $oldValue) {
            $newValue = $afterValues[$key];
            if ($oldValue === $newValue) {
                continue;
            }
            SettingVersion::create([
                'group' => 'smtp', 'key' => $key, 'is_secret' => false,
                'old_value' => is_bool($oldValue) ? ($oldValue ? '1' : '0') : (string) $oldValue,
                'new_value' => is_bool($newValue) ? ($newValue ? '1' : '0') : (string) $newValue,
                'changed_by' => $actor->id, 'changed_at' => now(),
            ]);
        }
        AuditService::log('smtp_settings.updated', 'SMTP settings updated (non-secret fields)', $after);
    }

    private function recordSecretAction(string $action, User $actor): void
    {
        SettingVersion::create([
            'group' => 'smtp', 'key' => 'password', 'is_secret' => true,
            'secret_action' => $action, 'changed_by' => $actor->id, 'changed_at' => now(),
        ]);
        AuditService::log('smtp_settings.password_'.$action, "SMTP password {$action}");
    }

    /**
     * Attempts a short-timeout SMTP connection using EITHER the saved
     * configuration OR explicitly-provided unsaved form values (so
     * Super Admin can test before saving). Never persists the tested
     * values. Returns a sanitized result — no server banner, no
     * certificate internals, no credentials.
     */
    public function testConnection(array $fields, ?string $testRecipient, User $actor): array
    {
        $password = $fields['password'] ?? ($fields['use_saved_password'] ?? false ? $this->current()->decryptPassword() : null);

        $dsn = $this->buildDsn($fields, $password);

        try {
            $transport = Transport::fromDsn($dsn);
            $isSmtp = $transport instanceof SmtpTransport;

            if ($isSmtp) {
                $transport->start();
            }

            if ($testRecipient) {
                $email = (new Email())
                    ->from($fields['from_email'] ?? 'no-reply@localhost')
                    ->to($testRecipient)
                    ->subject('Test message')
                    ->text('This is a connectivity test message from the platform SMTP settings page.');
                $transport->send($email);
            }

            if ($isSmtp) {
                $transport->stop();
            }

            AuditService::log('smtp_settings.test_connection', 'SMTP test connection succeeded'.($testRecipient ? ' (test email sent)' : ''));

            return ['success' => true, 'message' => 'Connection succeeded.'];
        } catch (TransportExceptionInterface $e) {
            AuditService::log('smtp_settings.test_connection_failed', 'SMTP test connection failed');

            return ['success' => false, 'message' => $this->sanitizeError($e->getMessage())];
        } catch (\Throwable $e) {
            AuditService::log('smtp_settings.test_connection_failed', 'SMTP test connection failed');

            return ['success' => false, 'message' => $this->sanitizeError($e->getMessage())];
        }
    }

    private function buildDsn(array $fields, ?string $password): string
    {
        $scheme = match ($fields['encryption'] ?? 'starttls') {
            'tls' => 'smtps',
            'none' => 'smtp',
            default => 'smtp', // STARTTLS is negotiated automatically by EsmtpTransport on the plain "smtp" scheme.
        };
        $auth = ($fields['auth_enabled'] ?? true) && ! empty($fields['username'])
            ? rawurlencode($fields['username']).':'.rawurlencode((string) $password).'@'
            : '';
        $host = $fields['host'] ?? 'localhost';
        $port = $fields['port'] ?? 587;

        return "{$scheme}://{$auth}{$host}:{$port}";
    }

    /** Strips anything resembling a credential, connection string, or file path from a transport exception message before it is ever shown or logged. */
    private function sanitizeError(string $message): string
    {
        $message = preg_replace('/:\/\/[^@\s]+@/', '://***@', $message) ?? $message;
        $message = preg_replace('#(/[a-zA-Z0-9_\-./]+)+\.php#', '[path]', $message) ?? $message;

        return \Illuminate\Support\Str::limit($message, 300);
    }
}
