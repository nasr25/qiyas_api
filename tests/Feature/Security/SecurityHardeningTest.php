<?php

namespace Tests\Feature\Security;

use App\Services\WorkflowService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Tests\Feature\Workflow\WorkflowTestCase;

/**
 * Phase 3 hardening regressions: each test here corresponds to a confirmed
 * finding from the Phase 3 security audit and proves the fix actually
 * closes the gap, not just that the code compiles.
 */
class SecurityHardeningTest extends WorkflowTestCase
{
    public function test_login_is_rate_limited_after_repeated_attempts(): void
    {
        $user = $this->makeUser('employee');

        for ($i = 0; $i < 10; $i++) {
            $this->postJson('/api/v1/auth/login', [
                'username' => $user->username,
                'password' => 'wrong-password',
            ])->assertStatus(401);
        }

        // The 11th attempt within the same minute, same username+IP, must be throttled.
        $this->postJson('/api/v1/auth/login', [
            'username' => $user->username,
            'password' => 'wrong-password',
        ])->assertStatus(429);
    }

    public function test_login_rate_limit_is_scoped_per_username_not_global(): void
    {
        $userA = $this->makeUser('employee');
        $userB = $this->makeUser('employee');

        for ($i = 0; $i < 10; $i++) {
            $this->postJson('/api/v1/auth/login', [
                'username' => $userA->username,
                'password' => 'wrong-password',
            ]);
        }

        // A different username from the same test client (same IP) must not be
        // punished by user A's exhausted attempts.
        $this->postJson('/api/v1/auth/login', [
            'username' => $userB->username,
            'password' => 'Password123!',
        ])->assertOk();
    }

    public function test_disguised_executable_upload_is_rejected_by_real_mime_detection(): void
    {
        $assignment = app(WorkflowService::class)->assign(
            $this->requirement, $this->qiyas, $this->programManager, $this->deptA, null, '2026-12-01', null, null, null,
        );
        $draft = app(WorkflowService::class)->getOrCreateDraft($assignment, $this->deptAEmployee);

        // A minimal but structurally valid Windows PE (MZ/PE) header, renamed
        // with an allowed extension. The client-declared MIME type and the
        // filename extension both look like an innocuous PDF; only real
        // content inspection (finfo) can catch this. Built as a genuine
        // Illuminate\Http\UploadedFile (not Testing\File::fake(), which
        // reports a MIME type guessed from the filename rather than reading
        // the actual bytes and would not exercise this check at all).
        $peBytes = 'MZ'.str_repeat("\x00", 58).pack('V', 64)
            ."PE\x00\x00"
            .pack('vvVVVvv', 0x14C, 0, 0, 0, 0, 0xE0, 0x0102)
            .pack('v', 0x10B).str_repeat("\x00", 222);

        $tempPath = tempnam(sys_get_temp_dir(), 'pe');
        file_put_contents($tempPath, $peBytes);
        $disguised = new UploadedFile($tempPath, 'report.pdf', 'application/pdf', null, true);

        $this->postJson("/api/v1/programs/QIYAS/evidence-submissions/{$draft->id}/files", [
            'file' => $disguised,
        ], $this->authHeader($this->deptAEmployee))->assertStatus(422);

        @unlink($tempPath);
    }

    public function test_macro_enabled_workbook_renamed_to_xlsx_is_rejected_on_import_preview(): void
    {
        $zipPath = tempnam(sys_get_temp_dir(), 'macro').'.xlsx';
        $zip = new \ZipArchive;
        $zip->open($zipPath, \ZipArchive::CREATE);
        $zip->addFromString('[Content_Types].xml', '<?xml version="1.0"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"/>');
        // The definitive macro marker the validator looks for.
        $zip->addFromString('xl/vbaProject.bin', 'fake-vba-bytes');
        $zip->close();

        $file = new UploadedFile($zipPath, 'renamed-macro-workbook.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);

        $response = $this->postJson('/api/v1/programs/QIYAS/requirements-import/preview', [
            'file' => $file,
            'cycle_id' => $this->cycle->id,
        ], $this->authHeader($this->programManager))->assertOk();

        $response->assertJsonFragment(['code' => 'MACRO_ENABLED_REJECTED']);

        @unlink($zipPath);
    }

    public function test_security_headers_are_present_on_api_responses(): void
    {
        $user = $this->makeUser('employee');

        $response = $this->postJson('/api/v1/auth/login', [
            'username' => $user->username,
            'password' => 'Password123!',
        ]);

        $response->assertHeader('X-Content-Type-Options', 'nosniff');
        $response->assertHeader('X-Frame-Options', 'DENY');
        $response->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
        $response->assertHeader('Content-Security-Policy');
    }

    public function test_readiness_health_check_reports_each_component_and_is_super_admin_only(): void
    {
        $this->getJson('/api/v1/admin/health', $this->authHeader($this->deptAEmployee))
            ->assertStatus(403);

        // Before the scheduler has ever run, the endpoint correctly reports
        // the whole system as degraded (503) rather than silently ignoring
        // a missing heartbeat — this is the desired behavior right after a
        // fresh deployment, not a bug.
        $before = $this->getJson('/api/v1/admin/health', $this->authHeader($this->superAdmin))
            ->assertStatus(503);
        $before->assertJsonStructure([
            'status', 'checked_at',
            'checks' => ['database', 'cache', 'queue', 'storage', 'scheduler'],
        ]);
        $before->assertJsonPath('checks.database.status', 'ok');
        $before->assertJsonPath('checks.cache.status', 'ok');
        $before->assertJsonPath('checks.storage.status', 'ok');
        $before->assertJsonPath('checks.scheduler.status', 'fail');

        Artisan::call('compliance:process-sla');

        $this->getJson('/api/v1/admin/health', $this->authHeader($this->superAdmin))
            ->assertOk()
            ->assertJsonPath('checks.scheduler.status', 'ok');
    }

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }
}
