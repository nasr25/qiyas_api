# Email Template Administration

**Author:** Nasser

Super Admin → Settings → Email Templates (`/admin/settings`).

## Scope

**Global templates only**, one per workflow event type (e.g.
`requirement_assigned`, `sla_warning`, `submission_sent_to_auditor` —
16 seeded events; see `database/seeders/EmailTemplatesSeeder.php` for
the full list). There is **no program-scoped override** — the brief
that requested this feature described a Program-Manager-managed
per-program override as conditional on it "already being approved by
the authorization model," and no such program-scoped email-template
authorization exists in this platform's current role model, so it was
not added. This is a scope decision, not an oversight.

## Editing

Each template has: bilingual subject (AR/EN), bilingual body (AR/EN,
plain text with `{{variable}}` placeholders), and an enabled toggle.
Disabling a template stops its email delivery entirely (the
corresponding in-app/database notification still fires — only the
email leg is gated by `is_enabled`).

## Available variables

The editor lists every supported `{{variable}}` (recipient_name,
program_name, requirement_code, due_date, rejection_reason,
action_url, etc. — see `EmailTemplateRenderer::supportedVariables()`
for the authoritative list). Typing an unsupported variable name and
saving is **rejected by the API with a 422** — verified in this
phase's Playwright suite.

## Preview

"Preview (AR)"/"Preview (EN)" renders the template with sample
variable values (each shown as `[variable_name]` so a missing
substitution is obviously visible, never silently blank) — this uses
the exact same `EmailTemplateRenderer` code path as real delivery, so
the preview can never diverge from what an actual email looks like.

## Test Send

Sends a real templated email (using the first available sample
`RequirementAssignment` as data) to an address the Super Admin enters.
Requires at least one assignment to exist in the target environment to
have sample data to render against.

## Sanitization

The template body is treated as **plain text** with variable
substitution — inserted variable *values* are HTML-escaped, and the
rendered result is delivered through Laravel's default Markdown
notification mail (`MailMessage::line()`), which itself HTML-escapes
line content. A script tag typed directly into a template body cannot
execute as live HTML in the sent email or in the in-app preview — 
verified in this phase's Playwright suite by saving a body containing
a `<script>` tag and confirming it never executes. There is no rich
HTML/WYSIWYG body editor — templates are plain text by design, which
is itself the primary sanitization control (there is no HTML markup
surface for an unsafe tag/iframe/remote-image/tracking-pixel to hide
in).

## Version history / restore

**Not implemented.** Unlike branding assets and SMTP settings, email
templates do not currently have version history or a restore-previous/
restore-default action — an edit overwrites the template's current
row directly. This is a real gap against the brief's stated
requirement and is recorded in the final readiness report rather than
silently omitted.

## Authorization

Restricted to Super Admin — verified with Playwright tests confirming
a 403 for other roles.
