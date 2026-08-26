<?php

namespace App\Exports\Hierarchy;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithColumnWidths;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithTitle;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * The data sheet.
 *
 * Visible headings use the program's own terminology in the requested
 * language ("رمز المنظور" / "Perspective Code"), because a Program Manager
 * filling this in should not have to read machine identifiers.
 *
 * The importer NEVER reads these headings to identify a column. Identity
 * comes from `_metadata.column_identifiers`, which lists the stable machine
 * keys in exact column order — so translating a heading, or downloading the
 * template in a different language, cannot change how a file imports.
 *
 * Widths and the sample row are generated from the column list rather than
 * hard-coded A–J, which is what broke when a program configured more than
 * ten columns (audit finding L1).
 */
class HierarchyRequirementsSheet implements FromArray, WithColumnWidths, WithHeadings, WithStyles, WithTitle
{
    public const SHEET_NAME = 'Requirements';

    public function __construct(
        private readonly array $columns,
        private readonly array $levels,
        private readonly string $language = 'ar',
    ) {}

    public function title(): string
    {
        return self::SHEET_NAME;
    }

    /** Localized, human-readable headings — never the import identity. */
    public function headings(): array
    {
        $labelKey = $this->language === 'en' ? 'label_en' : 'label_ar';

        return array_map(fn (array $column) => $column[$labelKey], $this->columns);
    }

    /**
     * One illustrative row showing a complete parent chain, so a user can
     * see that each row repeats its ancestors' codes down to the leaf.
     */
    public function array(): array
    {
        $row = [];
        foreach ($this->columns as $column) {
            $row[] = match ($column['role']) {
                'code' => $this->sampleCode($column['level_key']),
                'name_ar' => 'مثال',
                'name_en' => 'Example',
                default => match ($column['key']) {
                    'weight' => 10,
                    'default_due_date' => now()->addMonths(3)->toDateString(),
                    default => '',
                },
            };
        }

        return [$row];
    }

    private function sampleCode(?string $levelKey): string
    {
        $index = array_search($levelKey, array_map(fn ($l) => $l->key, $this->levels), true);
        $depth = $index === false ? 1 : $index + 1;

        return implode('.', array_fill(0, $depth, '1'));
    }

    public function columnWidths(): array
    {
        $widths = [];
        foreach ($this->columns as $i => $column) {
            $widths[$this->columnLetter($i)] = $column['role'] === 'code' ? 18 : 30;
        }

        return $widths;
    }

    public function styles(Worksheet $sheet): array
    {
        return [1 => [
            'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
            'fill' => ['fillType' => 'solid', 'startColor' => ['rgb' => '00281E']],
        ]];
    }

    /** 0-based index to spreadsheet column letter, beyond Z (AA, AB, …). */
    private function columnLetter(int $index): string
    {
        $letter = '';
        $index++;
        while ($index > 0) {
            $remainder = ($index - 1) % 26;
            $letter = chr(65 + $remainder).$letter;
            $index = intdiv($index - 1, 26);
        }

        return $letter;
    }
}
