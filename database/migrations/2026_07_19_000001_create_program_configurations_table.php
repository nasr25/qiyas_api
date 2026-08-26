<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per (program, category) — the current, active configuration for
 * that category. See app/Services/ProgramConfigurationService.php and
 * docs/program-configuration.md. History of every prior value lives in
 * program_configuration_versions, never overwritten here.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('program_configurations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('compliance_program_id')->constrained('compliance_programs')->cascadeOnDelete();
            $table->string('category', 50);
            $table->json('value');
            $table->unsignedInteger('version')->default(1);
            $table->boolean('is_active')->default(true);
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['compliance_program_id', 'category']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('program_configurations');
    }
};
