<?php

namespace Tests\Feature\Admin;

use App\Models\BrandingAsset;
use App\Models\SettingVersion;
use App\Models\SmtpSetting;
use App\Services\BrandingService;
use App\Services\SmtpSettingsService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

/**
 * Phase 8: Super Admin branding + SMTP settings — versioning, secret
 * handling, and authorization. See docs/administration/branding.md and
 * docs/administration/smtp-settings.md.
 */
class PlatformAdministrationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    // ── Branding: upload validation ──────────────────────────────────────

    public function test_a_valid_png_logo_uploads_and_can_be_activated(): void
    {
        $admin = $this->makeUser('super-admin');
        $file = UploadedFile::fake()->image('logo.png', 200, 100);

        $response = $this->postJson('/api/v1/admin/branding/logo_primary/upload', ['file' => $file], $this->authHeader($admin))
            ->assertCreated();

        $assetId = $response->json('data.id');
        $this->assertDatabaseHas('branding_assets', ['id' => $assetId, 'asset_type' => 'logo_primary', 'status' => 'inactive', 'version' => 1]);

        $this->postJson("/api/v1/admin/branding/logo_primary/{$assetId}/activate", [], $this->authHeader($admin))
            ->assertOk()
            ->assertJsonPath('data.status', 'active');

        $this->assertDatabaseHas('branding_assets', ['id' => $assetId, 'status' => 'active']);
    }

    public function test_activating_a_new_version_supersedes_the_previous_active_version(): void
    {
        $admin = $this->makeUser('super-admin');
        $branding = app(BrandingService::class);

        $v1 = $branding->upload(UploadedFile::fake()->image('logo1.png', 100, 100), 'favicon', $admin);
        $branding->activate($v1, $admin);
        $v2 = $branding->upload(UploadedFile::fake()->image('logo2.png', 100, 100), 'favicon', $admin);
        $branding->activate($v2, $admin);

        $this->assertSame('superseded', $v1->fresh()->status);
        $this->assertSame('active', $v2->fresh()->status);
        $this->assertSame(1, BrandingAsset::ofType('favicon')->active()->count());
    }

    public function test_restoring_a_superseded_version_reactivates_it_without_deleting_history(): void
    {
        $admin = $this->makeUser('super-admin');
        $branding = app(BrandingService::class);

        $v1 = $branding->upload(UploadedFile::fake()->image('a.png'), 'logo_header', $admin);
        $branding->activate($v1, $admin);
        $v2 = $branding->upload(UploadedFile::fake()->image('b.png'), 'logo_header', $admin);
        $branding->activate($v2, $admin);

        $branding->restore($v1->fresh(), $admin);

        $this->assertSame('active', $v1->fresh()->status);
        $this->assertSame('superseded', $v2->fresh()->status);
        $this->assertDatabaseCount('branding_assets', 2); // Both versions still exist.
    }

    public function test_a_non_image_file_is_rejected(): void
    {
        $admin = $this->makeUser('super-admin');
        $file = UploadedFile::fake()->create('not-a-logo.pdf', 10, 'application/pdf');

        $this->postJson('/api/v1/admin/branding/logo_primary/upload', ['file' => $file], $this->authHeader($admin))
            ->assertStatus(422);
    }

    public function test_a_corrupted_image_masquerading_as_png_is_rejected(): void
    {
        $admin = $this->makeUser('super-admin');
        // Real PNG extension/name, but not actually decodable image bytes.
        $file = UploadedFile::fake()->createWithContent('logo.png', 'not actually a png file, just text');

        $this->postJson('/api/v1/admin/branding/logo_primary/upload', ['file' => $file], $this->authHeader($admin))
            ->assertStatus(422);
    }

    public function test_an_svg_with_an_embedded_script_is_sanitized_or_rejected(): void
    {
        $admin = $this->makeUser('super-admin');
        $unsafeSvg = '<svg xmlns="http://www.w3.org/2000/svg" onload="alert(1)"><script>alert(1)</script><circle cx="5" cy="5" r="4"/></svg>';
        $file = UploadedFile::fake()->createWithContent('logo.svg', $unsafeSvg);

        $response = $this->postJson('/api/v1/admin/branding/logo_primary/upload', ['file' => $file], $this->authHeader($admin));

        // Either sanitized-and-accepted (in which case the stored file must
        // no longer contain the script) or rejected outright — never
        // stored with the script intact.
        if ($response->status() === 201) {
            $asset = BrandingAsset::find($response->json('data.id'));
            $stored = \Illuminate\Support\Facades\Storage::disk('public')->get($asset->storage_path);
            $this->assertStringNotContainsString('<script', $stored);
            $this->assertStringNotContainsString('onload=', $stored);
        } else {
            $response->assertStatus(422);
        }
    }

    public function test_an_svg_with_an_xxe_entity_declaration_is_rejected(): void
    {
        $admin = $this->makeUser('super-admin');
        $xxeSvg = '<?xml version="1.0"?><!DOCTYPE svg [<!ENTITY xxe SYSTEM "file:///etc/passwd">]><svg xmlns="http://www.w3.org/2000/svg">&xxe;</svg>';
        $file = UploadedFile::fake()->createWithContent('logo.svg', $xxeSvg);

        $this->postJson('/api/v1/admin/branding/logo_primary/upload', ['file' => $file], $this->authHeader($admin))
            ->assertStatus(422);
    }

    // ── Branding: audit + authorization ──────────────────────────────────

    public function test_branding_changes_are_audited(): void
    {
        $admin = $this->makeUser('super-admin');
        $file = UploadedFile::fake()->image('logo.png');

        $this->postJson('/api/v1/admin/branding/logo_primary/upload', ['file' => $file], $this->authHeader($admin))->assertCreated();

        $this->assertDatabaseHas('audit_logs', ['action' => 'branding.uploaded']);
    }

    public function test_non_super_admin_roles_cannot_manage_branding(): void
    {
        $file = UploadedFile::fake()->image('logo.png');

        foreach (['auditor', 'coordinator', 'employee', 'executive'] as $role) {
            $user = $this->makeUser($role);
            $this->postJson('/api/v1/admin/branding/logo_primary/upload', ['file' => $file], $this->authHeader($user))
                ->assertStatus(403);
        }
    }

    // ── SMTP: secret handling ────────────────────────────────────────────

    public function test_smtp_password_is_encrypted_at_rest_and_never_returned_by_the_api(): void
    {
        $admin = $this->makeUser('super-admin');

        $this->putJson('/api/v1/admin/smtp-settings', [
            'is_enabled' => true, 'host' => 'smtp.internal.test', 'port' => 587,
            'encryption' => 'starttls', 'auth_enabled' => true,
            'username' => 'notify@internal.test', 'password' => 'S3cr3tPassw0rd!',
            'from_email' => 'notify@internal.test', 'connection_timeout' => 10,
            'verify_certificate' => true, 'queue_enabled' => true,
            'retry_count' => 3, 'retry_delay' => 60, 'internal_relay_mode' => false,
        ], $this->authHeader($admin))->assertOk();

        $setting = SmtpSetting::first();
        $this->assertNotNull($setting->password_encrypted);
        $this->assertStringNotContainsString('S3cr3tPassw0rd!', $setting->password_encrypted);
        $this->assertSame('S3cr3tPassw0rd!', $setting->decryptPassword());

        $response = $this->getJson('/api/v1/admin/smtp-settings', $this->authHeader($admin))->assertOk();
        $response->assertJsonMissingPath('data.password');
        $response->assertJsonMissingPath('data.password_encrypted');
        $response->assertJsonPath('data.password_configured', true);
        $this->assertStringNotContainsString('S3cr3tPassw0rd!', $response->getContent());
    }

    public function test_saving_settings_with_an_empty_password_preserves_the_existing_password(): void
    {
        $admin = $this->makeUser('super-admin');
        app(SmtpSettingsService::class)->save([
            'is_enabled' => true, 'host' => 'smtp.internal.test', 'port' => 587,
            'encryption' => 'starttls', 'auth_enabled' => true, 'username' => 'a@b.test', 'password' => 'FirstPass1!',
            'from_email' => 'a@b.test', 'connection_timeout' => 10, 'verify_certificate' => true,
            'queue_enabled' => true, 'retry_count' => 3, 'retry_delay' => 60, 'internal_relay_mode' => false,
        ], $admin);

        // Re-save with password omitted (empty) — same non-secret field changed.
        $this->putJson('/api/v1/admin/smtp-settings', [
            'is_enabled' => true, 'host' => 'smtp.internal.test', 'port' => 2525,
            'encryption' => 'starttls', 'auth_enabled' => true, 'username' => 'a@b.test', 'password' => '',
            'from_email' => 'a@b.test', 'connection_timeout' => 10, 'verify_certificate' => true,
            'queue_enabled' => true, 'retry_count' => 3, 'retry_delay' => 60, 'internal_relay_mode' => false,
        ], $this->authHeader($admin))->assertOk();

        $this->assertSame('FirstPass1!', SmtpSetting::first()->decryptPassword());
        $this->assertSame(2525, SmtpSetting::first()->port);
    }

    public function test_smtp_secret_changes_are_audited_without_the_secret_value(): void
    {
        $admin = $this->makeUser('super-admin');

        $this->putJson('/api/v1/admin/smtp-settings', [
            'is_enabled' => true, 'host' => 'smtp.internal.test', 'port' => 587,
            'encryption' => 'starttls', 'auth_enabled' => true, 'username' => 'a@b.test', 'password' => 'TopSecret1!',
            'from_email' => 'a@b.test', 'connection_timeout' => 10, 'verify_certificate' => true,
            'queue_enabled' => true, 'retry_count' => 3, 'retry_delay' => 60, 'internal_relay_mode' => false,
        ], $this->authHeader($admin))->assertOk();

        $version = SettingVersion::where('group', 'smtp')->where('is_secret', true)->first();
        $this->assertNotNull($version);
        $this->assertSame('configured', $version->secret_action);
        $this->assertNull($version->old_value);
        $this->assertNull($version->new_value);

        $this->assertDatabaseHas('audit_logs', ['action' => 'smtp_settings.password_configured']);
        $auditLog = \App\Models\AuditLog::where('action', 'smtp_settings.password_configured')->first();
        $this->assertStringNotContainsString('TopSecret1!', json_encode($auditLog->toArray()));
    }

    public function test_unencrypted_smtp_is_rejected_unless_internal_relay_mode_is_set(): void
    {
        $admin = $this->makeUser('super-admin');

        $this->putJson('/api/v1/admin/smtp-settings', [
            'is_enabled' => true, 'host' => 'relay.internal.test', 'port' => 25,
            'encryption' => 'none', 'auth_enabled' => false,
            'from_email' => 'a@b.test', 'connection_timeout' => 10, 'verify_certificate' => true,
            'queue_enabled' => true, 'retry_count' => 3, 'retry_delay' => 60, 'internal_relay_mode' => false,
        ], $this->authHeader($admin))->assertStatus(422);
    }

    public function test_non_super_admin_roles_cannot_access_smtp_settings(): void
    {
        foreach (['auditor', 'coordinator', 'employee', 'executive'] as $role) {
            $user = $this->makeUser($role);
            $this->getJson('/api/v1/admin/smtp-settings', $this->authHeader($user))->assertStatus(403);
            $this->putJson('/api/v1/admin/smtp-settings', [], $this->authHeader($user))->assertStatus(403);
        }
    }

    public function test_unauthorized_direct_api_calls_are_rejected_without_authentication(): void
    {
        $this->getJson('/api/v1/admin/smtp-settings')->assertStatus(401);
        $this->getJson('/api/v1/admin/branding/logo_primary')->assertStatus(401);
    }
}
