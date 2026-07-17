<?php

namespace App\Services;

use App\Models\ComplianceProgram;
use App\Models\EvidenceSubmission;
use App\Models\RequirementAssignment;
use Illuminate\Support\Collection;

/**
 * Reusable, program-scoped count-builders shared across dashboard
 * endpoints — see docs/dashboard-reporting-engine.md. Extracted from
 * WorkflowDashboardController so a future program's dashboard reuses these
 * exact query builders instead of a copied count query. Full consolidation
 * of every dashboard/report controller onto this service was scoped out of
 * Phase 4 (see docs/compliance-engine-known-issues.md, finding #6) — this
 * covers the workflow-status and department-comparison metrics that were
 * duplicated in spirit across controllers, not a rewrite of every endpoint.
 */
class DashboardMetricsService
{
    /** Every known EvidenceSubmission status, each defaulted to 0 so the caller never has to guard a missing key. */
    public function submissionStatusCounts(ComplianceProgram $program): array
    {
        $counts = EvidenceSubmission::where('compliance_program_id', $program->id)
            ->selectRaw('status, count(*) as count')->groupBy('status')->pluck('count', 'status');

        return collect(EvidenceSubmission::STATUSES)->mapWithKeys(fn ($s) => [$s => (int) ($counts[$s] ?? 0)])->all();
    }

    public function overdueAssignmentCount(ComplianceProgram $program): int
    {
        return RequirementAssignment::forProgram($program)->active()
            ->whereDate('effective_due_date', '<', now()->toDateString())->count();
    }

    public function upcomingDeadlineCount(ComplianceProgram $program, int $withinDays = 14): int
    {
        return RequirementAssignment::forProgram($program)->active()
            ->whereBetween('effective_due_date', [now()->toDateString(), now()->addDays($withinDays)->toDateString()])
            ->count();
    }

    public function assignedRequirementIds(ComplianceProgram $program): Collection
    {
        return RequirementAssignment::forProgram($program)->active()->pluck('requirement_id');
    }
}
