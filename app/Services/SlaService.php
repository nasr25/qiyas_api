<?php

namespace App\Services;

use App\Models\ComplianceProgram;
use App\Models\Department;
use App\Models\EvidenceSubmission;
use App\Models\RequirementAssignment;
use App\Models\SlaInstance;
use App\Models\SlaSetting;
use App\Models\User;
use Illuminate\Support\Carbon;

/**
 * Opens/closes SlaInstance rows and calculates business-day-aware due dates.
 * See docs/sla-design.md.
 *
 * Every SlaInstance stores a snapshot of the settings used to calculate it
 * (`settings_snapshot`), so a later change to SlaSetting never alters a
 * historical measurement.
 */
class SlaService
{
    public function settingsFor(ComplianceProgram $program): SlaSetting
    {
        return SlaSetting::firstOrCreate(
            ['compliance_program_id' => $program->id],
            [
                'working_days' => SlaSetting::defaultWorkingDays(),
            ]
        );
    }

    /**
     * Opens a new SlaInstance for a stage. Any other still-active instance
     * for the same assignment+stage is left alone by design — callers are
     * expected to close the previous stage's instance before opening the
     * next (see WorkflowService), so in practice only one instance per
     * assignment is ever active at a time.
     */
    public function openInstance(
        RequirementAssignment $assignment,
        ?EvidenceSubmission $submission,
        string $stage,
        ?User $responsibleUser,
        ?Department $responsibleDepartment,
    ): SlaInstance {
        $settings = $this->settingsFor($assignment->program);
        $startedAt = now();
        $dueAt = $settings->is_enabled ? $this->calculateDueAt($settings, $stage, $startedAt) : null;

        return SlaInstance::create([
            'compliance_program_id' => $assignment->compliance_program_id,
            'program_cycle_id' => $assignment->program_cycle_id,
            'requirement_assignment_id' => $assignment->id,
            'evidence_submission_id' => $submission?->id,
            'stage' => $stage,
            'responsible_user_id' => $responsibleUser?->id,
            'responsible_department_id' => $responsibleDepartment?->id,
            'started_at' => $startedAt,
            'due_at' => $dueAt,
            'status' => 'active',
            'settings_snapshot' => $settings->only([
                'employee_submission_sla_value', 'employee_submission_sla_unit',
                'department_manager_review_sla_value', 'department_manager_review_sla_unit',
                'auditor_review_sla_value', 'auditor_review_sla_unit',
                'program_manager_review_sla_value', 'program_manager_review_sla_unit',
                'use_business_days', 'working_days', 'working_day_start', 'working_day_end',
                'timezone', 'warning_threshold_percentage', 'is_enabled',
            ]),
        ]);
    }

    /**
     * Closes the currently active SlaInstance(s) for an assignment+stage
     * (there is normally exactly one). `cancelled` is used when the stage
     * is bypassed without the responsible party acting (e.g. reassignment).
     */
    public function closeActiveInstance(RequirementAssignment $assignment, string $stage, string $outcome = 'completed'): void
    {
        $instance = SlaInstance::where('requirement_assignment_id', $assignment->id)
            ->where('stage', $stage)
            ->where('status', 'active')
            ->latest('started_at')
            ->first();

        if (! $instance) {
            return;
        }

        $completedAt = now();
        $elapsedMinutes = $instance->started_at->diffInMinutes($completedAt);

        if ($outcome === 'cancelled') {
            $status = 'cancelled';
        } else {
            $status = ($instance->due_at && $completedAt->greaterThan($instance->due_at))
                ? 'completed_after_sla'
                : 'completed_within_sla';
        }

        $instance->update([
            'completed_at' => $completedAt,
            'status' => $status,
            'elapsed_minutes' => $elapsedMinutes,
            'business_elapsed_minutes' => $instance->settings_snapshot['use_business_days'] ?? true
                ? $this->businessMinutesBetween($instance->started_at, $completedAt, $instance->settings_snapshot)
                : $elapsedMinutes,
        ]);
    }

    /** Marks all still-active instances for an assignment as breached — used by the scheduled command. */
    public function markBreached(SlaInstance $instance): void
    {
        $instance->update(['status' => 'breached', 'breached_at' => now()]);
    }

    /**
     * Business-day/hour-aware due date calculation. When `use_business_days`
     * is false, this is a plain calendar addition.
     */
    public function calculateDueAt(SlaSetting $settings, string $stage, Carbon $from): Carbon
    {
        ['value' => $value, 'unit' => $unit] = $settings->forStage($stage);

        $from = $from->copy()->setTimezone($settings->timezone);

        if (! $settings->use_business_days) {
            return $unit === 'hours' ? $from->copy()->addHours($value) : $from->copy()->addDays($value);
        }

        $workingDays = $settings->working_days ?: SlaSetting::defaultWorkingDays();
        $totalMinutes = $unit === 'hours' ? $value * 60 : $value * $this->workingMinutesPerDay($settings);

        return $this->addBusinessMinutes($from, $totalMinutes, $workingDays, $settings);
    }

    private function workingMinutesPerDay(SlaSetting $settings): int
    {
        $start = Carbon::createFromTimeString($settings->working_day_start);
        $end = Carbon::createFromTimeString($settings->working_day_end);

        return max(1, $end->diffInMinutes($start));
    }

    private function addBusinessMinutes(Carbon $from, int $minutes, array $workingDays, SlaSetting $settings): Carbon
    {
        $cursor = $from->copy();
        $dayStart = $settings->working_day_start;
        $dayEnd = $settings->working_day_end;

        // Snap into the working window if starting outside it.
        $cursor = $this->snapIntoWorkingWindow($cursor, $workingDays, $dayStart, $dayEnd);

        $remaining = $minutes;
        while ($remaining > 0) {
            $windowEnd = $cursor->copy()->setTimeFromTimeString($dayEnd);
            $minutesLeftToday = max(0, $cursor->diffInMinutes($windowEnd, false));

            if ($remaining <= $minutesLeftToday) {
                return $cursor->addMinutes($remaining);
            }

            $remaining -= $minutesLeftToday;
            $cursor = $cursor->addDay()->setTimeFromTimeString($dayStart);
            $cursor = $this->snapIntoWorkingWindow($cursor, $workingDays, $dayStart, $dayEnd);
        }

        return $cursor;
    }

    private function snapIntoWorkingWindow(Carbon $cursor, array $workingDays, string $dayStart, string $dayEnd): Carbon
    {
        while (! in_array($cursor->dayOfWeek, $workingDays, true)) {
            $cursor = $cursor->addDay()->setTimeFromTimeString($dayStart);
        }

        $start = $cursor->copy()->setTimeFromTimeString($dayStart);
        $end = $cursor->copy()->setTimeFromTimeString($dayEnd);

        if ($cursor->lessThan($start)) {
            return $start;
        }
        if ($cursor->greaterThanOrEqualTo($end)) {
            $cursor = $cursor->addDay()->setTimeFromTimeString($dayStart);

            return $this->snapIntoWorkingWindow($cursor, $workingDays, $dayStart, $dayEnd);
        }

        return $cursor;
    }

    /** Approximate business-minutes elapsed, honoring the snapshot's working calendar. */
    private function businessMinutesBetween(Carbon $start, Carbon $end, array $snapshot): int
    {
        $workingDays = $snapshot['working_days'] ?? SlaSetting::defaultWorkingDays();
        $dayStart = $snapshot['working_day_start'] ?? '08:00:00';
        $dayEnd = $snapshot['working_day_end'] ?? '16:00:00';

        $minutes = 0;
        $cursor = $start->copy();

        while ($cursor->lessThan($end)) {
            if (in_array($cursor->dayOfWeek, $workingDays, true)) {
                $windowStart = $cursor->copy()->setTimeFromTimeString($dayStart);
                $windowEnd = $cursor->copy()->setTimeFromTimeString($dayEnd);
                $segmentStart = $cursor->greaterThan($windowStart) ? $cursor : $windowStart;
                $segmentEnd = $end->lessThan($windowEnd) ? $end : $windowEnd;
                if ($segmentEnd->greaterThan($segmentStart)) {
                    $minutes += $segmentStart->diffInMinutes($segmentEnd);
                }
            }
            $cursor = $cursor->addDay()->startOfDay();
        }

        return $minutes;
    }
}
