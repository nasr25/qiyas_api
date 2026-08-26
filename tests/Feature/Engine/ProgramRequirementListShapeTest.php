<?php

namespace Tests\Feature\Engine;

use Tests\Feature\Workflow\WorkflowTestCase;

/**
 * GET /programs/{program}/requirements returned a nested paginator object
 * instead of a flat array (the same defect class fixed elsewhere in
 * Phase 2/3 — see docs/qiyas-workflow.md §6 — but missed on this
 * controller), which silently broke the Requirement Assignment form's
 * dropdown (it appeared empty because Vue iterated the paginator object's
 * own keys instead of the requirement rows). Discovered via Phase 4 E2E
 * testing, not previously covered by any test.
 */
class ProgramRequirementListShapeTest extends WorkflowTestCase
{
    public function test_requirement_list_returns_a_flat_data_array(): void
    {
        $response = $this->getJson('/api/v1/programs/QIYAS/requirements?per_page=5', $this->authHeader($this->programManager))
            ->assertOk();

        $data = $response->json('data');
        $this->assertIsArray($data);
        $this->assertArrayNotHasKey('current_page', $data);
        if (count($data)) {
            $this->assertArrayHasKey('number', $data[0]);
        }
    }
}
