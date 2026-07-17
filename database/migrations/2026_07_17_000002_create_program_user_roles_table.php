<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Program-scoped role assignment. A user may hold a different role_key in
 * each ComplianceProgram (e.g. Program Manager in Qiyas, Auditor in ECC).
 *
 * Platform-level roles (Super Admin, Executive Viewer) are NOT represented
 * here — they continue to use the existing global spatie/laravel-permission
 * roles and are granted implicit access to every program by the
 * EnsureProgramAccess middleware, since they are platform-wide, not
 * program-scoped, concerns.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('program_user_roles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('compliance_program_id')->constrained('compliance_programs')->cascadeOnDelete();
            $table->string('role_key', 50);
            $table->foreignId('department_id')->nullable()->constrained('departments')->nullOnDelete();
            $table->boolean('is_active')->default(true);
            $table->foreignId('assigned_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('assigned_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'compliance_program_id', 'role_key', 'department_id'], 'program_user_roles_unique_assignment');
            $table->index(['compliance_program_id', 'is_active']);
            $table->index(['user_id', 'is_active']);
            $table->index('department_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('program_user_roles');
    }
};
