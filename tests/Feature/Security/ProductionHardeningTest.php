<?php

namespace Tests\Feature\Security;

use App\Models\AuditLog;
use App\Models\EvidenceFile;
use App\Models\User;
use App\Services\WorkflowService;
use Database\Seeders\DemoDataSeeder;
use Database\Seeders\TestUsersSeeder;
use Illuminate\Support\Facades\Artisan;
use RuntimeException;
use Tests\Feature\Workflow\WorkflowTestCase;

/**
 * Regressions for the production-readiness gate. Every test here maps to a
 * confirmed defect found by auditing the platform in APP_ENV=production, and
 * asserts the behaviour that was actually wrong — not merely that the fix
 * compiles.
 */
class ProductionHardeningTest extends WorkflowTestCase
{
    // ── Account state is enforced on every request, not just at sign-in ──

    /**
     * The custom JWT middleware carrying the is_active and forced-password
     * checks was aliased as 'jwt.auth', which tymon/jwt-auth also registers.
     * The package's registration won, so the middleware never ran and a
     * deactivated user kept a working token until it expired (24 h).
     */
    public function test_deactivating_a_user_invalidates_their_existing_token(): void
    {
        $user = $this->deptAEmployee;
        $token = auth('api')->login($user);

        $this->withToken($token)->getJson('/api/v1/departments')->assertOk();

        $user->update(['is_active' => false]);

        $this->withToken($token)->getJson('/api/v1/departments')
            ->assertStatus(401)
            ->assertJsonPath('message', 'Account is deactivated.');
    }

    /**
     * must_change_password was advisory: returned to the client and acted on
     * by the Vue router only, so a caller using the API directly never had
     * to rotate a known initial credential.
     */
    public function test_a_pending_forced_password_change_blocks_the_api(): void
    {
        $user = $this->deptAEmployee;
        $user->update(['must_change_password' => true]);
        $token = auth('api')->login($user);

        $this->withToken($token)->getJson('/api/v1/departments')
            ->assertStatus(403)
            ->assertJsonPath('code', 'PASSWORD_CHANGE_REQUIRED');
    }

    /** …but the routes needed to resolve it stay reachable. */
    public function test_the_password_change_flow_itself_stays_reachable(): void
    {
        $user = $this->deptAEmployee;
        $user->update(['must_change_password' => true]);
        $token = auth('api')->login($user);

        $this->withToken($token)->getJson('/api/v1/auth/me')->assertOk();
        // Reached the controller (validation failure), not the middleware.
        $this->withToken($token)->postJson('/api/v1/auth/change-password', [])
            ->assertStatus(422);
    }

    // ── Demo data cannot reach a production database ─────────────────────

    public function test_demo_seeders_refuse_to_run_in_production(): void
    {
        $this->app->detectEnvironment(fn () => 'production');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('blocked in production');

        (new DemoDataSeeder)->run();
    }

    public function test_test_account_seeders_refuse_to_run_in_production(): void
    {
        $this->app->detectEnvironment(fn () => 'production');

        $this->expectException(RuntimeException::class);

        (new TestUsersSeeder)->run();
    }

    public function test_the_demo_data_command_refuses_to_run_in_production(): void
    {
        $before = User::count();
        $this->app->detectEnvironment(fn () => 'production');

        $exit = Artisan::call('system:demo-data');

        $this->assertSame(1, $exit, 'system:demo-data must exit non-zero in production.');
        $this->assertStringContainsString('Refusing to run', Artisan::output());
        $this->assertSame($before, User::count(), 'No account may be created.');
    }

    // ── Malformed input must not become a 500 ────────────────────────────

    /**
     * The login throttle key did `(string) $request->input('username')`.
     * A non-scalar username made that cast raise before validation ran, so
     * an unauthenticated caller could turn a trivial payload into a 500.
     */
    public function test_a_non_string_username_is_rejected_not_a_server_error(): void
    {
        $this->postJson('/api/v1/auth/login', [
            'username' => ['array'],
            'password' => 'x',
        ])->assertStatus(422);
    }

    // ── Liveness must survive its dependencies ───────────────────────────

    public function test_the_liveness_probe_is_self_contained(): void
    {
        $response = $this->get('/up');

        $response->assertOk()->assertExactJson(['status' => 'ok']);
        // No HTML, and above all no external font/CDN references: this
        // platform is deployed on-premises with no Internet access.
        $this->assertStringNotContainsString('<html', $response->getContent());
        $this->assertStringNotContainsString('cdn.', $response->getContent());
        $this->assertStringNotContainsString('fonts.', $response->getContent());
    }

    // ── Missing evidence bytes are a 404, and are not audited as a read ──

    public function test_downloading_an_evidence_row_whose_file_is_gone_is_a_404(): void
    {
        $workflow = app(WorkflowService::class);
        $assignment = $workflow->assign(
            $this->requirement, $this->qiyas, $this->programManager,
            $this->deptA, null, '2026-12-01', null, null, null,
        );
        $submission = $workflow->getOrCreateDraft($assignment, $this->deptAEmployee);

        $file = EvidenceFile::create([
            'evidence_submission_id' => $submission->id,
            'original_name' => 'gone.pdf',
            'stored_name' => 'gone.pdf',
            'storage_path' => 'evidence/definitely/not/here.pdf',
            'mime_type' => 'application/pdf',
            'file_size' => 10,
            'file_hash' => hash('sha256', 'gone'),
            'uploaded_by' => $this->deptAEmployee->id,
            'uploaded_at' => now(),
            'is_active' => true,
        ]);

        $auditedBefore = AuditLog::where('action', 'evidence.downloaded')->count();

        $this->getJson(
            "/api/v1/programs/QIYAS/evidence-files/{$file->id}/download",
            $this->authHeader($this->deptAEmployee)
        )->assertStatus(404);

        $this->assertSame(
            $auditedBefore,
            AuditLog::where('action', 'evidence.downloaded')->count(),
            'A download that never happened must not be recorded as one.'
        );
    }
}
