<?php

namespace App\Exports\Qiyas;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithColumnWidths;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithTitle;

/** Existing perspective/axis values already used in the program, shown for consistency (not enforced as a strict allowlist). */
class AllowedValuesSheet implements FromArray, WithColumnWidths, WithHeadings, WithTitle
{
    public function __construct(private readonly array $perspectives, private readonly array $axes) {}

    public function title(): string
    {
        return 'Allowed Values';
    }

    public function headings(): array
    {
        return ['existing_perspectives', 'existing_axes'];
    }

    public function array(): array
    {
        $rows = max(count($this->perspectives), count($this->axes), 1);
        $out = [];
        for ($i = 0; $i < $rows; $i++) {
            $out[] = [$this->perspectives[$i] ?? '', $this->axes[$i] ?? ''];
        }

        return $out;
    }

    public function columnWidths(): array
    {
        return ['A' => 30, 'B' => 30];
    }
}
