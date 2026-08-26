<?php

namespace App\Services;

use App\Models\ComplianceProgram;
use App\Models\ProgramUserRole;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Maps existing global spatie/laravel-permission roles onto the new
 * program-scoped authorization layer (program_user_roles), for the QIYAS
 * program only. See docs/qiyas-migration-plan.md for the full mapping
 * matrix and rationale.
 *
 * Idempotent and safe to re-run: uses firstOrCreate keyed on the natural
 * uniqueness of (user, program, role_key, department). Existing spatie
 * roles are never modified or removed by this process.
 */
class ProgramMigrationService
{
    /** spatie role name => program-level role_key (no department scoping) */
    private const PROGRAM_LEVEL_MAP = [
        'qiyas-admin' => ProgramUserRole::ROLE_PROGRAM_MANAGER,
        'auditor' => ProgramUserRole::ROLE_AUDITOR,
    ];

    /** spatie role name => department-scoped role_key */
    private const DEPARTMENT_LEVEL_MAP = [
        'coordinator' => ProgramUserRole::ROLE_DEPARTMENT_MANAGER,
        'employee' => ProgramUserRole::ROLE_EMPLOYEE,
    ];

    /**
     * Runs the migration for a given program (QIYAS in Phase 1).
     * Returns counts for reporting.
     */
    public function migrateUsersToProgram(ComplianceProgram $program, ?User $assignedBy = null): array
    {
        $created = 0;
        $skipped = 0;

        DB::transaction(function () use ($program, $assignedBy, &$created, &$skipped) {
            User::with('roles')->chunkById(100, function ($users) use ($program, $assignedBy, &$created, &$skipped) {
                foreach ($users as $user) {
                    foreach ($user->roles as $role) {
                        $roleKey = self::PROGRAM_LEVEL_MAP[$role->name] ?? null;
                        $departmentId = null;

                        if (! $roleKey) {
                            $roleKey = self::DEPARTMENT_LEVEL_MAP[$role->name] ?? null;
                            $departmentId = $user->department_id;
                        }

                        // Platform-level roles (super-admin, executive) are not
                        // represented in program_user_roles — they get implicit
                        // access via User::hasProgramAccess().
                        if (! $roleKey) {
                            continue;
                        }

                        [$row, $wasCreated] = $this->firstOrCreateRow($user, $program, $roleKey, $departmentId, $assignedBy);
                        $wasCreated ? $created++ : $skipped++;
                    }
                }
            });
        });

        return ['created' => $created, 'already_existed' => $skipped];
    }

    private function firstOrCreateRow(User $user, ComplianceProgram $program, string $roleKey, ?int $departmentId, ?User $assignedBy): array
    {
        $existing = ProgramUserRole::where('user_id', $user->id)
            ->where('compliance_program_id', $program->id)
            ->where('role_key', $roleKey)
            ->where('department_id', $departmentId)
            ->first();

        if ($existing) {
            return [$existing, false];
        }

        $row = ProgramUserRole::create([
            'user_id' => $user->id,
            'compliance_program_id' => $program->id,
            'role_key' => $roleKey,
            'department_id' => $departmentId,
            'is_active' => true,
            'assigned_by' => $assignedBy?->id,
            'assigned_at' => now(),
        ]);

        return [$row, true];
    }
}
