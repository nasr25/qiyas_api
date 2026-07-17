<?php

namespace App\Exports\Qiyas;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithTitle;

/**
 * Hidden sheet carrying machine-readable identification the importer
 * validates before trusting the visible sheets — column headers alone are
 * never sufficient to identify a valid official template. See
 * docs/qiyas-xlsx-import.md.
 */
class MetadataSheet implements FromArray, WithTitle
{
    // Deliberately not a bare numeric string like "1.0" — Excel/PhpSpreadsheet
    // auto-converts numeric-looking cell text to a number on read, which
    // would silently drop the trailing zero and break the exact-match
    // version check in QiyasImportValidator.
    public const TEMPLATE_VERSION = 'QIYAS-TEMPLATE-1.0';

    public function __construct(
        private readonly string $programCode,
        private readonly ?int $cycleId,
    ) {}

    public function title(): string
    {
        return '_metadata';
    }

    public function array(): array
    {
        return [
            ['key', 'value'],
            ['template_version', self::TEMPLATE_VERSION],
            ['program_code', $this->programCode],
            ['schema_version', RequirementsSheet::class],
            ['export_date', now()->toIso8601String()],
            ['cycle_id', (string) ($this->cycleId ?? '')],
            ['column_identifiers', implode(',', RequirementsSheet::COLUMNS)],
        ];
    }
}
