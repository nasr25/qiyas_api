<?php

namespace App\Console\Commands;

use App\Models\AssessmentCycle;
use App\Models\ComplianceProgram;
use App\Models\Document;
use App\Models\ExtensionRequest;
use App\Models\ProgramUserRole;
use App\Models\Standard;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Read-only integrity report for the multi-program migration. Never writes
 * to the database. Run after `php artisan migrate` and
 * `php artisan compliance:migrate-qiyas` to confirm nothing was lost or
 * left orphaned.
 */
class VerifyComplianceMigration extends Command
{
    protected $signature = 'compliance:verify-migration';

    protected $description = 'Read-only report verifying the multi-program migration left no orphaned or unmigrated data.';

    public function handle(): int
    {
        $this->info('Compliance platform migration verification');
        $this->line('(read-only — no data is modified by this command)');
        $this->newLine();

        $programs = ComplianceProgram::count();
        $qiyas = ComplianceProgram::where('code', 'QIYAS')->first();

        $this->table(['Metric', 'Count'], [
            ['Compliance programs', $programs],
            ['Qiyas cycles (Program Cycles)', $qiyas ? AssessmentCycle::forProgram($qiyas)->count() : 0],
            ['Domains (perspectives, distinct)', $qiyas ? Standard::where('compliance_program_id', $qiyas->id)->whereNotNull('perspective')->distinct('perspective')->count('perspective') : 0],
            ['Categories (axes, distinct)', $qiyas ? Standard::where('compliance_program_id', $qiyas->id)->whereNotNull('axis')->distinct('axis')->count('axis') : 0],
            ['Requirements (standards)', $qiyas ? Standard::where('compliance_program_id', $qiyas->id)->count() : 0],
            ['Requirement assignments (department_standard)', DB::table('department_standard')->count()],
            ['Evidence submissions (documents)', $qiyas ? Document::where('compliance_program_id', $qiyas->id)->count() : 0],
            ['Extension requests', $qiyas ? ExtensionRequest::where('compliance_program_id', $qiyas->id)->count() : 0],
            ['Program user role assignments', $qiyas ? ProgramUserRole::where('compliance_program_id', $qiyas->id)->count() : 0],
        ]);

        $this->newLine();
        $this->comment('Orphan / integrity checks:');

        $orphanStandards = Standard::whereNull('compliance_program_id')->count();
        $orphanDocuments = Document::whereNull('compliance_program_id')->count();
        $orphanExtensions = ExtensionRequest::whereNull('compliance_program_id')->count();
        $orphanCycles = AssessmentCycle::whereNull('compliance_program_id')->count();

        $usersWithoutMapping = User::whereDoesntHave('roles')
            ->whereDoesntHave('programRoles')
            ->count();

        $documentsWithBadDepartment = DB::table('documents')
            ->leftJoin('departments', 'departments.id', '=', 'documents.department_id')
            ->whereNull('departments.id')
            ->count();

        $standardsWithBadCycle = DB::table('standards')
            ->leftJoin('assessment_cycles', 'assessment_cycles.id', '=', 'standards.cycle_id')
            ->whereNull('assessment_cycles.id')
            ->count();

        $checks = [
            ['Cycles missing compliance_program_id', $orphanCycles, $orphanCycles === 0],
            ['Standards missing compliance_program_id', $orphanStandards, $orphanStandards === 0],
            ['Documents missing compliance_program_id', $orphanDocuments, $orphanDocuments === 0],
            ['Extension requests missing compliance_program_id', $orphanExtensions, $orphanExtensions === 0],
            ['Documents with dangling department_id', $documentsWithBadDepartment, $documentsWithBadDepartment === 0],
            ['Standards with dangling cycle_id', $standardsWithBadCycle, $standardsWithBadCycle === 0],
            ['Users with no role at all (platform or program)', $usersWithoutMapping, $usersWithoutMapping === 0],
        ];

        foreach ($checks as [$label, $count, $ok]) {
            $icon = $ok ? '<fg=green>OK</>' : '<fg=red>FAIL</>';
            $this->line(sprintf('  [%s] %-55s %d', $icon, $label, $count));
        }

        $failed = collect($checks)->contains(fn ($c) => ! $c[2]);

        $this->newLine();
        if ($failed) {
            $this->error('One or more integrity checks failed. Review the counts above before proceeding.');

            return self::FAILURE;
        }

        $this->info('All integrity checks passed.');

        return self::SUCCESS;
    }
}
