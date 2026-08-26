<?php

namespace App\Services;

use App\Models\EmailTemplate;

/**
 * Renders an EmailTemplate's subject/body for a locale by substituting
 * `{{variable}}` placeholders with plain-text values only — no code
 * execution, no PHP evaluation of template content. Unknown placeholders
 * are left as literal text rather than silently dropped, so a missing
 * variable is visible instead of producing a confusing blank. Values are
 * escaped for HTML output; the subject additionally has line breaks
 * stripped to prevent header injection.
 *
 * Used both for actually sending mail (WorkflowEventNotification) and for
 * the Super Admin template preview endpoint, so the two can never diverge.
 */
class EmailTemplateRenderer
{
    public function renderSubject(EmailTemplate $template, string $locale, array $variables): string
    {
        $subject = $template->subject($locale);

        return $this->substitute($subject, $variables, escapeHtml: false, stripNewlines: true);
    }

    public function renderBody(EmailTemplate $template, string $locale, array $variables): string
    {
        $body = $template->body($locale);

        return $this->substitute($body, $variables, escapeHtml: true, stripNewlines: false);
    }

    private function substitute(string $text, array $variables, bool $escapeHtml, bool $stripNewlines): string
    {
        $rendered = preg_replace_callback('/\{\{\s*([a-zA-Z0-9_]+)\s*\}\}/', function ($matches) use ($variables, $escapeHtml) {
            $key = $matches[1];
            if (! array_key_exists($key, $variables)) {
                return $matches[0]; // Leave unknown placeholders visible rather than blank.
            }

            $value = (string) ($variables[$key] ?? '');

            return $escapeHtml ? e($value) : $value;
        }, $text);

        if ($stripNewlines) {
            $rendered = str_replace(["\r", "\n"], ' ', $rendered);
        }

        return $rendered;
    }

    /** The canonical variable list, used for validation and the Super Admin editor's "insert variable" helper. */
    public static function supportedVariables(): array
    {
        return [
            'recipient_name', 'employee_name', 'reviewer_name', 'department_name',
            'program_name', 'cycle_name', 'requirement_code', 'requirement_name',
            'current_status', 'due_date', 'effective_due_date', 'requested_due_date',
            'days_remaining', 'days_overdue', 'sla_due_at', 'sla_breach_duration',
            'rejection_reason', 'review_notes', 'action_url',
        ];
    }
}
