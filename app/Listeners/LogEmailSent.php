<?php

namespace App\Listeners;

use App\Models\EmailLog;
use Illuminate\Mail\Events\MessageSent;

/**
 * Marks the matching email log as `sent` once delivery to the transport
 * succeeds (correlated via the X-Email-Log header set when sending).
 */
class LogEmailSent
{
    public function handle(MessageSent $event): void
    {
        try {
            $header = $event->message->getHeaders()->get('X-Email-Log');
            $id = $header ? $header->getBodyAsString() : null;

            $log = $id ? EmailLog::find($id) : null;
            $log?->update(['status' => 'sent', 'sent_at' => now()]);
        } catch (\Throwable $e) {
            // ignore
        }
    }
}
