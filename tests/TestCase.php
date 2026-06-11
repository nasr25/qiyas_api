<?php

namespace Tests;

use App\Models\Department;
use App\Models\User;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Tymon\JWTAuth\Facades\JWTAuth;

abstract class TestCase extends BaseTestCase
{
    /** Authorization header carrying a JWT for the given user. */
    protected function authHeader(User $user): array
    {
        return ['Authorization' => 'Bearer ' . JWTAuth::fromUser($user)];
    }

    /** Creates a local user with the given role and (optional) department. */
    protected function makeUser(string $role, ?int $departmentId = null, array $attrs = []): User
    {
        $user = User::create(array_merge([
            'name'                 => ucfirst($role) . ' User',
            'username'             => $role . '_' . uniqid(),
            'email'                => $role . '_' . uniqid() . '@qiyas.local',
            'password'             => 'Password123!',
            'auth_type'            => 'local',
            'department_id'        => $departmentId,
            'is_active'            => true,
            'must_change_password' => false,
            'locale'               => 'ar',
        ], $attrs));
        $user->assignRole($role);

        return $user;
    }

    protected function makeDepartment(string $name): Department
    {
        return Department::create(['name_ar' => $name, 'name_en' => $name, 'is_active' => true]);
    }
}
