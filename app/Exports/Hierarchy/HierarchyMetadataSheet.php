<?php

namespace App\Exports\Hierarchy;

use App\Models\AssessmentCycle;
use App\Models\ComplianceProgram;
use App\Models\ProgramStructureVersion;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithTitle;

/**
 * Machine-readable identification the importer validates BEFORE trusting
 * any visible sheet. Column headers alone can be forged or reordered; this
 * sheet ties the workbook to one program AND one structure version.
 *
 * `structure_version` is the field that makes requirement 7 enforceable:
 * a template generated before a level was added, removed or reordered is
 * rejected rather than silently imported into a structure it does not
 * describe.
 */
class HierarchyMetadataSheet implements FromArray, WithTitle
{
    public const SHEET_NAME = '_metadata';

    public function __construct(
        private readonly ComplianceProgram $program,
        private readonly ?ProgramStructureVersion $structureVersion,
        private readonly ?AssessmentCycle $cycle,
        private readonly array $columnIdentifiers,
        private readonly array $levelKeys = [],
        private readonly string $language = 'ar',
    ) {}

    public function title(): string
    {
        return self::SHEET_NAME;
    }

    public function array(): array
    {
        return [
            ['key', 'value'],
            ['template_version', HierarchyTemplateExport::TEMPLATE_VERSION],
            ['schema_version', HierarchyTemplateExport::SCHEMA_VERSION],
            ['program_code', $this->program->code],
            ['structure_version', (string) ($this->structureVersion?->version ?? '')],
            ['hierarchy_definition_version', (string) ($this->structureVersion?->hierarchy_definition_id ?? '')],
            ['level_count', (string) count($this->structureVersion?->levels() ?? [])],
            ['cycle_id', (string) ($this->cycle?->id ?? '')],
            ['generated_at', now()->toIso8601String()],
            ['generated_language', $this->language],
            // Level keys IN ORDER, and the machine column identifiers in
            // exact column order. Together these are the import contract:
            // the visible headings are decoration, these are identity.
            ['level_keys', implode(',', $this->levelKeys)],
            ['column_identifiers', implode(',', $this->columnIdentifiers)],
        ];
    }
}
