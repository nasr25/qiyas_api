<?php

namespace App\Services;

use App\Exceptions\WorkflowConflictException;
use App\Models\ComplianceProgram;
use App\Models\ComplianceResponsibility;
use App\Models\Department;
use App\Models\RequirementAssignment;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * The single write path for responsibility labels (Data Owner, Data
 * Steward, ...). Deliberately thin and deliberately NOT an authorization
 * service — nothing here checks or grants Gate permissions; see
 * ComplianceResponsibility's class doc. A responsibility type must be in
 * the acting program's configured `enabled_types` (empty by default —
 * the feature is off unless a program explicitly turns specific types
 * on), so an unapproved label can never be assigned even informationally.
 */
class ResponsibilityService
{
    public function __construct(private readonly ProgramConfigurationService $config) {}

    /** @return array<int, array{type:string,label_ar:string,label_en:string}> */
    public function enabledTypes(ComplianceProgram $program): array
    {
        $config = $this->config->get($program, 'responsibilities', []);
        $enabled = $config['enabled_types'] ?? [];
        $types = collect($config['types'] ?? [])->keyBy('type');

        return collect($enabled)->map(fn ($type) => $types->get($type))->filter()->values()->all();
    }

    public function assign(RequirementAssignment $assignment, string $responsibilityType, User $actor, ?User $user = null, ?Department $department = null, ?string $reason = null): ComplianceResponsibility
    {
        $program = $assignment->program;
        $enabledTypes = collect($this->enabledTypes($program))->pluck('type');

        if (! $enabledTypes->contains($responsibilityType)) {
            throw new WorkflowConflictException("Responsibility type '{$responsibilityType}' is not enabled for program '{$program->code}'.");
        }

        return DB::transaction(function () use ($assignment, $responsibilityType, $actor, $user, $department, $reason) {
            $responsibility = ComplianceResponsibility::create([
                'compliance_program_id' => $assignment->compliance_program_id,
                'program_cycle_id' => $assignment->program_cycle_id,
                'requirement_assignment_id' => $assignment->id,
                'department_id' => $department?->id,
                'user_id' => $user?->id,
                'responsibility_type' => $responsibilityType,
                'start_date' => now()->toDateString(),
                'is_active' => true,
                'reason' => $reason,
                'created_by' => $actor->id,
            ]);

            AuditService::log(
                'responsibility.assigned',
                "Responsibility '{$responsibilityType}' assigned on assignment #{$assignment->id}",
                $responsibility,
                complianceProgramId: $assignment->compliance_program_id,
            );

            return $responsibility;
        });
    }

    /** Never deletes — sets is_active=false and end_date, preserving full history for audit. */
    public function revoke(ComplianceResponsibility $responsibility, User $actor, ?string $reason = null): ComplianceResponsibility
    {
        return DB::transaction(function () use ($responsibility, $reason) {
            $responsibility->update([
                'is_active' => false,
                'end_date' => now()->toDateString(),
                'reason' => $reason ?? $responsibility->reason,
            ]);

            AuditService::log(
                'responsibility.revoked',
                "Responsibility '{$responsibility->responsibility_type}' revoked on assignment #{$responsibility->requirement_assignment_id}",
                $responsibility,
                complianceProgramId: $responsibility->compliance_program_id,
            );

            return $responsibility->fresh();
        });
    }
}
