<?php

namespace App\Events;

use App\Models\RequirementAssignment;
use App\Models\User;

/**
 * Dispatched by the workflow/extension domain services whenever a business
 * event occurred that some recipients need to be told about —
 * WorkflowService/ExtensionService never call NotificationService
 * directly (see docs/notification-engine.md); SendWorkflowNotification
 * (app/Listeners) is the only thing that does, keeping notification
 * delivery decoupled from the transition logic itself.
 *
 * Recipients are resolved by the dispatching service (which already knows
 * the department/program/role context needed to find them) and passed in
 * the payload rather than re-resolved in the listener, so this event
 * carries exactly one piece of new architecture — the publish/subscribe
 * seam — without duplicating recipient-resolution logic in two places.
 */
class WorkflowNotificationRequested
{
    /** @param User[] $recipients */
    public function __construct(
        public readonly string $eventType,
        public readonly RequirementAssignment $assignment,
        public readonly array $recipients,
    ) {}
}
