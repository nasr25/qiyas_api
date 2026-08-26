<?php

namespace App\Policies;

use App\Models\RequirementAssignment;
use App\Models\User;

/**
 * Program isolation is already enforced upstream by EnsureProgramAccess
 * (Phase 1) before any of these methods run — this policy adds the
 * role/department checks on top of that.
 */
class RequirementAssignmentPolicy
{
    public function manage(User $user, RequirementAssignment $assignment): bool
    {
        return $user->isPlatformSuperAdmin()
            || $user->hasProgramRole($assignment->program, 'program-manager');
    }

    public function view(User $user, RequirementAssignment $assignment): bool
    {
        if ($this->manage($user, $assignment)) {
            return true;
        }
        if ($user->isPlatformExecutiveViewer()) {
            return true;
        }
        if ($user->hasProgramRole($assignment->program, 'auditor')) {
            return true;
        }

        $departmentId = $user->managedDepartmentId($assignment->program) ?? $user->employeeDepartmentId($assignment->program);

        return $departmentId === $assignment->department_id;
    }
}
