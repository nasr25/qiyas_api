<?php

namespace App\Policies;

use App\Models\EvidenceSubmission;
use App\Models\User;

class EvidenceSubmissionPolicy
{
    public function view(User $user, EvidenceSubmission $submission): bool
    {
        $program = $submission->program;

        if ($user->isPlatformSuperAdmin() || $user->isPlatformExecutiveViewer()) {
            return true;
        }
        if ($user->hasProgramRole($program, 'program-manager') || $user->hasProgramRole($program, 'auditor')) {
            return true;
        }

        $managedDept = $user->managedDepartmentId($program);
        $employeeDept = $user->employeeDepartmentId($program);

        return $managedDept === $submission->department_id || $employeeDept === $submission->department_id;
    }

    /** Employee-side edit: upload/remove files, edit comment, submit. */
    public function edit(User $user, EvidenceSubmission $submission): bool
    {
        if ($user->isPlatformSuperAdmin()) {
            return true;
        }
        if (! $submission->isEditableByEmployee()) {
            return false;
        }

        $program = $submission->program;
        if ($user->employeeDepartmentId($program) !== $submission->department_id) {
            return false;
        }

        $assignedEmployeeId = $submission->assignment?->employee_id;

        // If the assignment names a specific employee, only that employee may
        // edit; otherwise any employee of the department may pick it up.
        return $assignedEmployeeId === null || $assignedEmployeeId === $user->id;
    }

    public function reviewAsDepartmentManager(User $user, EvidenceSubmission $submission): bool
    {
        if ($user->isPlatformSuperAdmin()) {
            return true;
        }

        return $user->managedDepartmentId($submission->program) === $submission->department_id;
    }

    public function reviewAsAuditor(User $user, EvidenceSubmission $submission): bool
    {
        return $user->isPlatformSuperAdmin() || $user->hasProgramRole($submission->program, 'auditor');
    }

    public function reviewAsProgramManager(User $user, EvidenceSubmission $submission): bool
    {
        return $user->isPlatformSuperAdmin() || $user->hasProgramRole($submission->program, 'program-manager');
    }

    public function downloadFile(User $user, EvidenceSubmission $submission): bool
    {
        return $this->view($user, $submission);
    }
}
