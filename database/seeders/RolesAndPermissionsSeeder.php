<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * Seeds all roles and permissions for the Qiyas platform.
 */
class RolesAndPermissionsSeeder extends Seeder
{
    public function run(): void
    {
        // Clear cached permissions
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

        $permissions = [
            // Users
            'users.view', 'users.create', 'users.edit', 'users.delete', 'users.import-ldap',

            // Departments
            'departments.view', 'departments.create', 'departments.edit', 'departments.delete',

            // Cycles
            'cycles.view', 'cycles.create', 'cycles.edit', 'cycles.activate', 'cycles.close', 'cycles.archive',

            // Standards
            'standards.view', 'standards.create', 'standards.edit', 'standards.delete',

            // Requirements
            'requirements.view', 'requirements.create', 'requirements.edit', 'requirements.delete',

            // Documents
            'documents.view', 'documents.create', 'documents.upload', 'documents.submit',
            'documents.approve', 'documents.reject', 'documents.download',

            // Extensions
            'extensions.view', 'extensions.create', 'extensions.approve', 'extensions.reject',

            // Reports
            'reports.view', 'reports.export',

            // Settings
            'settings.view', 'settings.edit',

            // Audit Logs
            'audit-logs.view',

            // Comments
            'comments.view', 'comments.create', 'comments.delete',
        ];

        foreach ($permissions as $permission) {
            Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'api']);
        }

        // ── Super Admin ──────────────────────────────────────────────────────
        $superAdmin = Role::firstOrCreate(['name' => 'super-admin', 'guard_name' => 'api']);
        $superAdmin->givePermissionTo(Permission::all());

        // ── Qiyas Administrator (functional owner: cycle + standards + progress)
        $qiyasAdmin = Role::firstOrCreate(['name' => 'qiyas-admin', 'guard_name' => 'api']);
        $qiyasAdmin->givePermissionTo([
            'departments.view', 'departments.create', 'departments.edit',
            'cycles.view', 'cycles.create', 'cycles.edit', 'cycles.activate', 'cycles.close', 'cycles.archive',
            'standards.view', 'standards.create', 'standards.edit', 'standards.delete',
            'requirements.view', 'requirements.create', 'requirements.edit', 'requirements.delete',
            'documents.view',
            'reports.view', 'reports.export',
            'comments.view',
            'audit-logs.view',
        ]);

        // ── Auditor ──────────────────────────────────────────────────────────
        $auditor = Role::firstOrCreate(['name' => 'auditor', 'guard_name' => 'api']);
        $auditor->givePermissionTo([
            'departments.view',
            'cycles.view',
            'standards.view',
            'requirements.view',
            'documents.view', 'documents.download', 'documents.approve', 'documents.reject',
            'extensions.view', 'extensions.approve', 'extensions.reject',
            'reports.view', 'reports.export',
            'comments.view', 'comments.create',
        ]);

        // ── Coordinator ──────────────────────────────────────────────────────
        $coordinator = Role::firstOrCreate(['name' => 'coordinator', 'guard_name' => 'api']);
        $coordinator->givePermissionTo([
            'departments.view',
            'cycles.view',
            'standards.view',
            'requirements.view',
            'documents.view', 'documents.create', 'documents.upload', 'documents.submit', 'documents.download',
            'extensions.view', 'extensions.create',
            'reports.view',
            'comments.view', 'comments.create',
        ]);

        // ── Employee ─────────────────────────────────────────────────────────
        $employee = Role::firstOrCreate(['name' => 'employee', 'guard_name' => 'api']);
        $employee->givePermissionTo([
            'cycles.view',
            'standards.view',
            'requirements.view',
            'documents.view', 'documents.create', 'documents.upload', 'documents.submit', 'documents.download',
            'extensions.view', 'extensions.create',
            'comments.view', 'comments.create',
        ]);

        // ── Executive Viewer ─────────────────────────────────────────────────
        $executive = Role::firstOrCreate(['name' => 'executive', 'guard_name' => 'api']);
        $executive->givePermissionTo([
            'departments.view',
            'cycles.view',
            'standards.view',
            'documents.view',
            'reports.view', 'reports.export',
        ]);

        $this->command->info('Roles and permissions seeded successfully.');
    }
}
