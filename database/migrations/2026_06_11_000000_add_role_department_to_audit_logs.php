<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Audit logs should capture the actor's role and department for compliance.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('audit_logs', function (Blueprint $table) {
            $table->string('role', 50)->nullable()->after('user_id');
            $table->unsignedBigInteger('department_id')->nullable()->after('role');
            $table->index('department_id');
        });
    }

    public function down(): void
    {
        Schema::table('audit_logs', function (Blueprint $table) {
            $table->dropIndex(['department_id']);
            $table->dropColumn(['role', 'department_id']);
        });
    }
};
