<?php

namespace App\Http\Controllers\Api\Reports;

use App\Exports\DepartmentProgressExport;
use App\Http\Controllers\Controller;
use App\Models\AssessmentCycle;
use App\Models\Department;
use App\Models\Document;
use App\Services\AuditService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Maatwebsite\Excel\Facades\Excel;

/**
 * Report export controller — generates Excel and PDF files.
 */
class ExportController extends Controller
{
    /**
     * Exports department progress as Excel.
     * GET /api/v1/reports/export/department-excel
     */
    public function departmentExcel(Request $request)
    {
        $request->validate(['cycle_id' => ['required', 'exists:assessment_cycles,id']]);
        $cycle = AssessmentCycle::findOrFail($request->cycle_id);

        AuditService::log('report.export', "Department progress Excel export for cycle #{$cycle->id}");

        return Excel::download(
            new DepartmentProgressExport($cycle),
            "department-progress-{$cycle->year}.xlsx"
        );
    }

    /**
     * Exports department progress as PDF.
     * GET /api/v1/reports/export/department-pdf
     */
    public function departmentPdf(Request $request)
    {
        $request->validate(['cycle_id' => ['required', 'exists:assessment_cycles,id']]);
        $cycle = AssessmentCycle::findOrFail($request->cycle_id);

        $departments = Department::withCount([
            'documents as total' => fn ($q) => $q->where('cycle_id', $cycle->id),
            'documents as approved' => fn ($q) => $q->where('cycle_id', $cycle->id)->where('status', 'approved'),
            'documents as under_review' => fn ($q) => $q->where('cycle_id', $cycle->id)->where('status', 'under_review'),
            'documents as rejected' => fn ($q) => $q->where('cycle_id', $cycle->id)->where('status', 'rejected'),
        ])->get();

        AuditService::log('report.export', "Department progress PDF export for cycle #{$cycle->id}");

        $pdf = Pdf::loadView('reports.department-progress', compact('cycle', 'departments'));
        $pdf->setPaper('A4', 'landscape');

        return $pdf->download("department-progress-{$cycle->year}.pdf");
    }

    /**
     * Exports cycle summary as PDF.
     * GET /api/v1/reports/export/cycle-summary-pdf
     */
    public function cycleSummaryPdf(Request $request)
    {
        $request->validate(['cycle_id' => ['required', 'exists:assessment_cycles,id']]);
        $cycle = AssessmentCycle::with('standards')->findOrFail($request->cycle_id);

        $docStats = Document::where('cycle_id', $cycle->id)
            ->selectRaw('status, count(*) as count')
            ->groupBy('status')
            ->pluck('count', 'status')
            ->toArray();

        AuditService::log('report.export', "Cycle summary PDF export for cycle #{$cycle->id}");

        $pdf = Pdf::loadView('reports.cycle-summary', compact('cycle', 'docStats'));

        return $pdf->download("cycle-summary-{$cycle->year}.pdf");
    }
}
