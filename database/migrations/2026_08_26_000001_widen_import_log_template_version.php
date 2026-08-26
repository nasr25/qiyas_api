<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `template_version` was sized for the legacy `QIYAS-TEMPLATE-1.0` (18
 * chars). The structure-driven engine's identifier is longer, and a
 * program-specific prefix could be longer still, so the column is widened
 * rather than the identifier shortened — the identifier is a contract
 * checked byte-for-byte by the importer.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('import_logs', function (Blueprint $table) {
            $table->string('template_version', 60)->change();
        });
    }

    public function down(): void
    {
        Schema::table('import_logs', function (Blueprint $table) {
            $table->string('template_version', 20)->change();
        });
    }
};
