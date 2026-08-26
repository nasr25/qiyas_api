<?php

namespace App\Console\Commands;

use App\Exports\Hierarchy\HierarchyNodesExport;
use App\Exports\Hierarchy\HierarchyTemplateExport;
use App\Models\AssessmentCycle;
use App\Models\ComplianceNode;
use App\Models\ComplianceProgram;
use App\Services\HierarchyDashboardService;
use App\Services\HierarchyDefinitionService;
use App\Services\HierarchyImportValidator;
use App\Services\HierarchyReportService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Facades\Excel;

/**
 * Measures the dynamic hierarchy engine against the performance fixture.
 *
 * Read-only with respect to business data. For each operation it records
 * wall time across N iterations (P50/P95/P99), the SQL query count, the
 * slowest single statement, and peak memory — so a performance claim can
 * cite numbers rather than impressions.
 *
 *   php artisan compliance:measure-performance --env=perf
 */
class MeasureHierarchyPerformance extends Command
{
    protected $signature = 'compliance:measure-performance
                            {--iterations=15 : Samples per operation}
                            {--program= : Limit to one program code}
                            {--plans : Also print EXPLAIN for the heaviest query of each operation}';

    protected $description = 'Measure hierarchy/dashboard/report/XLSX performance against the seeded fixture.';

    private array $results = [];

    public function handle(
        HierarchyDefinitionService $structures,
        HierarchyDashboardService $dashboard,
        HierarchyReportService $reports,
    ): int {
        $iterations = max(3, (int) $this->option('iterations'));

        $codes = $this->option('program')
            ? [strtoupper($this->option('program'))]
            : ['PERF3', 'PERF5', 'PERF7'];

        $programs = ComplianceProgram::whereIn('code', $codes)->get();
        if ($programs->isEmpty()) {
            $this->error('No performance fixture programs found. Run PerformanceFixtureSeeder first.');

            return self::FAILURE;
        }

        $this->info('Hierarchy performance measurement');
        $this->line(sprintf('Dataset: %s compliance nodes, %s assignments, %s evidence submissions',
            number_format(ComplianceNode::count()),
            number_format(DB::table('requirement_assignments')->count()),
            number_format(DB::table('evidence_submissions')->count()),
        ));
        $this->line("Samples per operation: {$iterations}");
        $this->newLine();

        foreach ($programs as $program) {
            $depth = count($structures->levels($program));
            $nodeCount = ComplianceNode::where('compliance_program_id', $program->id)->count();
            $this->comment("── {$program->code} ({$depth} levels, ".number_format($nodeCount).' nodes)');

            $cycle = AssessmentCycle::where('compliance_program_id', $program->id)->where('is_current', true)->first();
            $levels = collect($structures->levels($program))->values();

            $root = ComplianceNode::where('compliance_program_id', $program->id)->whereNull('parent_id')->first();
            $deepest = ComplianceNode::where('compliance_program_id', $program->id)
                ->where('hierarchy_level_id', $levels->last()->id)->first();

            $this->measure($program->code, 'hierarchy: root load', $iterations, fn () => ComplianceNode::where('compliance_program_id', $program->id)
                ->whereNull('parent_id')->withCount('children')->orderBy('sort_order')->get());

            $this->measure($program->code, 'hierarchy: child load', $iterations, fn () => ComplianceNode::where('compliance_program_id', $program->id)
                ->where('parent_id', $root?->id)->withCount('children')->orderBy('sort_order')->get());

            $this->measure($program->code, "breadcrumb: {$depth}-level path", $iterations, fn () => $deepest?->fresh()->breadcrumb());

            $this->measure($program->code, 'subtree ids (recursive CTE)', $iterations, fn () => ComplianceNode::subtreeIds($root?->id ?? 0));

            $this->measure($program->code, 'cascading filter (full chain)', $iterations, function () use ($program, $reports, $levels, $cycle) {
                $parent = null;
                foreach ($levels as $level) {
                    $options = $reports->filterOptions($program, $level, $parent, $cycle);
                    $parent = $options[0]['id'] ?? null;
                }
            });

            $this->measure($program->code, 'search: code/name contains', $iterations, fn () => ComplianceNode::where('compliance_program_id', $program->id)
                ->where(fn ($q) => $q->where('code', 'like', '%.1.1%')->orWhere('name_ar', 'like', '%عقدة%'))
                ->limit(50)->get());

            $this->measure($program->code, 'dashboard: universal metrics', $iterations, fn () => $dashboard->universalMetrics($program, $cycle));

            $this->measure($program->code, 'dashboard: drill-down (level 1)', $iterations, fn () => $dashboard->groupByLevel($program, $levels[0], $cycle));

            $this->measure($program->code, 'report: no grouping', $iterations, fn () => $reports->build($program, [], [], $cycle));

            for ($dimensions = 1; $dimensions <= min(4, $levels->count()); $dimensions++) {
                $keys = $levels->take($dimensions)->pluck('key')->all();
                $this->measure($program->code, "report: {$dimensions}-dimension grouping", $iterations,
                    fn () => $reports->build($program, $keys, [], $cycle));
            }

            $this->measure($program->code, 'xlsx: template generation', max(3, (int) ($iterations / 3)), function () use ($program, $levels, $structures, $cycle) {
                Excel::raw(new HierarchyTemplateExport($program, $levels->all(), $structures->currentStructureVersion($program), $cycle), \Maatwebsite\Excel\Excel::XLSX);
            });

            $this->measure($program->code, 'xlsx: hierarchy export', max(3, (int) ($iterations / 3)), function () use ($program, $levels, $cycle) {
                Excel::raw(new HierarchyNodesExport($program, $levels->all(), $cycle?->id), \Maatwebsite\Excel\Excel::XLSX);
            });

            $this->measureImportValidation($program, $levels->all(), $structures, $cycle, max(3, (int) ($iterations / 3)));

            $this->newLine();
        }

        $this->renderTable();

        return self::SUCCESS;
    }

    private function measureImportValidation(ComplianceProgram $program, array $levels, HierarchyDefinitionService $structures, ?AssessmentCycle $cycle, int $iterations): void
    {
        $path = tempnam(sys_get_temp_dir(), 'perf').'.xlsx';
        file_put_contents($path, Excel::raw(
            new HierarchyTemplateExport($program, $levels, $structures->currentStructureVersion($program), $cycle),
            \Maatwebsite\Excel\Excel::XLSX,
        ));

        $validator = app(HierarchyImportValidator::class);
        $this->measure($program->code, 'xlsx: import validation', $iterations, fn () => $validator->validate($path, $program));

        @unlink($path);
    }

    /** Runs $operation $iterations times, recording time, queries and memory. */
    private function measure(string $programCode, string $label, int $iterations, callable $operation): void
    {
        // One untimed warm-up so the first sample does not carry autoload and
        // connection setup that a real request would not repeat.
        $operation();

        $timings = [];
        $queryCount = 0;
        $slowest = ['time' => 0.0, 'sql' => ''];
        $peakBefore = memory_get_peak_usage(true);

        for ($i = 0; $i < $iterations; $i++) {
            DB::flushQueryLog();
            DB::enableQueryLog();

            $start = hrtime(true);
            $operation();
            $timings[] = (hrtime(true) - $start) / 1_000_000; // ms

            $log = DB::getQueryLog();
            $queryCount = count($log);
            foreach ($log as $query) {
                if ($query['time'] > $slowest['time']) {
                    $slowest = ['time' => $query['time'], 'sql' => $query['query']];
                }
            }
            DB::disableQueryLog();
        }

        sort($timings);
        $this->results[] = [
            'program' => $programCode,
            'operation' => $label,
            'p50' => $this->percentile($timings, 50),
            'p95' => $this->percentile($timings, 95),
            'p99' => $this->percentile($timings, 99),
            'max' => end($timings),
            'queries' => $queryCount,
            'slowest_sql_ms' => $slowest['time'],
            'slowest_sql' => $slowest['sql'],
            'peak_mb' => round((memory_get_peak_usage(true) - $peakBefore) / 1048576, 1),
        ];

        $latest = end($this->results);
        $this->line(sprintf('   %-34s p50 %7.1fms  p95 %7.1fms  queries %4d',
            $label, $latest['p50'], $latest['p95'], $latest['queries']));
    }

    private function percentile(array $sorted, int $percentile): float
    {
        if (! $sorted) {
            return 0.0;
        }
        $index = (int) ceil(($percentile / 100) * count($sorted)) - 1;

        return round($sorted[max(0, min($index, count($sorted) - 1))], 2);
    }

    private function renderTable(): void
    {
        $this->comment('Summary (times in milliseconds)');
        $this->table(
            ['Program', 'Operation', 'P50', 'P95', 'P99', 'Max', 'Queries', 'Slowest SQL', 'Peak MB'],
            array_map(fn ($r) => [
                $r['program'], $r['operation'],
                number_format($r['p50'], 1), number_format($r['p95'], 1),
                number_format($r['p99'], 1), number_format($r['max'], 1),
                $r['queries'], number_format($r['slowest_sql_ms'], 1), $r['peak_mb'],
            ], $this->results),
        );

        $this->newLine();
        $this->comment('Peak process memory: '.round(memory_get_peak_usage(true) / 1048576, 1).' MB');

        if ($this->option('plans')) {
            $this->renderPlans();
        }
    }

    private function renderPlans(): void
    {
        $this->newLine();
        $this->comment('Execution plans for the heaviest statement of the slowest operations');

        $slowest = collect($this->results)->sortByDesc('p95')->take(4);
        foreach ($slowest as $result) {
            if (! $result['slowest_sql'] || ! str_starts_with(strtolower(trim($result['slowest_sql'])), 'select')) {
                continue;
            }
            $this->line("  {$result['program']} — {$result['operation']}");
            $this->line('  '.substr($result['slowest_sql'], 0, 160));
            try {
                // Bindings are unknown here, so NULL placeholders are used;
                // the plan shape (index vs full scan) is what matters.
                $sql = preg_replace('/\?/', 'NULL', $result['slowest_sql']);
                foreach (DB::select('EXPLAIN '.$sql) as $row) {
                    $r = (array) $row;
                    $this->line(sprintf('    table=%s type=%s key=%s rows=%s Extra=%s',
                        $r['table'] ?? '-', $r['type'] ?? '-', $r['key'] ?? 'NONE', $r['rows'] ?? '-', $r['Extra'] ?? ''));
                }
            } catch (\Throwable $e) {
                $this->line('    (plan unavailable: '.substr($e->getMessage(), 0, 80).')');
            }
            $this->newLine();
        }
    }
}
