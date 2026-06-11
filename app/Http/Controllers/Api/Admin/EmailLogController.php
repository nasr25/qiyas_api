<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\EmailLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Email delivery log (Super Admin) — recipient, subject, body, sent/failed.
 */
class EmailLogController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $logs = EmailLog::query()
            ->when($request->status, fn ($q) => $q->where('status', $request->status))
            ->when($request->search, fn ($q) => $q
                ->where('to_address', 'like', "%{$request->search}%")
                ->orWhere('subject', 'like', "%{$request->search}%"))
            ->latest()
            ->paginate($request->get('per_page', 20));

        return response()->json([
            'success' => true,
            'data'    => $logs->map(fn ($l) => [
                'id'         => $l->id,
                'to'         => $l->to_address,
                'subject'    => $l->subject,
                'body'       => $l->body,
                'status'     => $l->status,
                'error'      => $l->error,
                'mailer'     => $l->mailer,
                'sent_at'    => $l->sent_at?->toIso8601String(),
                'created_at' => $l->created_at->toIso8601String(),
            ]),
            'meta'    => [
                'current_page' => $logs->currentPage(),
                'last_page'    => $logs->lastPage(),
                'total'        => $logs->total(),
            ],
        ]);
    }
}
