<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Platform-wide, managed by Super Admin only. Not program-scoped: the same
 * template key is reused across programs (event_type is generic), with
 * {{program_name}}/{{cycle_name}} variables carrying program context into
 * the rendered text. See docs/email-notifications.md.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('email_templates', function (Blueprint $table) {
            $table->id();
            $table->string('template_key', 100)->unique();
            $table->string('event_type', 100);
            $table->string('subject_ar', 500);
            $table->string('subject_en', 500);
            $table->longText('body_ar');
            $table->longText('body_en');
            $table->boolean('is_enabled')->default(true);
            $table->json('supported_variables')->nullable();
            $table->json('default_recipient_rules')->nullable();
            $table->json('cc_rules')->nullable();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index('event_type');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('email_templates');
    }
};
