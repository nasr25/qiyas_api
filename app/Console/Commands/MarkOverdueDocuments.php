<?php

namespace App\Console\Commands;

use App\Models\Document;
use App\Models\Standard;
use Illuminate\Console\Command;

/**
 * Marks documents as overdue if their standard's due date has passed
 * and the document is still in draft or under_review status.
 */
class MarkOverdueDocuments extends Command
{
    protected $signature = 'qiyas:mark-overdue';

    protected $description = 'Mark documents as overdue based on standard due dates';

    public function handle(): int
    {
        $count = 0;

        // Find standards with past due dates that have non-completed documents
        Standard::whereNotNull('due_date')
            ->where('due_date', '<', today())
            ->where('is_active', true)
            ->with('evidenceRequirements')
            ->each(function (Standard $standard) use (&$count) {
                $requirementIds = $standard->evidenceRequirements->pluck('id');

                $updated = Document::whereIn('requirement_id', $requirementIds)
                    ->whereIn('status', ['draft', 'under_review'])
                    ->update(['status' => 'overdue']);

                $count += $updated;
            });

        $this->info("Marked {$count} documents as overdue.");

        return self::SUCCESS;
    }
}
