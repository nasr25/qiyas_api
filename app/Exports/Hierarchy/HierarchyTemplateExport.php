<?php

namespace App\Exports\Hierarchy;

use App\Models\AssessmentCycle;
use App\Models\ComplianceProgram;
use App\Models\HierarchyLevelDefinition;
use App\Models\ProgramStructureVersion;
use Maatwebsite\Excel\Concerns\Exportable;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;

/**
 * Generates an import template from a program's ACTIVE structure — the
 * generic replacement for the hand-written, fixed ten-column Qiyas
 * template (audit findings C3 and L1).
 *
 * Column count follows structure depth: a three-level Sumoud template has
 * three hierarchy column groups, a six-level NDMO template has six. No
 * per-program exporter exists, and adding a seventh level to a program
 * changes its template with no code change at all.
 *
 * Four sheets:
 *   Requirements   — the data sheet, localized headings
 *   Instructions   — bilingual explanation generated from the structure
 *   Allowed Values — controlled values and this program's level semantics
 *   _metadata      — machine identity the importer validates first
 */
class HierarchyTemplateExport implements WithMultipleSheets
{
    use Exportable;

    /**
     * Bumped only when the workbook's STRUCTURE changes (sheet names, header
     * row position, metadata keys) — not when a program edits its levels,
     * which is tracked separately by structure_version.
     */
    public const TEMPLATE_VERSION = 'HIERARCHY-TEMPLATE-1.0';

    public const SCHEMA_VERSION = '1';

    /** @var array<int, HierarchyLevelDefinition> */
    private array $levels;

    public function __construct(
        private readonly ComplianceProgram $program,
        array $levels,
        private readonly ?ProgramStructureVersion $structureVersion,
        private readonly ?AssessmentCycle $cycle = null,
        private readonly string $language = 'ar',
    ) {
        $this->levels = array_values($levels);
    }

    public function sheets(): array
    {
        $columns = self::columnsFor($this->levels);

        return [
            new HierarchyRequirementsSheet($columns, $this->levels, $this->language),
            new HierarchyInstructionsSheet($columns, $this->program, $this->levels),
            new HierarchyAllowedValuesSheet($this->program, $this->levels),
            new HierarchyMetadataSheet(
                $this->program,
                $this->structureVersion,
                $this->cycle,
                array_column($columns, 'key'),
                array_map(fn ($level) => $level->key, $this->levels),
                $this->language,
            ),
        ];
    }

    /**
     * The column contract, derived from the structure.
     *
     * Keys are STABLE MACHINE IDENTIFIERS built from the level key
     * (`domain_code`, `domain_name_ar`, …), never from a translated label —
     * so renaming a level's Arabic display name never breaks a mapping,
     * and an Arabic-locale export imports identically to an English one.
     *
     * @param  array<int, HierarchyLevelDefinition>  $levels
     * @return array<int, array{key:string,label_ar:string,label_en:string,required:bool,level_key:?string,role:string}>
     */
    public static function columnsFor(array $levels): array
    {
        $columns = [];

        foreach ($levels as $level) {
            $columns[] = [
                'key' => "{$level->key}_code",
                'label_ar' => "رمز {$level->name_ar}",
                'label_en' => "{$level->name_en} Code",
                // Only levels the structure marks required must be present in
                // every row; an optional deep level may be left blank.
                'required' => (bool) $level->is_required,
                'level_key' => $level->key,
                'role' => 'code',
            ];
            $columns[] = [
                'key' => "{$level->key}_name_ar",
                'label_ar' => "{$level->name_ar} (عربي)",
                'label_en' => "{$level->name_en} (Arabic)",
                'required' => (bool) $level->is_required,
                'level_key' => $level->key,
                'role' => 'name_ar',
            ];
            $columns[] = [
                'key' => "{$level->key}_name_en",
                'label_ar' => "{$level->name_ar} (إنجليزي)",
                'label_en' => "{$level->name_en} (English)",
                'required' => false,
                'level_key' => $level->key,
                'role' => 'name_en',
            ];
        }

        // Per-level optional attributes, emitted only where the level
        // enables the corresponding form field.
        $deepest = end($levels) ?: null;
        if ($deepest) {
            foreach ([
                'description_enabled' => ['description_ar', 'الوصف', 'Description'],
                'objective_enabled' => ['objective_ar', 'الهدف', 'Objective'],
                'instructions_enabled' => ['guidance_ar', 'الإرشادات', 'Guidance'],
                'weight_enabled' => ['weight', 'الوزن', 'Weight'],
                'due_date_enabled' => ['default_due_date', 'تاريخ الاستحقاق', 'Due Date'],
            ] as $flag => [$key, $ar, $en]) {
                if ($deepest->{$flag}) {
                    $columns[] = [
                        'key' => $key,
                        'label_ar' => $ar,
                        'label_en' => $en,
                        'required' => false,
                        'level_key' => $deepest->key,
                        'role' => 'attribute',
                    ];
                }
            }
        }

        return $columns;
    }
}
