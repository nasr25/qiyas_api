<?php

namespace App\Exports;

use App\Models\AssessmentCycle;
use App\Models\Department;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithTitle;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Exports department progress report to Excel.
 */
class DepartmentProgressExport implements FromCollection, WithHeadings, WithStyles, WithTitle
{
    public function __construct(private readonly AssessmentCycle $cycle) {}

    public function title(): string
    {
        return "Department Progress - {$this->cycle->name}";
    }

    public function headings(): array
    {
        return [
            '#',
            'Department (Arabic)',
            'Department (English)',
            'Total',
            'Approved',
            'Under Review',
            'Rejected',
            'Draft',
            'Overdue',
            'Completion %',
        ];
    }

    public function collection()
    {
        return Department::withCount([
            'documents as total' => fn ($q) => $q->where('cycle_id', $this->cycle->id),
            'documents as approved' => fn ($q) => $q->where('cycle_id', $this->cycle->id)->where('status', 'approved'),
            'documents as under_review' => fn ($q) => $q->where('cycle_id', $this->cycle->id)->where('status', 'under_review'),
            'documents as rejected' => fn ($q) => $q->where('cycle_id', $this->cycle->id)->where('status', 'rejected'),
            'documents as draft' => fn ($q) => $q->where('cycle_id', $this->cycle->id)->where('status', 'draft'),
            'documents as overdue' => fn ($q) => $q->where('cycle_id', $this->cycle->id)->where('status', 'overdue'),
        ])->get()->map(fn ($dept, $i) => [
            $i + 1,
            $dept->name_ar,
            $dept->name_en,
            $dept->total,
            $dept->approved,
            $dept->under_review,
            $dept->rejected,
            $dept->draft,
            $dept->overdue,
            $dept->total > 0 ? round(($dept->approved / $dept->total) * 100, 1).'%' : '0%',
        ]);
    }

    public function styles(Worksheet $sheet): array
    {
        return [
            1 => ['font' => ['bold' => true], 'fill' => ['fillType' => 'solid', 'startColor' => ['rgb' => '1e3a5f']], 'font' => ['color' => ['rgb' => 'FFFFFF'], 'bold' => true]],
        ];
    }
}
