<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Managed by Super Admin only. See docs/email-notifications.md for the
 * full event list and supported variables.
 */
class EmailTemplate extends Model
{
    protected $fillable = [
        'template_key', 'event_type', 'subject_ar', 'subject_en', 'body_ar', 'body_en',
        'is_enabled', 'supported_variables', 'default_recipient_rules', 'cc_rules', 'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'is_enabled' => 'boolean',
            'supported_variables' => 'array',
            'default_recipient_rules' => 'array',
            'cc_rules' => 'array',
        ];
    }

    public function updater()
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function subject(string $locale): string
    {
        return $locale === 'ar' ? $this->subject_ar : $this->subject_en;
    }

    public function body(string $locale): string
    {
        return $locale === 'ar' ? $this->body_ar : $this->body_en;
    }
}
