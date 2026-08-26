<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('import_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('compliance_program_id')->constrained('compliance_programs')->restrictOnDelete();
            $table->foreignId('program_cycle_id')->nullable()->constrained('assessment_cycles')->nullOnDelete();
            $table->foreignId('imported_by')->constrained('users')->restrictOnDelete();
            $table->string('original_file_name', 500);
            $table->string('stored_file_name', 255);
            $table->string('file_hash', 64);
            $table->string('template_version', 20);
            $table->enum('mode', ['create', 'update'])->default('create');
            $table->enum('status', [
                'uploaded', 'validating', 'validation_failed', 'ready_for_confirmation',
                'importing', 'completed', 'failed', 'cancelled',
            ])->default('uploaded');
            $table->unsignedInteger('total_rows')->default(0);
            $table->unsignedInteger('valid_rows')->default(0);
            $table->unsignedInteger('invalid_rows')->default(0);
            $table->unsignedInteger('created_records')->default(0);
            $table->unsignedInteger('updated_records')->default(0);
            $table->unsignedInteger('warning_count')->default(0);
            $table->unsignedInteger('error_count')->default(0);
            $table->string('validation_report_path', 1000)->nullable();
            $table->timestamp('started_at');
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['compliance_program_id', 'status']);
            $table->index('imported_by');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('import_logs');
    }
};
