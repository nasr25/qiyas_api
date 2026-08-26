<?php

namespace App\Exports\Hierarchy;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithColumnWidths;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithTitle;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Downloadable error report for a failed import.
 *
 * Every row locates the problem precisely — sheet, row, column, the machine
 * field, the original value — and carries a stable error CODE plus bilingual
 * messages. Internal exception text and SQL are never included: the codes
 * are the contract, and a database error is a platform bug, not something a
 * Program Manager can act on.
 */
class HierarchyImportErrorReport implements FromArray, WithColumnWidths, WithHeadings, WithStyles, WithTitle
{
    public function __construct(private readonly array $errors) {}

    public function title(): string
    {
        return 'Errors';
    }

    public function headings(): array
    {
        return [
            'الورقة / Sheet',
            'الصف / Row',
            'العمود / Column',
            'المستوى / Level',
            'القيمة / Value',
            'الرمز / Code',
            'الرسالة (عربي)',
            'Message (English)',
        ];
    }

    public function array(): array
    {
        return array_map(fn (array $error) => [
            $error['sheet'] ?? '-',
            $error['row'] ?? 0,
            $error['column'] ?? '-',
            // The column identifier encodes its level, e.g. `policy_code`.
            $this->levelFromColumn((string) ($error['column'] ?? '')),
            $this->stringify($error['value'] ?? null),
            $error['code'] ?? '-',
            $error['message_ar'] ?? '',
            $error['message_en'] ?? '',
        ], $this->errors);
    }

    private function levelFromColumn(string $column): string
    {
        foreach (['_code', '_name_ar', '_name_en'] as $suffix) {
            if (str_ends_with($column, $suffix)) {
                return substr($column, 0, -strlen($suffix));
            }
        }

        return '-';
    }

    /** Values are echoed back as text so a formula payload cannot re-arm. */
    private function stringify(mixed $value): string
    {
        if ($value === null) {
            return '';
        }
        $text = is_scalar($value) ? (string) $value : json_encode($value, JSON_UNESCAPED_UNICODE);

        return in_array(substr($text, 0, 1), ['=', '+', '-', '@'], true) ? "'".$text : $text;
    }

    public function columnWidths(): array
    {
        return ['A' => 16, 'B' => 8, 'C' => 26, 'D' => 20, 'E' => 30, 'F' => 30, 'G' => 60, 'H' => 60];
    }

    public function styles(Worksheet $sheet): array
    {
        return [1 => [
            'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
            'fill' => ['fillType' => 'solid', 'startColor' => ['rgb' => '8B1A1A']],
        ]];
    }
}
