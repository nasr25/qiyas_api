<?php

namespace App\Listeners;

use App\Models\EmailLog;
use Illuminate\Mail\Events\MessageSending;

/**
 * Records each outgoing email as `pending` just before it is sent, and tags the
 * message with its log id so the matching MessageSent event can mark it `sent`.
 */
class LogEmailSending
{
    public function handle(MessageSending $event): void
    {
        try {
            $message = $event->message; // Symfony\Component\Mime\Email

            $log = EmailLog::create([
                'to_address' => collect($message->getTo())->map(fn ($a) => $a->getAddress())->implode(', '),
                'subject'    => $message->getSubject(),
                'body'       => $message->getHtmlBody() ?: $message->getTextBody(),
                'status'     => 'pending',
                'mailer'     => config('mail.default'),
            ]);

            $message->getHeaders()->addTextHeader('X-Email-Log', (string) $log->id);
        } catch (\Throwable $e) {
            // Never let logging break mail delivery.
        }
    }
}
