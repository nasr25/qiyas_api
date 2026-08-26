<?php

namespace App\Exports\Hierarchy;

use App\Models\ComplianceProgram;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithColumnWidths;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithTitle;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Bilingual explanation of every machine identifier on the Requirements
 * sheet. This is where Arabic and English headings live (requirement 5 of
 * the XLSX brief) — the data sheet itself stays language-neutral so a
 * template is not tied to the locale it was downloaded in.
 */
class HierarchyInstructionsSheet implements FromArray, WithColumnWidths, WithHeadings, WithStyles, WithTitle
{
    public function __construct(
        private readonly array $columns,
        private readonly ComplianceProgram $program,
        private readonly array $levels = [],
    ) {}

    public function title(): string
    {
        return 'Instructions';
    }

    public function headings(): array
    {
        return ['column_identifier', 'العنوان بالعربية', 'English Heading', 'مطلوب / Required'];
    }

    public function array(): array
    {
        $rows = [];
        foreach ($this->columns as $column) {
            $rows[] = [
                $column['key'],
                $column['label_ar'],
                $column['label_en'],
                $column['required'] ? 'نعم / Yes' : 'لا / No',
            ];
        }

        $rows[] = ['', '', '', ''];
        $rows[] = ['— ترتيب المستويات / Level order —', '', '', ''];

        foreach (array_values($this->levels) as $index => $level) {
            $traits = [];
            if ($level->is_assignable) {
                $traits[] = 'قابل للإسناد / assignable';
            }
            if ($level->is_assessable) {
                $traits[] = 'قابل للتقييم / assessable';
            }
            if ($level->accepts_evidence) {
                $traits[] = 'يقبل الإثبات / accepts evidence';
            }

            $rows[] = [
                (string) ($index + 1),
                $level->name_ar,
                $level->name_en,
                ($level->is_required ? 'إلزامي / Required' : 'اختياري / Optional')
                    .($traits ? ' — '.implode('، ', $traits) : ''),
            ];
        }

        $rows[] = ['', '', '', ''];
        $rows[] = ['— قواعد التعبئة / Filling rules —', '', '', ''];

        foreach ([
            ['كل صف يمثل عنصرًا في أعمق مستوى، مع تكرار رموز المستويات الأعلى.',
                'Each row is one deepest-level item, repeating its ancestors\' codes.'],
            ['الصفوف التي تشترك في نفس رموز المستويات الأعلى تُعيد استخدام نفس العقد الأب ولا تُنشئ نسخًا مكررة.',
                'Rows sharing the same ancestor codes reuse the same parent nodes; duplicates are not created.'],
            ['لا يجوز ترك مستوى وسيط فارغًا مع تعبئة مستوى أعمق منه.',
                'A middle level may not be left blank while a deeper level is filled.'],
            ['التواريخ بصيغة YYYY-MM-DD. الأوزان بين 0 و100.',
                'Dates use YYYY-MM-DD. Weights are between 0 and 100.'],
            ['لا تبدأ أي قيمة نصية بالرموز = + - @ لأنها تُفسَّر كصيغة وستُرفض.',
                'Do not start a text value with = + - @; it reads as a formula and will be rejected.'],
            ['لا تُغيّر ورقة _metadata، وإلا سيُرفض الملف.',
                'Do not edit the _metadata sheet, or the file will be rejected.'],
            ['العناوين الظاهرة للتوضيح فقط؛ هوية الأعمدة مأخوذة من ورقة _metadata.',
                'Visible headings are for readability only; column identity comes from _metadata.'],
        ] as [$ar, $en]) {
            $rows[] = ['—', $ar, $en, ''];
        }

        return $rows;
    }

    public function columnWidths(): array
    {
        return ['A' => 32, 'B' => 46, 'C' => 46, 'D' => 18];
    }

    public function styles(Worksheet $sheet): array
    {
        return [1 => [
            'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
            'fill' => ['fillType' => 'solid', 'startColor' => ['rgb' => '00281E']],
        ]];
    }
}
