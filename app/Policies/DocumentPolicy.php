<?php

namespace App\Policies;

use App\Models\Document;
use App\Models\User;

/**
 * DocumentPolicy enforces document access rules.
 * Employees/coordinators can only access their own department's documents.
 * Auditors and admins can access all documents.
 */
class DocumentPolicy
{
    /** Super admin always passes. */
    public function before(User $user): ?bool
    {
        if ($user->hasRole('super-admin')) return true;
        return null;
    }

    /** Any authenticated user can view documents in their department. */
    public function view(User $user, Document $document): bool
    {
        if ($user->hasRole('auditor') || $user->hasRole('executive')) return true;
        return $user->department_id === $document->department_id;
    }

    /** Employees and coordinators in the same department can upload. */
    public function upload(User $user, Document $document): bool
    {
        if ($document->cycle->isReadOnly()) return false;
        return $user->department_id === $document->department_id;
    }

    /** Same department members can submit. */
    public function submit(User $user, Document $document): bool
    {
        if ($document->cycle->isReadOnly()) return false;
        return $user->department_id === $document->department_id;
    }

    /** Only auditors can approve. */
    public function approve(User $user, Document $document): bool
    {
        return $user->hasRole('auditor');
    }

    /** Only auditors can reject. */
    public function reject(User $user, Document $document): bool
    {
        return $user->hasRole('auditor');
    }

    /** Department members can download. */
    public function download(User $user, Document $document): bool
    {
        if ($user->hasRole('auditor') || $user->hasRole('executive')) return true;
        return $user->department_id === $document->department_id;
    }
}
