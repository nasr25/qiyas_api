<?php

namespace App\Policies;

use App\Models\ExtensionRequest;
use App\Models\User;

/**
 * The Department Manager never decides an extension request (Phase 2
 * requirement) — only `view` is granted to them, never `decide`.
 */
class ExtensionRequestPolicy
{
    public function view(User $user, ExtensionRequest $extensionRequest): bool
    {
        if ($user->isPlatformSuperAdmin() || $user->isPlatformExecutiveViewer()) {
            return true;
        }

        $program = $extensionRequest->program;
        if ($program && ($user->hasProgramRole($program, 'auditor') || $user->hasProgramRole($program, 'program-manager'))) {
            return true;
        }

        if ($extensionRequest->requested_by === $user->id) {
            return true;
        }

        $assignment = $extensionRequest->assignment;
        if ($assignment && $program) {
            return $user->managedDepartmentId($program) === $assignment->department_id;
        }

        return false;
    }

    public function decide(User $user, ExtensionRequest $extensionRequest): bool
    {
        if ($user->isPlatformSuperAdmin()) {
            return true;
        }

        return $extensionRequest->program && $user->hasProgramRole($extensionRequest->program, 'auditor');
    }
}
