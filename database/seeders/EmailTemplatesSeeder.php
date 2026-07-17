<?php

namespace Database\Seeders;

use App\Models\EmailTemplate;
use App\Services\EmailTemplateRenderer;
use Illuminate\Database\Seeder;

/**
 * Default bilingual templates for every Phase 2 workflow event actually
 * dispatched by WorkflowService/ExtensionService/ProcessSlaCommand. Super
 * Admin can edit these from the platform email template management page —
 * this seeder only establishes safe, sensible defaults. Idempotent.
 */
class EmailTemplatesSeeder extends Seeder
{
    public function run(): void
    {
        $common = [
            'is_enabled' => true,
            'supported_variables' => EmailTemplateRenderer::supportedVariables(),
            'default_recipient_rules' => ['assignment_stakeholders'],
        ];

        $templates = [
            [
                'template_key' => 'requirement_assigned', 'event_type' => 'requirement_assigned',
                'subject_ar' => 'تم إسناد معيار جديد إلى {{department_name}}',
                'subject_en' => 'A new requirement has been assigned to {{department_name}}',
                'body_ar' => 'مرحباً {{recipient_name}}،\n\nتم إسناد المعيار "{{requirement_name}}" ({{requirement_code}}) في برنامج {{program_name}} إلى إدارتكم. الموعد النهائي: {{due_date}}.',
                'body_en' => 'Hello {{recipient_name}},\n\nThe requirement "{{requirement_name}}" ({{requirement_code}}) in {{program_name}} has been assigned to your department. Due date: {{due_date}}.',
            ],
            [
                'template_key' => 'requirement_reassigned', 'event_type' => 'requirement_reassigned',
                'subject_ar' => 'تم إعادة إسناد المعيار {{requirement_code}}',
                'subject_en' => 'Requirement {{requirement_code}} has been reassigned',
                'body_ar' => 'تم إعادة إسناد المعيار "{{requirement_name}}" إلى إدارة {{department_name}} في برنامج {{program_name}}.',
                'body_en' => 'The requirement "{{requirement_name}}" has been reassigned to {{department_name}} in {{program_name}}.',
            ],
            [
                'template_key' => 'employee_reassigned', 'event_type' => 'employee_reassigned',
                'subject_ar' => 'تم تحديث الموظف المسؤول عن {{requirement_code}}',
                'subject_en' => 'Responsible employee updated for {{requirement_code}}',
                'body_ar' => 'تم تحديث الموظف المسؤول عن المعيار "{{requirement_name}}".',
                'body_en' => 'The responsible employee for "{{requirement_name}}" has been updated.',
            ],
            [
                'template_key' => 'submission_sent_to_department_manager', 'event_type' => 'submission_sent_to_department_manager',
                'subject_ar' => 'مستند بانتظار مراجعتكم — {{requirement_code}}',
                'subject_en' => 'A submission is awaiting your review — {{requirement_code}}',
                'body_ar' => 'قام {{employee_name}} بتقديم مستند إثبات للمعيار "{{requirement_name}}". الحالة: {{current_status}}.',
                'body_en' => '{{employee_name}} has submitted evidence for "{{requirement_name}}". Status: {{current_status}}.',
            ],
            [
                'template_key' => 'department_manager_rejected', 'event_type' => 'department_manager_rejected',
                'subject_ar' => 'تم إرجاع طلبكم للتعديل — {{requirement_code}}',
                'subject_en' => 'Your submission was returned for revision — {{requirement_code}}',
                'body_ar' => 'قام مدير الإدارة بإرجاع مستند "{{requirement_name}}". سبب الإرجاع: {{rejection_reason}}.',
                'body_en' => 'The Department Manager returned your submission for "{{requirement_name}}". Reason: {{rejection_reason}}.',
            ],
            [
                'template_key' => 'submission_sent_to_auditor', 'event_type' => 'submission_sent_to_auditor',
                'subject_ar' => 'مستند بانتظار مراجعة المدقق — {{requirement_code}}',
                'subject_en' => 'A submission is awaiting Auditor review — {{requirement_code}}',
                'body_ar' => 'وافق مدير الإدارة على مستند "{{requirement_name}}" وهو الآن بانتظار مراجعتكم.',
                'body_en' => 'The Department Manager approved "{{requirement_name}}" — it now awaits your review.',
            ],
            [
                'template_key' => 'auditor_rejected', 'event_type' => 'auditor_rejected',
                'subject_ar' => 'تم إرجاع طلبكم للتعديل من قبل المدقق — {{requirement_code}}',
                'subject_en' => 'Your submission was returned by the Auditor — {{requirement_code}}',
                'body_ar' => 'قام المدقق بإرجاع مستند "{{requirement_name}}". سبب الإرجاع: {{rejection_reason}}.',
                'body_en' => 'The Auditor returned your submission for "{{requirement_name}}". Reason: {{rejection_reason}}.',
            ],
            [
                'template_key' => 'submission_sent_to_program_manager', 'event_type' => 'submission_sent_to_program_manager',
                'subject_ar' => 'مستند بانتظار الاعتماد النهائي — {{requirement_code}}',
                'subject_en' => 'A submission is awaiting final approval — {{requirement_code}}',
                'body_ar' => 'وافق المدقق على مستند "{{requirement_name}}" وهو الآن بانتظار اعتمادكم النهائي.',
                'body_en' => 'The Auditor approved "{{requirement_name}}" — it now awaits your final approval.',
            ],
            [
                'template_key' => 'program_manager_rejected', 'event_type' => 'program_manager_rejected',
                'subject_ar' => 'تم رفض طلبكم من قبل مدير البرنامج — {{requirement_code}}',
                'subject_en' => 'Your submission was rejected by the Program Manager — {{requirement_code}}',
                'body_ar' => 'قام مدير البرنامج بإرجاع مستند "{{requirement_name}}". سبب الرفض: {{rejection_reason}}.',
                'body_en' => 'The Program Manager returned your submission for "{{requirement_name}}". Reason: {{rejection_reason}}.',
            ],
            [
                'template_key' => 'program_manager_approved', 'event_type' => 'program_manager_approved',
                'subject_ar' => 'تم اعتماد المعيار نهائياً — {{requirement_code}}',
                'subject_en' => 'Requirement finally approved — {{requirement_code}}',
                'body_ar' => 'تم اعتماد المعيار "{{requirement_name}}" بشكل نهائي من قبل مدير البرنامج.',
                'body_en' => 'The requirement "{{requirement_name}}" has been finally approved by the Program Manager.',
            ],
            [
                'template_key' => 'extension_requested', 'event_type' => 'extension_requested',
                'subject_ar' => 'طلب تمديد جديد — {{requirement_code}}',
                'subject_en' => 'New extension request — {{requirement_code}}',
                'body_ar' => 'طلب {{employee_name}} تمديد الموعد النهائي للمعيار "{{requirement_name}}" إلى {{requested_due_date}}.',
                'body_en' => '{{employee_name}} requested extending the due date for "{{requirement_name}}" to {{requested_due_date}}.',
            ],
            [
                'template_key' => 'extension_approved', 'event_type' => 'extension_approved',
                'subject_ar' => 'تمت الموافقة على طلب التمديد — {{requirement_code}}',
                'subject_en' => 'Extension request approved — {{requirement_code}}',
                'body_ar' => 'تمت الموافقة على تمديد الموعد النهائي للمعيار "{{requirement_name}}" إلى {{effective_due_date}}.',
                'body_en' => 'The extension for "{{requirement_name}}" was approved. New due date: {{effective_due_date}}.',
            ],
            [
                'template_key' => 'extension_rejected', 'event_type' => 'extension_rejected',
                'subject_ar' => 'تم رفض طلب التمديد — {{requirement_code}}',
                'subject_en' => 'Extension request rejected — {{requirement_code}}',
                'body_ar' => 'تم رفض طلب تمديد المعيار "{{requirement_name}}".',
                'body_en' => 'The extension request for "{{requirement_name}}" was rejected.',
            ],
            [
                'template_key' => 'sla_warning', 'event_type' => 'sla_warning',
                'subject_ar' => 'تنبيه: اقتراب انتهاء مهلة SLA — {{requirement_code}}',
                'subject_en' => 'Warning: SLA deadline approaching — {{requirement_code}}',
                'body_ar' => 'مهلة المعالجة للمعيار "{{requirement_name}}" على وشك الانتهاء. الموعد النهائي لهذه المرحلة: {{sla_due_at}}.',
                'body_en' => 'The processing SLA for "{{requirement_name}}" is approaching its deadline: {{sla_due_at}}.',
            ],
            [
                'template_key' => 'sla_breached', 'event_type' => 'sla_breached',
                'subject_ar' => 'تم تجاوز مهلة SLA — {{requirement_code}}',
                'subject_en' => 'SLA deadline breached — {{requirement_code}}',
                'body_ar' => 'تم تجاوز مهلة معالجة المعيار "{{requirement_name}}" المحددة بموجب اتفاقية مستوى الخدمة.',
                'body_en' => 'The SLA processing deadline for "{{requirement_name}}" has been breached.',
            ],
            [
                'template_key' => 'requirement_overdue', 'event_type' => 'requirement_overdue',
                'subject_ar' => 'المعيار متأخر عن موعد التسليم — {{requirement_code}}',
                'subject_en' => 'Requirement overdue — {{requirement_code}}',
                'body_ar' => 'تجاوز المعيار "{{requirement_name}}" موعد التسليم المحدد ({{effective_due_date}}).',
                'body_en' => 'The requirement "{{requirement_name}}" has passed its delivery due date ({{effective_due_date}}).',
            ],
        ];

        foreach ($templates as $template) {
            EmailTemplate::firstOrCreate(['template_key' => $template['template_key']], array_merge($common, $template));
        }
    }
}
