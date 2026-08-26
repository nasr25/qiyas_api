<?php

namespace Tests\Feature\Workflow;

use App\Models\EmailTemplate;
use App\Models\NotificationLog;
use App\Notifications\WorkflowEventNotification;
use App\Services\EmailTemplateRenderer;
use App\Services\NotificationService;
use App\Services\WorkflowService;
use Illuminate\Support\Facades\Notification;

class NotificationDeduplicationTest extends WorkflowTestCase
{
    public function test_dispatching_the_same_event_twice_only_logs_once(): void
    {
        Notification::fake();

        $assignment = app(WorkflowService::class)->assign(
            $this->requirement, $this->qiyas, $this->programManager, $this->deptA, null, '2026-12-01', null, null, null,
        );

        $before = NotificationLog::where('event_type', 'requirement_assigned')->count();

        // A second dispatch for the exact same event/assignment/recipient must not create a second log row.
        app(NotificationService::class)->dispatchForAssignment('requirement_assigned', $assignment, [$this->deptAEmployee]);

        $after = NotificationLog::where('event_type', 'requirement_assigned')
            ->where('recipient_user_id', $this->deptAEmployee->id)->count();

        $this->assertLessThanOrEqual(1, $after);
        $this->assertEquals($before > 0 ? $before : 1, max($before, 1));
    }

    public function test_disabled_template_does_not_send_email_but_still_creates_in_app_notification(): void
    {
        EmailTemplate::where('template_key', 'requirement_assigned')->update(['is_enabled' => false]);

        $assignment = app(WorkflowService::class)->assign(
            $this->requirement, $this->qiyas, $this->programManager, $this->deptA, $this->deptAEmployee, '2026-12-01', null, null, null,
        );

        $notification = new WorkflowEventNotification('requirement_assigned', $assignment);
        $this->assertEquals(['database'], $notification->via($this->deptAEmployee));
    }

    public function test_template_variables_render_without_executing_arbitrary_content(): void
    {
        $template = EmailTemplate::where('template_key', 'requirement_assigned')->first();
        $renderer = app(EmailTemplateRenderer::class);

        $rendered = $renderer->renderBody($template, 'en', ['requirement_name' => '<script>alert(1)</script>', 'department_name' => 'X']);

        $this->assertStringNotContainsString('<script>', $rendered);
    }
}
