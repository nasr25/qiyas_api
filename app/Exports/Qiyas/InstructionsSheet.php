<?php

namespace App\Exports\Qiyas;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithColumnWidths;
use Maatwebsite\Excel\Concerns\WithTitle;

class InstructionsSheet implements FromArray, WithColumnWidths, WithTitle
{
    public function title(): string
    {
        return 'Instructions';
    }

    public function array(): array
    {
        return [
            ['العمود / Column', 'الوصف / Description'],
            ['perspective', 'المنظور (Perspective) — بالعربية. يُستخدم لتصنيف المعايير حسب المنظور الاستراتيجي.'],
            ['axis', 'المحور (Axis) — بالعربية. التصنيف الفرعي داخل المنظور.'],
            ['standard_number', 'رمز المعيار (Standard Code) — إلزامي، فريد ضمن الدورة.'],
            ['name_ar', 'اسم المعيار بالعربية (Standard Name — Arabic) — إلزامي.'],
            ['name_en', 'اسم المعيار بالإنجليزية (Standard Name — English) — اختياري.'],
            ['description', 'وصف المعيار (Standard Description) — اختياري.'],
            ['application_requirements', 'الهدف / متطلبات التطبيق (Objective) — اختياري.'],
            ['evidence_documents', 'متطلبات الإثبات (Evidence Requirements) — اختياري.'],
            ['weight', 'الوزن (Weight) — رقم بين 0 و100.'],
            ['due_date', 'تاريخ الاستحقاق الافتراضي (Default Due Date) — بصيغة YYYY-MM-DD.'],
            [],
            ['ملاحظات عامة / General notes'],
            ['- لا تقم بتغيير أسماء الأعمدة في الصف الأول من ورقة Requirements.'],
            ['- Do not rename the column headers in row 1 of the Requirements sheet.'],
            ['- الصفوف الفارغة يتم تجاهلها تلقائياً. / Empty rows are skipped automatically.'],
            ['- لا يتم دعم ملفات الماكرو (.xlsm). / Macro-enabled workbooks (.xlsm) are not supported.'],
        ];
    }

    public function columnWidths(): array
    {
        return ['A' => 30, 'B' => 80];
    }
}
