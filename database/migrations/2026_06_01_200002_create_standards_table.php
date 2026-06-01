<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('standards', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cycle_id')->constrained('assessment_cycles')->cascadeOnDelete();
            $table->string('standard_number', 50);
            $table->string('name_ar', 500);
            $table->string('name_en', 500);
            $table->text('description')->nullable();
            $table->string('version', 20)->nullable();
            $table->decimal('weight', 5, 2)->nullable();
            $table->date('due_date')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['cycle_id', 'standard_number']);
        });

        Schema::create('department_standard', function (Blueprint $table) {
            $table->id();
            $table->foreignId('department_id')->constrained()->cascadeOnDelete();
            $table->foreignId('standard_id')->constrained()->cascadeOnDelete();
            $table->timestamp('assigned_at')->useCurrent();
            $table->foreignId('assigned_by')->constrained('users');

            $table->unique(['department_id', 'standard_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('department_standard');
        Schema::dropIfExists('standards');
    }
};
