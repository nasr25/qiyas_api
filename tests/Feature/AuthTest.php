<?php

namespace Tests\Feature;

use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_login_succeeds_and_writes_audit_log(): void
    {
        $user = $this->makeUser('employee');

        $this->postJson('/api/v1/auth/login', [
            'username' => $user->username,
            'password' => 'Password123!',
        ])->assertOk()->assertJsonStructure(['data' => ['token', 'user']]);

        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $user->id,
            'action' => 'auth.login',
        ]);
    }

    public function test_login_fails_with_wrong_password(): void
    {
        $user = $this->makeUser('employee');

        $this->postJson('/api/v1/auth/login', [
            'username' => $user->username,
            'password' => 'wrong-password',
        ])->assertStatus(401);
    }

    public function test_quick_login_is_disabled_outside_local_or_debug(): void
    {
        config(['app.debug' => false]); // env is 'testing', not 'local'

        $this->makeUser('super-admin', null, ['username' => 'superadmin']);

        $this->postJson('/api/v1/auth/quick-login', ['username' => 'superadmin'])
            ->assertStatus(403);

        $this->getJson('/api/v1/auth/dev-users')
            ->assertOk()->assertJson(['data' => []]);
    }

    public function test_quick_login_works_in_debug_and_writes_quick_login_audit(): void
    {
        config(['app.debug' => true]);

        $user = $this->makeUser('super-admin', null, ['username' => 'superadmin']);

        $this->postJson('/api/v1/auth/quick-login', ['username' => 'superadmin'])
            ->assertOk()->assertJsonStructure(['data' => ['token', 'user']]);

        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $user->id,
            'action' => 'auth.quick_login',
        ]);
    }

    public function test_audit_log_captures_role_and_department(): void
    {
        $dept = $this->makeDepartment('IT');
        $user = $this->makeUser('employee', $dept->id);

        $this->postJson('/api/v1/auth/login', [
            'username' => $user->username,
            'password' => 'Password123!',
        ])->assertOk();

        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $user->id,
            'action' => 'auth.login',
            'role' => 'employee',
            'department_id' => $dept->id,
        ]);
    }
}
