<?php

namespace App\Policies;

use App\Models\ComplianceProgram;
use App\Models\User;

/**
 * Authorization for the ComplianceProgram entity itself (as opposed to
 * records inside a program, which are governed by EnsureProgramAccess).
 * Managing programs (create/update/activate/assign users) is Super Admin
 * only in Phase 1.
 */
class ComplianceProgramPolicy
{
    public function view(User $user, ComplianceProgram $program): bool
    {
        return $user->hasProgramAccess($program);
    }

    public function manage(User $user, ?ComplianceProgram $program = null): bool
    {
        return $user->isPlatformSuperAdmin();
    }
}
