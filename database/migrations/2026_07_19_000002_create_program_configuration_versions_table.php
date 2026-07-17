<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Append-only history of every program_configurations change — never
 * updated or deleted. Written by ProgramConfigurationService::set()
 * alongside the AuditService entry, so a configuration change is both
 * queryable structured history and part of the general audit trail.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('program_configuration_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('compliance_program_id')->constrained('compliance_programs')->cascadeOnDelete();
            $table->string('category', 50);
            $table->unsignedInteger('version');
            $table->json('value');
            $table->foreignId('changed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['compliance_program_id', 'category', 'version'], 'config_versions_program_category_version_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('program_configuration_versions');
    }
};
