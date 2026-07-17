<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per notification attempt. `idempotency_key` is unique — the
 * dispatching code always derives it deterministically from
 * (event_type, entity, recipient) so the same event can never queue twice
 * for the same recipient. See docs/email-notifications.md.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_logs', function (Blueprint $table) {
            $table->id();
            $table->string('template_key', 100);
            $table->string('event_type', 100);
            $table->string('idempotency_key', 255)->unique();
            $table->foreignId('recipient_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('recipient_email', 255);
            $table->foreignId('compliance_program_id')->nullable()->constrained('compliance_programs')->nullOnDelete();
            $table->foreignId('program_cycle_id')->nullable()->constrained('assessment_cycles')->nullOnDelete();
            $table->string('subject', 500);
            $table->enum('status', ['queued', 'sent', 'failed'])->default('queued');
            $table->text('error')->nullable();
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();

            $table->index(['recipient_user_id', 'status']);
            $table->index('event_type');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_logs');
    }
};
