<?php

namespace App\Console\Commands\Performance;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class LoadTestCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'performance:load-test
                            {--iterations=10 : Number of iterations per page}
                            {--warmup=2 : Number of warmup requests}
                            {--clear-cache : Clear cache before testing}
                            {--output= : Save report to file (json, csv, or txt)}
                            {--compare-cache : Run tests with cache cleared and warmed for comparison}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Run load tests across major pages and report performance metrics';

    private array $results = [];

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $iterations = (int) $this->option('iterations');
        $warmup = (int) $this->option('warmup');

        $this->info('╔════════════════════════════════════════════════════════╗');
        $this->info('║         TikrTracker Load Test Performance             ║');
        $this->info('╚════════════════════════════════════════════════════════╝');
        $this->newLine();

        // Check if we should compare cache performance
        if ($this->option('compare-cache')) {
            return $this->runCacheComparison($iterations, $warmup);
        }

        if ($this->option('clear-cache')) {
            $this->info('Clearing cache...');
            Cache::flush();
            $this->newLine();
        }

        // Get or create a test user
        $user = User::first();
        if (! $user) {
            $this->error('No users found in database. Please seed users first.');

            return Command::FAILURE;
        }

        // Define pages to test
        $pages = $this->getTestPages();

        $this->info('Test Configuration:');
        $this->line("  • Iterations: {$iterations}");
        $this->line("  • Warmup: {$warmup}");
        $this->line('  • Pages: '.count($pages));
        $this->newLine();

        // Test each page
        foreach ($pages as $page) {
            $this->testPage($page, $user, $iterations, $warmup);
        }

        // Display summary
        $this->displaySummary();

        // Save report if requested
        if ($outputFile = $this->option('output')) {
            $this->saveReport($outputFile);
        }

        return Command::SUCCESS;
    }

    private function runCacheComparison(int $iterations, int $warmup): int
    {
        $this->info('Running cache comparison test...');
        $this->newLine();

        $user = User::first();
        if (! $user) {
            $this->error('No users found in database.');

            return Command::FAILURE;
        }

        $pages = $this->getTestPages();

        // Test 1: Cache cleared
        $this->info('═══ Test 1: Cold Cache (Cleared) ═══');
        Cache::flush();
        $coldResults = [];
        foreach ($pages as $page) {
            $this->testPage($page, $user, $iterations, 0);
            $coldResults[] = end($this->results);
        }
        $this->newLine();

        // Test 2: Cache warmed
        $this->info('═══ Test 2: Warm Cache ═══');
        $this->call('market:warm-daily-prices-cache');
        $this->results = []; // Reset results
        $warmResults = [];
        foreach ($pages as $page) {
            $this->testPage($page, $user, $iterations, 0);
            $warmResults[] = end($this->results);
        }

        // Display comparison
        $this->displayCacheComparison($coldResults, $warmResults);

        return Command::SUCCESS;
    }

    private function getTestPages(): array
    {
        return [
            [
                'name' => 'Dashboard',
                'route' => '/dashboard',
                'method' => 'GET',
            ],
            [
                'name' => 'Daily Prices (Stock)',
                'route' => '/market-data/daily-prices?asset_type=stock',
                'method' => 'GET',
            ],
            [
                'name' => 'Daily Prices (Crypto)',
                'route' => '/market-data/daily-prices?asset_type=crypto',
                'method' => 'GET',
            ],
            [
                'name' => 'Hourly Prices (Stock)',
                'route' => '/market-data/hourly-prices?asset_type=stock',
                'method' => 'GET',
            ],
            [
                'name' => 'Assets List',
                'route' => '/market-data/assets',
                'method' => 'GET',
            ],
            [
                'name' => 'Deposits',
                'route' => '/deposits',
                'method' => 'GET',
            ],
            [
                'name' => 'Stock Transactions',
                'route' => '/stock-transactions',
                'method' => 'GET',
            ],
        ];
    }

    private function testPage(array $page, User $user, int $iterations, int $warmup): void
    {
        $this->info("Testing: {$page['name']}");
        $this->line("  Route: {$page['route']}");

        $times = [];
        $queryTimes = [];
        $queryCounts = [];
        $memorySizes = [];

        // Warmup requests
        if ($warmup > 0) {
            $this->line("  Warming up ({$warmup} requests)...");
            for ($i = 0; $i < $warmup; $i++) {
                $this->makeRequest($page['route'], $user);
            }
        }

        // Actual test iterations
        $progressBar = $this->output->createProgressBar($iterations);
        $progressBar->start();

        for ($i = 0; $i < $iterations; $i++) {
            $startTime = microtime(true);
            $startMemory = memory_get_usage();

            // Enable query logging
            DB::enableQueryLog();

            $response = $this->makeRequest($page['route'], $user);

            // Get query metrics
            $queries = DB::getQueryLog();
            $queryCount = count($queries);
            $queryTime = array_sum(array_column($queries, 'time'));

            DB::disableQueryLog();

            $endTime = microtime(true);
            $endMemory = memory_get_usage();

            $times[] = ($endTime - $startTime) * 1000; // Convert to milliseconds
            $queryTimes[] = $queryTime;
            $queryCounts[] = $queryCount;
            $memorySizes[] = ($endMemory - $startMemory) / 1024; // Convert to KB

            $progressBar->advance();
        }

        $progressBar->finish();
        $this->newLine();

        // Calculate statistics
        $stats = [
            'name' => $page['name'],
            'route' => $page['route'],
            'iterations' => $iterations,
            'response_time' => [
                'min' => min($times),
                'max' => max($times),
                'avg' => array_sum($times) / count($times),
                'median' => $this->median($times),
                'p95' => $this->percentile($times, 95),
                'p99' => $this->percentile($times, 99),
            ],
            'queries' => [
                'min' => min($queryCounts),
                'max' => max($queryCounts),
                'avg' => array_sum($queryCounts) / count($queryCounts),
            ],
            'query_time' => [
                'avg' => array_sum($queryTimes) / count($queryTimes),
            ],
            'memory' => [
                'avg' => array_sum($memorySizes) / count($memorySizes),
            ],
        ];

        $this->results[] = $stats;

        // Display individual results
        $this->line('  Results:');
        $this->line(sprintf('    Response Time: %.2fms (min) | %.2fms (avg) | %.2fms (median) | %.2fms (p95) | %.2fms (max)',
            $stats['response_time']['min'],
            $stats['response_time']['avg'],
            $stats['response_time']['median'],
            $stats['response_time']['p95'],
            $stats['response_time']['max']
        ));
        $this->line(sprintf('    Queries: %.0f (min) | %.1f (avg) | %.0f (max)',
            $stats['queries']['min'],
            $stats['queries']['avg'],
            $stats['queries']['max']
        ));
        $this->line(sprintf('    Query Time: %.2fms (avg)',
            $stats['query_time']['avg']
        ));
        $this->line(sprintf('    Memory Usage: %.2f KB (avg)',
            $stats['memory']['avg']
        ));

        // Performance grade
        $grade = $this->getPerformanceGrade($stats['response_time']['avg']);
        $gradeColor = $this->getGradeColor($grade);
        $this->line("    Grade: <{$gradeColor}>{$grade}</>");

        $this->newLine();
    }

    private function makeRequest(string $route, User $user)
    {
        return $this->actingAs($user)->get($route);
    }

    private function actingAs(User $user)
    {
        auth()->login($user);

        return $this;
    }

    private function get(string $route)
    {
        // Simulate HTTP request using Laravel's testing helpers
        $request = \Illuminate\Http\Request::create($route, 'GET');
        $request->setUserResolver(fn () => auth()->user());

        return app()->handle($request);
    }

    private function median(array $values): float
    {
        sort($values);
        $count = count($values);
        $middle = floor($count / 2);

        if ($count % 2 == 0) {
            return ($values[$middle - 1] + $values[$middle]) / 2;
        }

        return $values[$middle];
    }

    private function percentile(array $values, int $percentile): float
    {
        sort($values);
        $index = ceil((count($values) * $percentile) / 100) - 1;

        return $values[$index];
    }

    private function getPerformanceGrade(float $avgTime): string
    {
        if ($avgTime < 50) {
            return 'A+ (Excellent)';
        }
        if ($avgTime < 100) {
            return 'A (Very Good)';
        }
        if ($avgTime < 200) {
            return 'B (Good)';
        }
        if ($avgTime < 500) {
            return 'C (Acceptable)';
        }
        if ($avgTime < 1000) {
            return 'D (Needs Improvement)';
        }

        return 'F (Poor)';
    }

    private function getGradeColor(string $grade): string
    {
        if (str_starts_with($grade, 'A')) {
            return 'fg=green';
        }
        if (str_starts_with($grade, 'B')) {
            return 'fg=cyan';
        }
        if (str_starts_with($grade, 'C')) {
            return 'fg=yellow';
        }

        return 'fg=red';
    }

    private function displaySummary(): void
    {
        $this->info('╔════════════════════════════════════════════════════════╗');
        $this->info('║                   SUMMARY REPORT                       ║');
        $this->info('╚════════════════════════════════════════════════════════╝');
        $this->newLine();

        // Create summary table
        $headers = ['Page', 'Avg Time', 'P95', 'Queries', 'Grade'];
        $rows = [];

        foreach ($this->results as $result) {
            $grade = $this->getPerformanceGrade($result['response_time']['avg']);
            $rows[] = [
                $result['name'],
                sprintf('%.2fms', $result['response_time']['avg']),
                sprintf('%.2fms', $result['response_time']['p95']),
                sprintf('%.1f', $result['queries']['avg']),
                $grade,
            ];
        }

        $this->table($headers, $rows);

        // Overall statistics
        $totalAvgTime = array_sum(array_column(array_column($this->results, 'response_time'), 'avg')) / count($this->results);
        $totalAvgQueries = array_sum(array_column(array_column($this->results, 'queries'), 'avg')) / count($this->results);

        $this->newLine();
        $this->info('Overall Statistics:');
        $this->line(sprintf('  Average Response Time: %.2fms', $totalAvgTime));
        $this->line(sprintf('  Average Queries per Page: %.1f', $totalAvgQueries));

        // Performance recommendations
        $this->newLine();
        $this->info('Recommendations:');
        foreach ($this->results as $result) {
            if ($result['response_time']['avg'] > 500) {
                $this->warn("  • {$result['name']}: Consider adding more caching (avg: {$result['response_time']['avg']}ms)");
            }
            if ($result['queries']['avg'] > 20) {
                $this->warn("  • {$result['name']}: High query count (avg: {$result['queries']['avg']} queries) - consider eager loading");
            }
        }

        $this->newLine();
        $this->info('Test completed successfully!');
    }

    private function displayCacheComparison(array $coldResults, array $warmResults): void
    {
        $this->newLine();
        $this->info('╔════════════════════════════════════════════════════════╗');
        $this->info('║              CACHE COMPARISON REPORT                   ║');
        $this->info('╚════════════════════════════════════════════════════════╝');
        $this->newLine();

        $headers = ['Page', 'Cold Cache', 'Warm Cache', 'Improvement'];
        $rows = [];

        foreach ($coldResults as $index => $coldResult) {
            $warmResult = $warmResults[$index];
            $coldTime = $coldResult['response_time']['avg'];
            $warmTime = $warmResult['response_time']['avg'];
            $improvement = (($coldTime - $warmTime) / $coldTime) * 100;

            $rows[] = [
                $coldResult['name'],
                sprintf('%.2fms', $coldTime),
                sprintf('%.2fms', $warmTime),
                sprintf('%.1f%%', $improvement),
            ];
        }

        $this->table($headers, $rows);

        // Calculate average improvement
        $totalColdTime = array_sum(array_column(array_column($coldResults, 'response_time'), 'avg'));
        $totalWarmTime = array_sum(array_column(array_column($warmResults, 'response_time'), 'avg'));
        $avgImprovement = (($totalColdTime - $totalWarmTime) / $totalColdTime) * 100;

        $this->newLine();
        $this->info("Average Performance Improvement: <fg=green>{$avgImprovement}%</>");
        $this->newLine();
    }

    private function saveReport(string $filename): void
    {
        $extension = pathinfo($filename, PATHINFO_EXTENSION);
        $filepath = storage_path("app/performance/{$filename}");

        // Ensure directory exists
        if (! is_dir(dirname($filepath))) {
            mkdir(dirname($filepath), 0755, true);
        }

        switch ($extension) {
            case 'json':
                $this->saveJsonReport($filepath);
                break;
            case 'csv':
                $this->saveCsvReport($filepath);
                break;
            case 'txt':
            default:
                $this->saveTextReport($filepath);
                break;
        }

        $this->info("Report saved to: {$filepath}");
    }

    private function saveJsonReport(string $filepath): void
    {
        $report = [
            'timestamp' => now()->toIso8601String(),
            'results' => $this->results,
            'summary' => [
                'average_response_time' => array_sum(array_column(array_column($this->results, 'response_time'), 'avg')) / count($this->results),
                'average_queries' => array_sum(array_column(array_column($this->results, 'queries'), 'avg')) / count($this->results),
                'total_pages_tested' => count($this->results),
            ],
        ];

        file_put_contents($filepath, json_encode($report, JSON_PRETTY_PRINT));
    }

    private function saveCsvReport(string $filepath): void
    {
        $fp = fopen($filepath, 'w');

        // Headers
        fputcsv($fp, ['Page', 'Avg Response (ms)', 'Min (ms)', 'Max (ms)', 'P95 (ms)', 'Avg Queries', 'Grade']);

        // Data
        foreach ($this->results as $result) {
            $grade = $this->getPerformanceGrade($result['response_time']['avg']);
            fputcsv($fp, [
                $result['name'],
                round($result['response_time']['avg'], 2),
                round($result['response_time']['min'], 2),
                round($result['response_time']['max'], 2),
                round($result['response_time']['p95'], 2),
                round($result['queries']['avg'], 1),
                $grade,
            ]);
        }

        fclose($fp);
    }

    private function saveTextReport(string $filepath): void
    {
        $report = "TikrTracker Performance Report\n";
        $report .= 'Generated: '.now()->toDateTimeString()."\n";
        $report .= str_repeat('=', 60)."\n\n";

        foreach ($this->results as $result) {
            $report .= "{$result['name']}\n";
            $report .= str_repeat('-', 60)."\n";
            $report .= sprintf("  Avg Response: %.2fms\n", $result['response_time']['avg']);
            $report .= sprintf("  P95: %.2fms\n", $result['response_time']['p95']);
            $report .= sprintf("  Avg Queries: %.1f\n", $result['queries']['avg']);
            $report .= sprintf("  Grade: %s\n", $this->getPerformanceGrade($result['response_time']['avg']));
            $report .= "\n";
        }

        file_put_contents($filepath, $report);
    }
}
