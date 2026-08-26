<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

/**
 * Single-program form of `compliance:verify-hierarchy`, provided because
 * the Phase B brief names both entry points. Read-only; delegates rather
 * than duplicating any check, so the two commands can never drift.
 *
 *   php artisan compliance:verify-program-structure NDMO
 */
class VerifyProgramStructure extends Command
{
    protected $signature = 'compliance:verify-program-structure {programCode : The compliance program code, e.g. QIYAS}';

    protected $description = 'Read-only integrity report for one program hierarchy structure.';

    public function handle(): int
    {
        return $this->call('compliance:verify-hierarchy', [
            '--program' => $this->argument('programCode'),
        ]);
    }
}
