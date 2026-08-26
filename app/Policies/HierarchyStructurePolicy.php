<?php

namespace App\Policies;

use App\Models\ComplianceProgram;
use App\Models\User;

/**
 * Authorization for a program's hierarchy STRUCTURE.
 *
 * The distinction the Phase B brief draws is enforced here:
 *
 *   Super Admin     — may manage any program's structure.
 *   Program Manager — may manage ONLY programs they are assigned to.
 *   Everyone else   — may read the structure (labels drive the whole UI)
 *                     but never modify it.
 *
 * Program membership itself is checked one layer earlier by
 * EnsureProgramAccess, which returns 404 for a program the user cannot see
 * at all. This policy therefore distinguishes "can see the program but may
 * not change its shape" (403) from "cannot see the program" (404).
 */
class HierarchyStructurePolicy
{
    public function view(User $user, ComplianceProgram $program): bool
    {
        return $user->hasProgramAccess($program);
    }

    /**
     * hasProgramRole() already returns true for a platform Super Admin, so
     * this single check covers both permitted cases without special-casing.
     */
    public function manage(User $user, ComplianceProgram $program): bool
    {
        return $user->hasProgramRole($program, 'program-manager');
    }
}
