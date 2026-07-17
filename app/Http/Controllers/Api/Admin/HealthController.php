<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Protected operational readiness check (Super Admin only — see
 * routes/api.php). The public liveness probe is the framework's built-in
 * `/up` route (bootstrap/app.php `health: '/up'`), which only confirms the
 * app booted; this endpoint actually exercises each dependency and reports
 * per-component pass/fail without leaking hostnames, credentials, paths, or
 * stack traces into the response — see docs/qiyas-operational-runbook.md.
 */
class HealthController extends Controller
{
    /** GET /api/v1/admin/health */
    public function readiness(): JsonResponse
    {
        $checks = [
            'database' => $this->checkDatabase(),
            'cache' => $this->checkCache(),
            'queue' => $this->checkQueue(),
            'storage' => $this->checkStorage(),
            'scheduler' => $this->checkScheduler(),
        ];

        $healthy = collect($checks)->every(fn ($c) => $c['status'] === 'ok');

        return response()->json([
            'success' => true,
            'status' => $healthy ? 'ok' : 'degraded',
            'checked_at' => now()->toIso8601String(),
            'checks' => $checks,
        ], $healthy ? 200 : 503);
    }

    private function checkDatabase(): array
    {
        try {
            DB::select('select 1');

            return ['status' => 'ok'];
        } catch (\Throwable $e) {
            return ['status' => 'fail', 'message' => 'Database connection failed.'];
        }
    }

    private function checkCache(): array
    {
        try {
            $key = 'health:check:'.Str::random(8);
            Cache::put($key, '1', 5);
            $ok = Cache::get($key) === '1';
            Cache::forget($key);

            return ['status' => $ok ? 'ok' : 'fail'];
        } catch (\Throwable $e) {
            return ['status' => 'fail', 'message' => 'Cache read/write failed.'];
        }
    }

    private function checkQueue(): array
    {
        try {
            // The database queue driver's failed/pending job counts are a
            // reasonable, dependency-free proxy for "is the queue table
            // reachable" without actually dispatching a real job.
            $pending = DB::table('jobs')->count();
            $failedRecently = DB::table('failed_jobs')->where('failed_at', '>=', now()->subDay())->count();

            return ['status' => 'ok', 'pending_jobs' => $pending, 'failed_last_24h' => $failedRecently];
        } catch (\Throwable $e) {
            return ['status' => 'fail', 'message' => 'Queue tables unreachable.'];
        }
    }

    private function checkStorage(): array
    {
        try {
            $path = 'health-check/'.Str::random(12).'.txt';
            Storage::disk('private')->put($path, 'ok');
            $ok = Storage::disk('private')->get($path) === 'ok';
            Storage::disk('private')->delete($path);

            return ['status' => $ok ? 'ok' : 'fail'];
        } catch (\Throwable $e) {
            return ['status' => 'fail', 'message' => 'Private disk read/write failed.'];
        }
    }

    /**
     * Reports how long ago compliance:process-sla last ran, based on a
     * heartbeat timestamp the command writes unconditionally on every
     * invocation (see ProcessSlaCommand::handle()) — not "did anything
     * change", which would be a false negative on a quiet system where the
     * scheduler is running correctly but there is simply nothing to warn
     * about. Flags stale if nothing ran in the last 2 hours (the job is
     * scheduled every 30 minutes).
     */
    private function checkScheduler(): array
    {
        $lastRun = Setting::get('operations', 'scheduler_last_heartbeat');
        if (! $lastRun) {
            return ['status' => 'fail', 'message' => 'compliance:process-sla has never run on this environment.'];
        }

        $staleMinutes = now()->diffInMinutes(Carbon::parse($lastRun));

        return [
            'status' => $staleMinutes <= 120 ? 'ok' : 'fail',
            'last_run_minutes_ago' => $staleMinutes,
        ];
    }
}
