<?php

namespace App\Services;

use App\Models\AuditLog;
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
     * @param string     $action      Action identifier (e.g., 'document.approved')
     * @param string     $description Human-readable description
     * @param mixed|null $model       The affected model instance (optional)
     * @param array|null $oldValues   Previous state (for edits)
     * @param array|null $newValues   New state (for edits)
     */
    public static function log(
        string $action,
        string $description = '',
        mixed  $model = null,
        ?array $oldValues = null,
        ?array $newValues = null
    ): void {
        AuditLog::create([
            'user_id'    => Auth::id(),
            'action'     => $action,
            'model_type' => $model ? get_class($model) : null,
            'model_id'   => $model ? $model->getKey() : null,
            'old_values' => $oldValues,
            'new_values' => $newValues,
            'description' => $description,
            'ip_address'  => Request::ip(),
            'user_agent'  => Request::userAgent(),
        ]);
    }

    /**
     * Logs a login event.
     *
     * @param int    $userId   Authenticated user ID
     * @param string $authType Authentication type (ldap|local)
     */
    public static function logLogin(int $userId, string $authType): void
    {
        AuditLog::create([
            'user_id'     => $userId,
            'action'      => 'auth.login',
            'description' => "User logged in via {$authType}",
            'ip_address'  => Request::ip(),
            'user_agent'  => Request::userAgent(),
        ]);
    }

    /**
     * Logs a logout event.
     */
    public static function logLogout(int $userId): void
    {
        AuditLog::create([
            'user_id'     => $userId,
            'action'      => 'auth.logout',
            'description' => 'User logged out',
            'ip_address'  => Request::ip(),
            'user_agent'  => Request::userAgent(),
        ]);
    }
}
