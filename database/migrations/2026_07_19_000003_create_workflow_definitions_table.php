<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One active workflow definition per program — the review process a
 * requirement's evidence submission goes through. See
 * app/Services/WorkflowDefinitionRepository.php and docs/workflow-engine.md.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workflow_definitions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('compliance_program_id')->constrained('compliance_programs')->cascadeOnDelete();
            $table->string('key', 50)->default('requirement_review');
            $table->string('name_ar', 150);
            $table->string('name_en', 150);
            $table->unsignedInteger('version')->default(1);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['compliance_program_id', 'key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workflow_definitions');
    }
};
