<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class NotificationLog extends Model
{
    protected $fillable = [
        'template_key', 'event_type', 'idempotency_key', 'recipient_user_id', 'recipient_email',
        'compliance_program_id', 'program_cycle_id', 'subject', 'status', 'error', 'attempts', 'sent_at',
    ];

    protected function casts(): array
    {
        return ['sent_at' => 'datetime'];
    }

    public function recipient()
    {
        return $this->belongsTo(User::class, 'recipient_user_id');
    }
}
