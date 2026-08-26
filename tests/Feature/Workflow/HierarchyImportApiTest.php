<?php

namespace Tests\Feature\Workflow;

use App\Exports\Hierarchy\HierarchyTemplateExport;
use App\Models\ComplianceNode;
use App\Models\ProgramUserRole;
use App\Services\HierarchyDefinitionService;
use Illuminate\Http\UploadedFile;
use Maatwebsite\Excel\Facades\Excel;

/**
 * API-level import behaviour, rewritten from the retired `QiyasImportTest`.
 *
 * Every guarantee the old test asserted is still supported and still
 * asserted here — template download, preview writes nothing, confirm is
 * transactional, wrong-program rejection, authorization — but against the
 * structure-driven engine and ComplianceNode instead of the legacy
 * Standard importer. See docs/testing/legacy-playwright-retirement.md for
 * the disposition record.
 */
class HierarchyImportApiTest extends WorkflowTestCase
{
    public function test_official_template_downloads_successfully(): void
    {
        $this->get('/api/v1/programs/QIYAS/hierarchy-template', $this->authHeader($this->programManager))
            ->assertOk()
            ->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    }

    public function test_import_preview_does_not_save_any_data(): void
    {
        $path = $this->buildTemplateFile();
        $before = ComplianceNode::where('compliance_program_id', $this->qiyas->id)->count();

        $this->postJson('/api/v1/programs/QIYAS/hierarchy-import/preview', [
            'file' => $this->upload($path),
            'cycle_id' => $this->cycle->id,
        ], $this->authHeader($this->programManager))->assertOk();

        $this->assertSame($before, ComplianceNode::where('compliance_program_id', $this->qiyas->id)->count(),
            'Preview must never write business data.');
    }

    public function test_confirmed_import_creates_nodes_transactionally(): void
    {
        $path = $this->buildTemplateFile();

        $preview = $this->postJson('/api/v1/programs/QIYAS/hierarchy-import/preview', [
            'file' => $this->upload($path),
            'cycle_id' => $this->cycle->id,
        ], $this->authHeader($this->programManager))->assertOk()->json('data');

        $this->assertSame(0, $preview['error_count']);
        $this->assertTrue($preview['can_import']);

        $this->postJson("/api/v1/programs/QIYAS/hierarchy-import/{$preview['import_log_id']}/confirm",
            [], $this->authHeader($this->programManager))->assertOk();

        // The sample row carries a full chain, so every level gets a node.
        $depth = count(app(HierarchyDefinitionService::class)->levels($this->qiyas));
        $this->assertDatabaseHas('import_logs', ['id' => $preview['import_log_id'], 'status' => 'completed']);
        $this->assertSame(
            $depth,
            ComplianceNode::where('compliance_program_id', $this->qiyas->id)
                ->where('program_cycle_id', $this->cycle->id)
                ->whereNotNull('hierarchy_level_id')
                ->count() - $this->preExistingNodeCount(),
            'One node per configured level must be created from the sample row.',
        );
    }

    public function test_a_template_generated_for_another_program_is_rejected(): void
    {
        // A structurally valid template belonging to a different program.
        $other = $this->otherProgram;
        $admin = $this->makeUser('super-admin');
        $this->activateStructure($other, [
            ['key' => 'level_1'],
            ['key' => 'level_2', 'is_assessable' => true],
        ], $admin);

        $structures = app(HierarchyDefinitionService::class);
        Excel::store(new HierarchyTemplateExport(
            $other,
            array_values(collect($structures->levels($other))->all()),
            $structures->currentStructureVersion($other),
            null,
        ), 'foreign.xlsx', 'local');

        $response = $this->postJson('/api/v1/programs/QIYAS/hierarchy-import/preview', [
            'file' => $this->upload(storage_path('app/private/foreign.xlsx')),
            'cycle_id' => $this->cycle->id,
        ], $this->authHeader($this->programManager))->assertOk();

        $this->assertFalse($response->json('data.can_import'));
        $this->assertContains('WRONG_PROGRAM', array_column($response->json('data.errors'), 'code'));
    }

    public function test_import_requires_program_manager_authorization(): void
    {
        $employee = $this->makeUser('employee', $this->deptA->id);
        ProgramUserRole::create([
            'compliance_program_id' => $this->qiyas->id, 'user_id' => $employee->id,
            'role_key' => 'employee', 'department_id' => $this->deptA->id, 'is_active' => true,
        ]);

        $this->postJson('/api/v1/programs/QIYAS/hierarchy-import/preview', [
            'file' => $this->upload($this->buildTemplateFile()),
            'cycle_id' => $this->cycle->id,
        ], $this->authHeader($employee))->assertForbidden();
    }

    public function test_confirming_twice_does_not_duplicate_nodes(): void
    {
        $path = $this->buildTemplateFile();

        $preview = $this->postJson('/api/v1/programs/QIYAS/hierarchy-import/preview', [
            'file' => $this->upload($path),
            'cycle_id' => $this->cycle->id,
        ], $this->authHeader($this->programManager))->assertOk()->json('data');

        $this->postJson("/api/v1/programs/QIYAS/hierarchy-import/{$preview['import_log_id']}/confirm",
            [], $this->authHeader($this->programManager))->assertOk();
        $afterFirst = ComplianceNode::where('compliance_program_id', $this->qiyas->id)->count();

        // Re-confirming the same log resolves the same nodes by identity
        // (program, cycle, level, code) and updates rather than duplicating.
        $this->postJson("/api/v1/programs/QIYAS/hierarchy-import/{$preview['import_log_id']}/confirm",
            [], $this->authHeader($this->programManager))->assertOk();

        $this->assertSame($afterFirst, ComplianceNode::where('compliance_program_id', $this->qiyas->id)->count());
    }

    // ─── Helpers ─────────────────────────────────────────────────────────────

    private function preExistingNodeCount(): int
    {
        // WorkflowTestCase seeds one node per level as the assignable fixture.
        return count(app(HierarchyDefinitionService::class)->levels($this->qiyas));
    }

    private function upload(string $path): UploadedFile
    {
        return new UploadedFile(
            $path, 'template.xlsx',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            null, true,
        );
    }

    /** The program's own current template, which is a valid import by construction. */
    private function buildTemplateFile(): string
    {
        $structures = app(HierarchyDefinitionService::class);

        Excel::store(new HierarchyTemplateExport(
            $this->qiyas,
            array_values(collect($structures->levels($this->qiyas))->all()),
            $structures->currentStructureVersion($this->qiyas),
            $this->cycle,
        ), 'import-template.xlsx', 'local');

        return storage_path('app/private/import-template.xlsx');
    }
}
