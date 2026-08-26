<?php

namespace App\Exports\Hierarchy;

use App\Models\ComplianceNode;
use App\Models\ComplianceProgram;
use App\Services\HierarchyPathResolver;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithColumnWidths;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithTitle;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Exports a program's existing hierarchy using the SAME column contract the
 * template declares (requirement 11), so an export can be edited and
 * re-imported without transformation — a round trip.
 *
 * One row per deepest-level node, repeating its ancestors' codes and names,
 * exactly as the import format expects.
 */
class HierarchyNodesExport implements FromArray, WithColumnWidths, WithHeadings, WithStyles, WithTitle
{
    private array $columns;

    public function __construct(
        private readonly ComplianceProgram $program,
        array $levels,
        private readonly ?int $cycleId = null,
    ) {
        $this->columns = HierarchyTemplateExport::columnsFor(array_values($levels));
    }

    public function title(): string
    {
        return HierarchyRequirementsSheet::SHEET_NAME;
    }

    public function headings(): array
    {
        return array_column($this->columns, 'key');
    }

    public function array(): array
    {
        // One query for the whole program's tree; ancestor chains are then
        // resolved in memory. Walking `->parent` per cell previously cost
        // thousands of queries on a realistic dataset.
        $paths = HierarchyPathResolver::forProgram($this->program, $this->cycleId);

        $nodes = ComplianceNode::where('compliance_program_id', $this->program->id)
            ->when($this->cycleId, fn ($q) => $q->where('program_cycle_id', $this->cycleId))
            ->orderBy('level')->orderBy('code')
            ->get();

        // Leaves are nodes with no children: one exported row per leaf.
        $parentIds = $nodes->pluck('parent_id')->filter()->unique()->flip();
        $leaves = $nodes->reject(fn (ComplianceNode $n) => $parentIds->has($n->id));

        return $leaves->map(function (ComplianceNode $leaf) use ($paths) {
            $byLevelKey = [];
            foreach ($paths->chain($leaf->id) as $node) {
                $byLevelKey[$node->hierarchyLevel?->key ?? $node->node_type] = $node;
            }

            $row = [];
            foreach ($this->columns as $column) {
                $node = $byLevelKey[$column['level_key']] ?? null;
                $row[] = match ($column['role']) {
                    'code' => $node?->code ?? '',
                    'name_ar' => $node?->name_ar ?? '',
                    'name_en' => $node?->name_en ?? '',
                    default => $this->attribute($leaf, $column['key']),
                };
            }

            return $row;
        })->values()->all();
    }

    private function attribute(ComplianceNode $leaf, string $key): string
    {
        return match ($key) {
            'description_ar' => (string) $leaf->description_ar,
            'objective_ar' => (string) $leaf->objective_ar,
            'guidance_ar' => (string) $leaf->guidance_ar,
            'weight' => $leaf->weight === null ? '' : (string) $leaf->weight,
            'default_due_date' => $leaf->default_due_date?->toDateString() ?? '',
            default => '',
        };
    }

    public function columnWidths(): array
    {
        $widths = [];
        foreach ($this->columns as $i => $column) {
            $letter = '';
            $n = $i + 1;
            while ($n > 0) {
                $letter = chr(65 + ($n - 1) % 26).$letter;
                $n = intdiv($n - 1, 26);
            }
            $widths[$letter] = $column['role'] === 'code' ? 18 : 30;
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
}
