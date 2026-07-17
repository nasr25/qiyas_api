<?php

namespace Tests\Feature\Workflow;

use App\Services\ExtensionService;
use App\Services\WorkflowService;

/**
 * GET /programs/{program}/reviews/auditor/extension-requests crashed with a
 * SQL "Unknown column 'requested_at'" error on every call — the endpoint
 * ordered by a column the table doesn't have (the real column is
 * `requested_date`). No prior test called this listing endpoint directly
 * (only the approve/reject actions were tested), so this was never caught
 * until Phase 4 E2E testing exercised the real Auditor extension queue UI.
 */
class AuditorExtensionQueueTest extends WorkflowTestCase
{
    public function test_auditor_extension_queue_lists_pending_requests_without_error(): void
    {
        $assignment = app(WorkflowService::class)->assign(
            $this->requirement, $this->qiyas, $this->programManager, $this->deptA, null, now()->addDays(5)->toDateString(), null, null, null,
        );
        app(ExtensionService::class)->request($assignment, $this->deptAEmployee, now()->addDays(20)->toDateString(), 'Reason.');

        $response = $this->getJson('/api/v1/programs/QIYAS/reviews/auditor/extension-requests', $this->authHeader($this->auditor))
            ->assertOk();

        $this->assertCount(1, $response->json('data'));
    }
}
