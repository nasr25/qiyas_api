<?php

namespace Tests;

use App\Models\ComplianceProgram;
use App\Models\Department;
use App\Models\HierarchyDefinition;
use App\Models\User;
use App\Services\HierarchyDefinitionService;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Tymon\JWTAuth\Facades\JWTAuth;

abstract class TestCase extends BaseTestCase
{
    /** Authorization header carrying a JWT for the given user. */
    protected function authHeader(User $user): array
    {
        return ['Authorization' => 'Bearer '.JWTAuth::fromUser($user)];
    }

    /** Creates a local user with the given role and (optional) department. */
    protected function makeUser(string $role, ?int $departmentId = null, array $attrs = []): User
    {
        $user = User::create(array_merge([
            'name' => ucfirst($role).' User',
            'username' => $role.'_'.uniqid(),
            'email' => $role.'_'.uniqid().'@qiyas.local',
            'password' => 'Password123!',
            'auth_type' => 'local',
            'department_id' => $departmentId,
            'is_active' => true,
            'must_change_password' => false,
            'locale' => 'ar',
        ], $attrs));
        $user->assignRole($role);

        return $user;
    }

    /**
     * Activates a hierarchy structure for a program through the real service
     * path. Each entry needs at least a `key`; behavioural flags default to
     * a grouping level, so callers only state what differs.
     *
     * Every suite builds its fixtures through this rather than hand-writing
     * rows, so a change to the engine's validation is felt by all of them.
     *
     * @param  array<int, array<string, mixed>>  $levels
     */
    protected function activateStructure(ComplianceProgram $program, array $levels, User $actor): HierarchyDefinition
    {
        $structures = app(HierarchyDefinitionService::class);
        $draft = $structures->openDraft($program, $actor);

        // openDraft() clones the active structure, which is right for real
        // use but not here: this helper's contract is "the structure IS
        // this list", so any inherited levels are cleared first.
        $draft->levels()->delete();
        $draft->refresh();

        foreach ($levels as $level) {
            $structures->addLevel($draft, $level + [
                'name_ar' => $level['key'],
                'name_en' => $level['key'],
                'is_active' => true,
                'appears_in_dashboard' => true,
                'appears_in_reports' => true,
                'appears_in_filters' => true,
                'appears_in_breadcrumb' => true,
            ], $actor);
        }

        return $structures->activate($draft->fresh(), $actor);
    }

    protected function makeDepartment(string $name): Department
    {
        return Department::create(['name_ar' => $name, 'name_en' => $name, 'is_active' => true]);
    }
}
