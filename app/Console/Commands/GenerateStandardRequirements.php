<?php

namespace App\Console\Commands;

use App\Models\EvidenceRequirement;
use App\Models\Standard;
use Illuminate\Console\Command;

/**
 * Creates one evidence requirement for every standard that has none, using the
 * standard's `evidence_documents` (مستندات الإثبات) text as the description.
 * This gives imported catalog standards an "upload task" employees can fill.
 *
 * Idempotent: standards that already have requirements are skipped.
 */
class GenerateStandardRequirements extends Command
{
    protected $signature = 'qiyas:generate-requirements {--cycle= : Limit to standards in this cycle id}';

    protected $description = 'Generate a default evidence requirement for standards that have none.';

    public function handle(): int
    {
        $created = 0;

        Standard::doesntHave('evidenceRequirements')
            ->when($this->option('cycle'), fn ($q) => $q->where('cycle_id', $this->option('cycle')))
            ->chunkById(100, function ($standards) use (&$created) {
                foreach ($standards as $standard) {
                    EvidenceRequirement::create([
                        'standard_id' => $standard->id,
                        'title_ar' => 'مستندات الإثبات',
                        'title_en' => 'Evidence Documents',
                        'description' => $standard->evidence_documents,
                        'is_mandatory' => true,
                        'sort_order' => 1,
                    ]);
                    $created++;
                }
            });

        $this->info("Created {$created} evidence requirement(s).");

        return self::SUCCESS;
    }
}
