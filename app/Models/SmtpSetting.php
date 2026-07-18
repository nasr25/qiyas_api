<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;

/**
 * Single active SMTP configuration row, managed by Super Admin. See
 * app/Services/SmtpSettingsService.php — the only place this model's
 * decrypted password is ever read. `password_encrypted` is always
 * $hidden so a stray `toArray()`/`toJson()`/API Resource call can never
 * leak it — see docs/security/smtp-security.md.
 */
class SmtpSetting extends Model
{
    protected $fillable = [
        'is_enabled', 'host', 'port', 'encryption', 'auth_enabled', 'username',
        'password_encrypted', 'password_set_at', 'from_email', 'from_name_ar', 'from_name_en',
        'reply_to_email', 'reply_to_name', 'connection_timeout', 'send_timeout',
        'verify_certificate', 'queue_enabled', 'retry_count', 'retry_delay',
        'environment_label', 'internal_relay_mode', 'updated_by',
    ];

    protected $hidden = ['password_encrypted'];

    protected function casts(): array
    {
        return [
            'is_enabled' => 'boolean',
            'auth_enabled' => 'boolean',
            'verify_certificate' => 'boolean',
            'queue_enabled' => 'boolean',
            'internal_relay_mode' => 'boolean',
            'password_set_at' => 'datetime',
        ];
    }

    /** Sets the encrypted password. Never call ->password_encrypted directly outside this model/SmtpSettingsService. */
    public function setPassword(?string $plainPassword): void
    {
        if ($plainPassword === null || $plainPassword === '') {
            return; // Preserve existing password — see SmtpSettingsService::save().
        }
        $this->password_encrypted = Crypt::encryptString($plainPassword);
        $this->password_set_at = now();
    }

    /** Decrypts the stored password. Only called from SmtpSettingsService when actually connecting. */
    public function decryptPassword(): ?string
    {
        if (! $this->password_encrypted) {
            return null;
        }

        try {
            return Crypt::decryptString($this->password_encrypted);
        } catch (\Throwable) {
            return null;
        }
    }

    public function hasPassword(): bool
    {
        return ! empty($this->password_encrypted);
    }
}
