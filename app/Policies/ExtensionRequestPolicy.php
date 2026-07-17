<?php

namespace App\Policies;

use App\Models\ExtensionRequest;
use App\Models\User;
use App\Services\ProgramConfigurationService;

/**
 * WHO decides an extension request is program-configurable (category
 * 'extensions', key 'reviewer_role') as of Phase 4 — see
 * docs/extension-engine.md. For Qiyas that role is 'auditor', so the
 * Department Manager still never decides one (Phase 2 requirement,
 * unchanged) — but the rule now comes from configuration, not a literal
 * string in this class.
 */
class ExtensionRequestPolicy
{
    public function __construct(private readonly ProgramConfigurationService $config) {}

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

        $program = $extensionRequest->program;
        if (! $program) {
            return false;
        }

        $reviewerRole = $this->config->get($program, 'extensions', ['reviewer_role' => 'auditor'])['reviewer_role'] ?? 'auditor';

        return $user->hasProgramRole($program, $reviewerRole);
    }
}
