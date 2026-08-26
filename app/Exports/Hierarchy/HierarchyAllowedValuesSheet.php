<?php

namespace App\Exports\Hierarchy;

use App\Models\ComplianceProgram;
use App\Models\HierarchyLevelDefinition;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithColumnWidths;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithTitle;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Controlled values a filler may use, generated from the program's own
 * structure rather than a fixed list — so a program with different levels
 * gets a different sheet with no code change.
 *
 * Deliberately descriptive rather than an Excel data-validation dropdown:
 * the importer enforces these values regardless of what the workbook
 * allows, and a dropdown that disagreed with server-side validation would
 * be worse than none.
 */
class HierarchyAllowedValuesSheet implements FromArray, WithColumnWidths, WithHeadings, WithStyles, WithTitle
{
    /** @var array<int, HierarchyLevelDefinition> */
    private array $levels;

    public function __construct(
        private readonly ComplianceProgram $program,
        array $levels,
    ) {
        $this->levels = array_values($levels);
    }

    public function title(): string
    {
        return 'Allowed Values';
    }

    public function headings(): array
    {
        return ['الحقل / Field', 'القيم المسموحة / Allowed Values', 'ملاحظات / Notes'];
    }

    public function array(): array
    {
        $rows = [
            ['status', 'active, draft, archived', 'حالة العقدة / Node status'],
            ['boolean fields', 'نعم/لا · yes/no · true/false · 1/0', 'تُقبل أي من هذه الصيغ / Any of these forms are accepted'],
            ['dates', 'YYYY-MM-DD', 'مثال / Example: 2026-12-31'],
            ['weight', '0 – 100', 'رقم عشري / Decimal, up to two places'],
            ['', '', ''],
            ['— مستويات هذا البرنامج / This program\'s levels —', '', ''],
        ];

        foreach ($this->levels as $index => $level) {
            $traits = [];
            if ($level->is_assignable) {
                $traits[] = 'قابل للإسناد / assignable';
            }
            if ($level->is_assessable) {
                $traits[] = 'قابل للتقييم / assessable';
            }
            if ($level->accepts_evidence) {
                $traits[] = 'يقبل الإثبات / evidence';
            }
            if ($level->is_required) {
                $traits[] = 'إلزامي / required';
            }

            $rows[] = [
                $level->key,
                sprintf('%s · %s', $level->name_ar, $level->name_en),
                sprintf('المستوى %d — %s', $index + 1, $traits ? implode('، ', $traits) : 'تجميعي / grouping only'),
            ];
        }

        return $rows;
    }

    public function columnWidths(): array
    {
        return ['A' => 34, 'B' => 46, 'C' => 60];
    }

    public function styles(Worksheet $sheet): array
    {
        return [1 => [
            'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
            'fill' => ['fillType' => 'solid', 'startColor' => ['rgb' => '00281E']],
        ]];
    }
}
