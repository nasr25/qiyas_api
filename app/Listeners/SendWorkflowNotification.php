<?php

namespace App\Listeners;

use App\Events\WorkflowNotificationRequested;
use App\Services\NotificationService;
use Illuminate\Support\Facades\Log;

/**
 * The only listener for WorkflowNotificationRequested — the single place
 * that actually calls NotificationService for a workflow/extension event.
 * Registered synchronously (not itself queued): NotificationService's own
 * dispatchForAssignment() already queues the underlying
 * WorkflowEventNotification per recipient, so queuing this listener too
 * would add a redundant hop without changing delivery behavior.
 *
 * The dispatch call is wrapped in a try/catch: notification delivery is a
 * best-effort side effect of a workflow transition, never a precondition
 * for it. With a real (non-sync) queue connection a transport failure
 * would already be isolated to the background job — but if the platform
 * is ever run with QUEUE_CONNECTION=sync (a valid, simple deployment
 * choice, and how this was actually caught: the Phase 4 E2E environment
 * uses it), the underlying mail send happens inline, and a mailer/SMTP
 * failure must not fail the assignment/submission/review action that
 * triggered it. See docs/notification-engine.md.
 */
class SendWorkflowNotification
{
    public function __construct(private readonly NotificationService $notifications) {}

    public function handle(WorkflowNotificationRequested $event): void
    {
        try {
            $this->notifications->dispatchForAssignment(
                $event->eventType,
                $event->assignment,
                array_filter($event->recipients),
            );
        } catch (\Throwable $e) {
            Log::error("Notification dispatch failed for event '{$event->eventType}' on assignment #{$event->assignment->id}: {$e->getMessage()}");
        }
    }
}
