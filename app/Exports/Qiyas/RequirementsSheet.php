<?php

namespace App\Exports\Qiyas;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithColumnWidths;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithTitle;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Column headers are stable machine identifiers (never translated) — the
 * bilingual Qiyas-terminology explanation of each column lives on the
 * Instructions sheet. See docs/qiyas-xlsx-import.md.
 *
 * As of Phase 4, the column list is normally resolved from program
 * configuration (category 'import', see
 * QiyasRequirementsTemplateExport::resolveColumns() and
 * docs/import-export-engine.md) rather than read directly from COLUMNS —
 * the constant below is kept only as the fallback default so this class
 * still works standalone (e.g. in code that constructs it directly without
 * going through the exporter) and so existing tests need no changes.
 */
class RequirementsSheet implements FromArray, WithColumnWidths, WithHeadings, WithStyles, WithTitle
{
    public const COLUMNS = [
        'perspective', 'axis', 'standard_number', 'name_ar', 'name_en',
        'description', 'application_requirements', 'evidence_documents',
        'weight', 'due_date',
    ];

    public function __construct(private readonly ?array $columns = null) {}

    public function title(): string
    {
        return 'Requirements';
    }

    public function headings(): array
    {
        return $this->columns ?? self::COLUMNS;
    }

    public function array(): array
    {
        return [
            [
                'الأداء المؤسسي', 'التخطيط الاستراتيجي', 'STD-101', 'معيار تجريبي', 'Sample Standard',
                'وصف المعيار', 'الهدف من المعيار', 'الأدلة المطلوبة', 10, '2026-12-31',
            ],
            [
                'التمكين الرقمي', 'أمن المعلومات', 'STD-102', 'معيار تجريبي ثانٍ', 'Second Sample Standard',
                '', '', '', 5, '',
            ],
        ];
    }

    public function columnWidths(): array
    {
        return ['A' => 20, 'B' => 20, 'C' => 16, 'D' => 30, 'E' => 30, 'F' => 35, 'G' => 30, 'H' => 30, 'I' => 10, 'J' => 16];
    }

    public function styles(Worksheet $sheet): array
    {
        return [1 => ['font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']], 'fill' => ['fillType' => 'solid', 'startColor' => ['rgb' => '00281E']]]];
    }
}
