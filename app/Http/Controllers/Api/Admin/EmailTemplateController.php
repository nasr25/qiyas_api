<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\EmailTemplate;
use App\Models\RequirementAssignment;
use App\Notifications\WorkflowEventNotification;
use App\Services\AuditService;
use App\Services\EmailTemplateRenderer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Notification;

/**
 * Super Admin only — platform-wide, not program-scoped. See
 * docs/email-notifications.md.
 */
class EmailTemplateController extends Controller
{
    public function __construct(private readonly EmailTemplateRenderer $renderer) {}

    /** GET /api/v1/admin/email-templates */
    public function index(): JsonResponse
    {
        $templates = EmailTemplate::orderBy('event_type')->get();

        return response()->json(['success' => true, 'data' => $templates]);
    }

    /** GET /api/v1/admin/email-templates/{template} */
    public function show(EmailTemplate $template): JsonResponse
    {
        return response()->json(['success' => true, 'data' => $template]);
    }

    /** PUT /api/v1/admin/email-templates/{template} */
    public function update(Request $request, EmailTemplate $template): JsonResponse
    {
        $data = $request->validate([
            'subject_ar' => ['required', 'string', 'max:500'],
            'subject_en' => ['required', 'string', 'max:500'],
            'body_ar' => ['required', 'string'],
            'body_en' => ['required', 'string'],
            'is_enabled' => ['required', 'boolean'],
            'cc_rules' => ['nullable', 'array'],
        ]);

        $this->validateVariables($data['subject_ar'].$data['body_ar'].$data['subject_en'].$data['body_en']);

        $old = $template->only(['subject_ar', 'subject_en', 'body_ar', 'body_en', 'is_enabled']);
        $template->update(array_merge($data, ['updated_by' => $request->user()->id]));

        AuditService::log('email_template.updated', "Updated email template '{$template->template_key}'", $template, $old, $data);

        return response()->json(['success' => true, 'data' => $template->fresh()]);
    }

    /** POST /api/v1/admin/email-templates/{template}/preview */
    public function preview(Request $request, EmailTemplate $template): JsonResponse
    {
        $data = $request->validate(['locale' => ['required', 'in:ar,en']]);

        $sampleVariables = $this->sampleVariables();

        return response()->json([
            'success' => true,
            'data' => [
                'subject' => $this->renderer->renderSubject($template, $data['locale'], $sampleVariables),
                'body' => $this->renderer->renderBody($template, $data['locale'], $sampleVariables),
            ],
        ]);
    }

    /** POST /api/v1/admin/email-templates/{template}/test-send */
    public function testSend(Request $request, EmailTemplate $template): JsonResponse
    {
        $data = $request->validate(['email' => ['required', 'email']]);

        $assignment = RequirementAssignment::query()->first();
        if (! $assignment) {
            return response()->json(['success' => false, 'message' => 'No sample data available to send a test email.'], 422);
        }

        Notification::route('mail', $data['email'])
            ->notify(new WorkflowEventNotification($template->template_key, $assignment));

        AuditService::log('email_template.test_sent', "Sent test email for template '{$template->template_key}' to {$data['email']}", $template);

        return response()->json(['success' => true, 'message' => 'Test email queued.']);
    }

    private function validateVariables(string $content): void
    {
        preg_match_all('/\{\{\s*([a-zA-Z0-9_]+)\s*\}\}/', $content, $matches);
        $unknown = array_diff(array_unique($matches[1] ?? []), EmailTemplateRenderer::supportedVariables());
        if ($unknown) {
            abort(422, 'Unsupported template variable(s): '.implode(', ', $unknown));
        }
    }

    private function sampleVariables(): array
    {
        return array_combine(
            EmailTemplateRenderer::supportedVariables(),
            array_map(fn ($v) => "[{$v}]", EmailTemplateRenderer::supportedVariables()),
        );
    }
}
