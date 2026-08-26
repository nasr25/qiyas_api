<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Manages in-app notifications for the authenticated user.
 */
class NotificationController extends Controller
{
    /**
     * Returns paginated notifications for the authenticated user.
     * GET /api/v1/notifications
     */
    public function index(Request $request): JsonResponse
    {
        $notifications = $request->user()
            ->notifications()
            ->when($request->unread_only, fn ($q) => $q->whereNull('read_at'))
            ->latest()
            ->paginate($request->get('per_page', 20));

        return response()->json([
            'success' => true,
            'data' => $notifications->map(fn ($n) => [
                'id' => $n->id,
                'type' => class_basename($n->type),
                'data' => $n->data,
                'read_at' => $n->read_at?->toIso8601String(),
                'created_at' => $n->created_at->toIso8601String(),
            ]),
            'meta' => [
                'unread_count' => $request->user()->unreadNotifications()->count(),
                'current_page' => $notifications->currentPage(),
                'last_page' => $notifications->lastPage(),
                'total' => $notifications->total(),
            ],
        ]);
    }

    /**
     * Marks a notification as read.
     * POST /api/v1/notifications/{id}/read
     */
    public function markRead(Request $request, string $id): JsonResponse
    {
        $notification = $request->user()->notifications()->findOrFail($id);
        $notification->markAsRead();

        return response()->json(['success' => true]);
    }

    /**
     * Marks all notifications as read.
     * POST /api/v1/notifications/mark-all-read
     */
    public function markAllRead(Request $request): JsonResponse
    {
        $request->user()->unreadNotifications->markAsRead();

        return response()->json(['success' => true, 'message' => 'All notifications marked as read.']);
    }

    /**
     * Deletes a notification.
     * DELETE /api/v1/notifications/{id}
     */
    public function destroy(Request $request, string $id): JsonResponse
    {
        $request->user()->notifications()->findOrFail($id)->delete();

        return response()->json(['success' => true]);
    }

    /**
     * Returns the unread notification count.
     * GET /api/v1/notifications/count
     */
    public function count(Request $request): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => ['unread' => $request->user()->unreadNotifications()->count()],
        ]);
    }
}
