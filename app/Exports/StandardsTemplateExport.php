<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithColumnWidths;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithTitle;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Downloadable .xlsx template for bulk-importing standards.
 * Header names are the exact keys the importer expects (snake_case).
 */
class StandardsTemplateExport implements FromArray, WithHeadings, WithStyles, WithTitle, WithColumnWidths
{
    public function title(): string
    {
        return 'Standards Template';
    }

    public function headings(): array
    {
        return [
            'standard_number',
            'name_ar',
            'name_en',
            'description',
            'version',
            'weight',
            'due_date',
            'departments',
        ];
    }

    /** Two example rows showing the expected format. */
    public function array(): array
    {
        return [
            ['STD-001', 'معيار تجريبي أول', 'Sample Standard One', 'وصف اختياري', '1.0', 10, '2026-12-31', 'تقنية المعلومات, الموارد البشرية'],
            ['STD-002', 'معيار تجريبي ثانٍ', 'Sample Standard Two', '', '', 5, '', ''],
        ];
    }

    public function columnWidths(): array
    {
        return ['A' => 18, 'B' => 30, 'C' => 30, 'D' => 35, 'E' => 10, 'F' => 10, 'G' => 14, 'H' => 40];
    }

    public function styles(Worksheet $sheet): array
    {
        return [
            1 => [
                'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
                'fill' => ['fillType' => 'solid', 'startColor' => ['rgb' => '00281E']],
            ],
        ];
    }
}
