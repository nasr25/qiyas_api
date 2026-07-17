<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Request;

/**
 * AuditService handles all audit log recording for compliance purposes.
 * Every significant action must be logged.
 */
class AuditService
{
    /**
     * Records an audit event.
     *
     * @param  string  $action  Action identifier (e.g., 'document.approved')
     * @param  string  $description  Human-readable description
     * @param  mixed|null  $model  The affected model instance (optional)
     * @param  array|null  $oldValues  Previous state (for edits)
     * @param  array|null  $newValues  New state (for edits)
     */
    public static function log(
        string $action,
        string $description = '',
        mixed $model = null,
        ?array $oldValues = null,
        ?array $newValues = null,
        ?int $complianceProgramId = null
    ): void {
        AuditLog::create(array_merge([
            'action' => $action,
            'model_type' => $model ? get_class($model) : null,
            'model_id' => $model ? $model->getKey() : null,
            'old_values' => $oldValues,
            'new_values' => $newValues,
            'description' => $description,
            'compliance_program_id' => $complianceProgramId ?? (
                $model && isset($model->compliance_program_id) ? $model->compliance_program_id : null
            ),
            'ip_address' => Request::ip(),
            'user_agent' => Request::userAgent(),
        ], self::actorMeta(Auth::user())));
    }

    /**
     * Logs a login event (also used for dev quick-login via $action).
     *
     * @param  int  $userId  Authenticated user ID
     * @param  string  $authType  Authentication type (ldap|local)
     */
    public static function logLogin(int $userId, string $authType, string $action = 'auth.login'): void
    {
        AuditLog::create(array_merge([
            'action' => $action,
            'description' => "User logged in via {$authType}",
            'ip_address' => Request::ip(),
            'user_agent' => Request::userAgent(),
        ], self::actorMeta(User::find($userId))));
    }

    /**
     * Logs a logout event.
     */
    public static function logLogout(int $userId): void
    {
        AuditLog::create(array_merge([
            'action' => 'auth.logout',
            'description' => 'User logged out',
            'ip_address' => Request::ip(),
            'user_agent' => Request::userAgent(),
        ], self::actorMeta(User::find($userId))));
    }

    /** Resolves user_id / role / department_id for the audit row. */
    private static function actorMeta(?User $user): array
    {
        return [
            'user_id' => $user?->id,
            'role' => $user?->getRoleNames()->first(),
            'department_id' => $user?->department_id,
        ];
    }
}
