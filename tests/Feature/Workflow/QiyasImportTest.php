<?php

namespace Tests\Feature\Workflow;

use App\Exports\Qiyas\QiyasRequirementsTemplateExport;
use App\Models\Standard;
use Illuminate\Http\UploadedFile;
use Maatwebsite\Excel\Facades\Excel;

class QiyasImportTest extends WorkflowTestCase
{
    public function test_official_template_downloads_successfully(): void
    {
        $this->getJson('/api/v1/programs/QIYAS/requirements-template', $this->authHeader($this->programManager))
            ->assertOk()
            ->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    }

    public function test_import_preview_does_not_save_any_data(): void
    {
        $path = $this->buildTemplateFile();
        $countBefore = Standard::where('compliance_program_id', $this->qiyas->id)->count();

        $this->postJson('/api/v1/programs/QIYAS/requirements-import/preview', [
            'file' => new UploadedFile($path, 'template.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true),
            'cycle_id' => $this->cycle->id,
        ], $this->authHeader($this->programManager))->assertOk();

        $this->assertEquals($countBefore, Standard::where('compliance_program_id', $this->qiyas->id)->count());
    }

    public function test_confirmed_import_creates_standards_transactionally(): void
    {
        $path = $this->buildTemplateFile();

        $preview = $this->postJson('/api/v1/programs/QIYAS/requirements-import/preview', [
            'file' => new UploadedFile($path, 'template.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true),
            'cycle_id' => $this->cycle->id,
        ], $this->authHeader($this->programManager))->assertOk()->json('data');

        $this->assertEquals(0, $preview['summary']['error_count']);

        $this->postJson("/api/v1/programs/QIYAS/requirements-import/{$preview['import_log_id']}/confirm", [], $this->authHeader($this->programManager))
            ->assertOk();

        $this->assertDatabaseHas('standards', ['cycle_id' => $this->cycle->id, 'standard_number' => 'STD-101']);
        $this->assertDatabaseHas('import_logs', ['id' => $preview['import_log_id'], 'status' => 'completed']);
    }

    public function test_wrong_program_template_is_rejected(): void
    {
        $export = new QiyasRequirementsTemplateExport('OTHER', $this->qiyas->id, $this->cycle->id);
        Excel::store($export, 'wrong.xlsx', 'local');
        $path = storage_path('app/private/wrong.xlsx');

        $result = $this->postJson('/api/v1/programs/QIYAS/requirements-import/preview', [
            'file' => new UploadedFile($path, 'wrong.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true),
            'cycle_id' => $this->cycle->id,
        ], $this->authHeader($this->programManager))->assertOk()->json('data');

        $this->assertGreaterThan(0, $result['summary']['error_count']);
        @unlink($path);
    }

    public function test_import_requires_program_manager_authorization(): void
    {
        $this->getJson('/api/v1/programs/QIYAS/requirements-template', $this->authHeader($this->deptAEmployee))
            ->assertStatus(403);
    }

    private function buildTemplateFile(): string
    {
        $export = new QiyasRequirementsTemplateExport($this->qiyas->code, $this->qiyas->id, $this->cycle->id);
        Excel::store($export, 'template.xlsx', 'local');

        return storage_path('app/private/template.xlsx');
    }
}
