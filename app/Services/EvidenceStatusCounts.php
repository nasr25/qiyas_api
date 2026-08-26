<?php

namespace App\Services;

use App\Models\EvidenceSubmission;
use App\Models\RequirementAssignment;

/**
 * Status counts in the shape dashboards and reports already consume.
 *
 * The legacy `documents` table had five flat statuses
 * (draft/under_review/approved/rejected/overdue). EvidenceSubmission has a
 * per-stage vocabulary instead, and "overdue" is a property of the
 * ASSIGNMENT's due date rather than of a submission at all.
 *
 * This maps one to the other in a single place. Without it, five separate
 * consumers kept querying `documents` after the legacy path was retired and
 * silently reported zeros — technically running, quietly wrong.
 */
class EvidenceStatusCounts
{
    /**
     * @param  array{program_id?:int,cycle_id?:int,department_id?:int}  $scope
     * @return array{total:int,draft:int,under_review:int,approved:int,rejected:int,overdue:int}
     */
    public function for(array $scope = []): array
    {
        $submissions = EvidenceSubmission::query()
            ->when($scope['program_id'] ?? null, fn ($q, $id) => $q->where('compliance_program_id', $id))
            ->when($scope['cycle_id'] ?? null, fn ($q, $id) => $q->where('program_cycle_id', $id))
            ->when($scope['department_id'] ?? null, fn ($q, $id) => $q->where('department_id', $id))
            ->selectRaw('status, count(*) as count')
            ->groupBy('status')
            ->pluck('count', 'status')
            ->toArray();

        $overdue = RequirementAssignment::query()
            ->when($scope['program_id'] ?? null, fn ($q, $id) => $q->where('compliance_program_id', $id))
            ->when($scope['cycle_id'] ?? null, fn ($q, $id) => $q->where('program_cycle_id', $id))
            ->when($scope['department_id'] ?? null, fn ($q, $id) => $q->where('department_id', $id))
            ->where('status', 'active')
            ->whereDate('effective_due_date', '<', now())
            ->count();

        return [
            'total' => array_sum($submissions),
            'draft' => (int) ($submissions['draft'] ?? 0),
            // All three review stages read as "under review" to a consumer
            // that only knows the legacy vocabulary.
            'under_review' => (int) ($submissions['pending_department_manager'] ?? 0)
                + (int) ($submissions['pending_auditor'] ?? 0)
                + (int) ($submissions['pending_program_manager'] ?? 0),
            'approved' => (int) ($submissions['approved'] ?? 0),
            'rejected' => (int) ($submissions['returned_for_revision'] ?? 0),
            'overdue' => $overdue,
        ];
    }

    /** Count for one legacy status name, for consumers that ask one at a time. */
    public function count(string $legacyStatus, array $scope = []): int
    {
        return $this->for($scope)[$legacyStatus] ?? 0;
    }
}
