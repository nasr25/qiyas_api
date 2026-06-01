<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Audit log viewer — read-only, no modifications permitted.
 */
class AuditLogController extends Controller
{
    /**
     * Returns paginated audit logs with filters.
     * GET /api/v1/admin/audit-logs
     */
    public function index(Request $request): JsonResponse
    {
        $logs = AuditLog::with('user')
            ->when($request->user_id, fn($q) => $q->where('user_id', $request->user_id))
            ->when($request->action, fn($q) => $q->where('action', 'like', "%{$request->action}%"))
            ->when($request->date_from, fn($q) => $q->whereDate('created_at', '>=', $request->date_from))
            ->when($request->date_to, fn($q) => $q->whereDate('created_at', '<=', $request->date_to))
            ->when($request->ip_address, fn($q) => $q->where('ip_address', $request->ip_address))
            ->latest('created_at')
            ->paginate($request->get('per_page', 25));

        return response()->json([
            'success' => true,
            'data'    => $logs->map(fn($log) => [
                'id'          => $log->id,
                'user'        => $log->user ? ['id' => $log->user->id, 'name' => $log->user->name, 'username' => $log->user->username] : null,
                'action'      => $log->action,
                'description' => $log->description,
                'model_type'  => $log->model_type ? class_basename($log->model_type) : null,
                'model_id'    => $log->model_id,
                'old_values'  => $log->old_values,
                'new_values'  => $log->new_values,
                'ip_address'  => $log->ip_address,
                'user_agent'  => $log->user_agent,
                'created_at'  => $log->created_at->toIso8601String(),
            ]),
            'meta'    => [
                'current_page' => $logs->currentPage(),
                'last_page'    => $logs->lastPage(),
                'total'        => $logs->total(),
            ],
        ]);
    }
}
