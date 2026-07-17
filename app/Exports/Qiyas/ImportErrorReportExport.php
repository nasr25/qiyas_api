<?php

namespace App\Exports\Qiyas;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithTitle;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Echoes the invalid cell values back to the user for correction. Values
 * are user-controlled (they came from the uploaded workbook), so any value
 * starting with `=`, `+`, `-`, or `@` is prefixed with a leading apostrophe
 * before being written — this forces Excel/Sheets to treat it as literal
 * text instead of evaluating it as a formula when the report is reopened
 * (spreadsheet formula injection).
 */
class ImportErrorReportExport implements FromArray, WithHeadings, WithStyles, WithTitle
{
    public function __construct(private readonly array $errors) {}

    public function title(): string
    {
        return 'Import Errors';
    }

    public function headings(): array
    {
        return ['Sheet', 'Row', 'Column', 'Value', 'Error Code', 'Arabic Message', 'English Message'];
    }

    public function array(): array
    {
        return array_map(fn ($e) => [
            $this->escape($e['sheet']), $e['row'], $this->escape($e['column']), $this->escape((string) ($e['value'] ?? '')),
            $e['code'], $this->escape($e['message_ar']), $this->escape($e['message_en']),
        ], $this->errors);
    }

    public function styles(Worksheet $sheet): array
    {
        return [1 => ['font' => ['bold' => true]]];
    }

    private function escape(?string $value): string
    {
        $value = (string) $value;
        if ($value !== '' && in_array($value[0], ['=', '+', '-', '@'], true)) {
            return "'".$value;
        }

        return $value;
    }
}
