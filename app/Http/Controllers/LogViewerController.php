<?php

namespace App\Http\Controllers;

use App\Models\CpuTemperatureReading;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Inertia\Inertia;

class LogViewerController extends Controller
{
    public function laravel()
    {
        return Inertia::render('Logs/Laravel');
    }

    public function scheduler()
    {
        return Inertia::render('Logs/Scheduler');
    }

    public function htop()
    {
        return Inertia::render('Logs/Htop');
    }

    public function cpuTemp(): \Inertia\Response
    {
        return Inertia::render('Logs/CpuTemp');
    }

    public function getCpuTempOutput(Request $request): \Illuminate\Http\JsonResponse
    {
        $command = 'sensors 2>&1';
        exec($command, $output, $returnCode);

        $rawContent = implode("\n", $output);
        $sensorData = $this->parseSensorsOutput($output);
        $this->persistCpuTemperatureReadings($sensorData['temperatures']);

        return response()->json([
            'content' => $rawContent,
            'sections' => $sensorData['sections'],
            'temperatures' => $sensorData['temperatures'],
            'fans' => $sensorData['fans'],
            'summary' => $sensorData['summary'],
            'success' => $returnCode === 0,
            'lastUpdated' => now('America/New_York')->toDateTimeString(),
        ]);
    }

    public function tempChart(): \Inertia\Response
    {
        return Inertia::render('Logs/TempChart');
    }

    public function getTempChartData(Request $request): \Illuminate\Http\JsonResponse
    {
        $since = now('America/New_York')->subHours(8);

        $readings = CpuTemperatureReading::query()
            ->where('refreshed_at', '>=', $since)
            ->orderBy('refreshed_at')
            ->get(['refreshed_at', 'temperature_celsius']);

        $maxByTime = $readings->groupBy(fn ($r) => $r->getRawOriginal('refreshed_at'))
            ->map(function ($group) {
                $time = \Illuminate\Support\Carbon::parse(
                    $group->first()->getRawOriginal('refreshed_at'),
                    'America/New_York'
                );

                return [
                    'time' => $time->toIso8601String(),
                    'temperature' => (float) $group->max('temperature_celsius'),
                ];
            })
            ->values()
            ->toArray();

        return response()->json([
            'series' => [
                [
                    'name' => 'Max Temperature',
                    'data' => $maxByTime,
                ],
            ],
            'success' => true,
        ]);
    }

    /**
     * @return array{
     *     sections: array<int, array{
     *         name: string,
     *         adapter: string|null,
     *         temperature_readings: array<int, array{section: string, label: string, value: float, raw: string}>,
     *         fan_readings: array<int, array{section: string, label: string, value: int, raw: string}>,
     *         other_readings: array<int, array{label: string, value: string}>
     *     }>,
     *     temperatures: array<int, array{section: string, label: string, value: float, raw: string}>,
     *     fans: array<int, array{section: string, label: string, value: int, raw: string}>,
     *     summary: array{highest_temperature: float|null, average_temperature: float|null, temperature_count: int, fan_count: int}
     * }
     */
    private function parseSensorsOutput(array $output): array
    {
        $sections = [];
        $temperatures = [];
        $fans = [];
        $currentSectionIndex = null;

        foreach ($output as $line) {
            $trimmedLine = trim($line);

            if ($trimmedLine === '') {
                continue;
            }

            $isReadingLine = preg_match('/^[^:]+:\s+.+$/', $trimmedLine) === 1;

            if (! str_starts_with($line, ' ') && ! str_starts_with($line, "\t") && ! str_starts_with($trimmedLine, 'Adapter:') && ! $isReadingLine) {
                $sections[] = [
                    'name' => $trimmedLine,
                    'adapter' => null,
                    'temperature_readings' => [],
                    'fan_readings' => [],
                    'other_readings' => [],
                ];
                $currentSectionIndex = count($sections) - 1;

                continue;
            }

            if ($currentSectionIndex === null) {
                continue;
            }

            if (str_starts_with($trimmedLine, 'Adapter:')) {
                $sections[$currentSectionIndex]['adapter'] = trim(substr($trimmedLine, strlen('Adapter:')));

                continue;
            }

            if (preg_match('/^([^:]+):\s+([+-]?\d+(?:\.\d+)?)(?:\s*°\s*|\s*)C\b/i', $trimmedLine, $matches)) {
                $reading = [
                    'section' => $sections[$currentSectionIndex]['name'],
                    'label' => trim($matches[1]),
                    'value' => (float) $matches[2],
                    'raw' => $trimmedLine,
                ];

                $sections[$currentSectionIndex]['temperature_readings'][] = $reading;
                $temperatures[] = $reading;

                continue;
            }

            if (preg_match('/^([^:]+):\s+(\d+)\s+RPM/i', $trimmedLine, $matches)) {
                $reading = [
                    'section' => $sections[$currentSectionIndex]['name'],
                    'label' => trim($matches[1]),
                    'value' => (int) $matches[2],
                    'raw' => $trimmedLine,
                ];

                $sections[$currentSectionIndex]['fan_readings'][] = $reading;
                $fans[] = $reading;

                continue;
            }

            if (preg_match('/^([^:]+):\s+(.+)$/', $trimmedLine, $matches)) {
                $sections[$currentSectionIndex]['other_readings'][] = [
                    'label' => trim($matches[1]),
                    'value' => trim($matches[2]),
                ];
            }
        }

        $temperatureValues = array_map(static fn (array $reading): float => (float) $reading['value'], $temperatures);

        return [
            'sections' => $sections,
            'temperatures' => $temperatures,
            'fans' => $fans,
            'summary' => [
                'highest_temperature' => empty($temperatureValues) ? null : round(max($temperatureValues), 1),
                'average_temperature' => empty($temperatureValues) ? null : round(array_sum($temperatureValues) / count($temperatureValues), 1),
                'temperature_count' => count($temperatures),
                'fan_count' => count($fans),
            ],
        ];
    }

    /**
     * @param  array<int, array{section: string, label: string, value: float, raw: string}>  $temperatures
     */
    private function persistCpuTemperatureReadings(array $temperatures): void
    {
        if ($temperatures === []) {
            return;
        }

        $refreshedAt = now('America/New_York');
        $rows = array_map(static function (array $reading) use ($refreshedAt): array {
            return [
                'refreshed_at' => $refreshedAt,
                'sensor_section' => $reading['section'],
                'sensor_label' => $reading['label'],
                'temperature_celsius' => $reading['value'],
                'raw_reading' => $reading['raw'],
                'created_at' => $refreshedAt,
                'updated_at' => $refreshedAt,
            ];
        }, $temperatures);

        CpuTemperatureReading::query()->insert($rows);
    }

    public function continuousBacktest()
    {
        return Inertia::render('Logs/ContinuousBT');
    }

    public function streaming()
    {
        return Inertia::render('Logs/Streaming');
    }

    public function staleEntries()
    {
        return Inertia::render('Logs/StaleEntries');
    }

    public function getStaleEntriesLog(Request $request): \Illuminate\Http\JsonResponse
    {
        $date = now('America/New_York')->format('Y-m-d');
        $lines = (int) $request->get('lines', 500);
        $resolvedLog = $this->resolveDailyLogFile('stale-alerts', $date);

        if (! $resolvedLog) {
            return response()->json([
                'content' => "Log file not found: stale-alerts-{$date}.log",
                'exists' => false,
                'filename' => "stale-alerts-{$date}.log",
            ]);
        }

        $content = $this->tailFile($resolvedLog['path'], $lines);

        return response()->json([
            'content' => $content,
            'exists' => true,
            'filename' => $resolvedLog['filename'],
            'fallback' => $resolvedLog['is_fallback'],
        ]);
    }

    public function searchStaleEntriesLog(Request $request): \Illuminate\Http\JsonResponse
    {
        $query = (string) $request->get('q', '');
        $context = (int) $request->get('context', 6);

        if (mb_strlen($query) < 2) {
            return response()->json(['blocks' => [], 'total_matches' => 0]);
        }

        $date = now('America/New_York')->format('Y-m-d');
        $resolvedLog = $this->resolveDailyLogFile('stale-alerts', $date);

        if (! $resolvedLog) {
            return response()->json(['blocks' => [], 'total_matches' => 0, 'exists' => false]);
        }

        $lines = file($resolvedLog['path'], FILE_IGNORE_NEW_LINES);
        $lowerQuery = mb_strtolower($query);
        $included = [];
        $matchCount = 0;

        foreach ($lines as $i => $line) {
            if (str_contains(mb_strtolower($line), $lowerQuery)) {
                $matchCount++;
                $start = max(0, $i - $context);
                $end = min(count($lines) - 1, $i + $context);

                for ($j = $start; $j <= $end; $j++) {
                    $included[$j] = true;
                }
            }
        }

        if (empty($included)) {
            return response()->json(['blocks' => [], 'total_matches' => 0, 'total_lines' => count($lines)]);
        }

        ksort($included);
        $indices = array_keys($included);
        $blocks = [];
        $blockLines = [$indices[0]];

        for ($i = 1; $i < count($indices); $i++) {
            if ($indices[$i] === $indices[$i - 1] + 1) {
                $blockLines[] = $indices[$i];
            } else {
                $blocks[] = $this->buildBlock($lines, $blockLines, $lowerQuery);
                $blockLines = [$indices[$i]];
            }
        }

        $blocks[] = $this->buildBlock($lines, $blockLines, $lowerQuery);

        return response()->json([
            'blocks' => $blocks,
            'total_matches' => $matchCount,
            'filename' => $resolvedLog['filename'],
            'total_lines' => count($lines),
        ]);
    }

    public function getStreamingLog(Request $request): \Illuminate\Http\JsonResponse
    {
        $lines = (int) $request->get('lines', 300);

        $date = $request->get('date', now('America/New_York')->format('Y-m-d'));

        $logs = [
            'bar_stream' => storage_path("logs/bar-stream-{$date}.log"),
            'pipeline_watcher' => storage_path("logs/pipeline-watcher-{$date}.log"),
        ];

        $result = [];

        foreach ($logs as $key => $path) {
            if (! File::exists($path)) {
                $result[$key] = [
                    'content' => null,
                    'exists' => false,
                    'filename' => basename($path),
                    'size' => 0,
                ];

                continue;
            }

            $result[$key] = [
                'content' => $this->tailFile($path, $lines),
                'exists' => true,
                'filename' => basename($path),
                'size' => File::size($path),
            ];
        }

        return response()->json($result);
    }

    public function getContinuousBacktestLog(Request $request): \Illuminate\Http\JsonResponse
    {
        $date = $request->get('date', now('America/New_York')->format('Y-m-d'));
        $lines = (int) $request->get('lines', 300);
        $pipelines = ['a', 'b', 'c', 'd', 'e', 'f', 'g', 'h', 'i', 'j', 'k', 'l', 'm', 'n', 'o', 'p', 'q', 'r', 's'];

        $result = [];

        foreach ($pipelines as $pipeline) {
            $logFile = storage_path("logs/backtest-{$pipeline}-{$date}.log");

            if (! File::exists($logFile)) {
                $result[$pipeline] = [
                    'content' => null,
                    'exists' => false,
                    'filename' => "backtest-{$pipeline}-{$date}.log",
                    'size' => 0,
                ];

                continue;
            }

            $result[$pipeline] = [
                'content' => $this->tailFile($logFile, $lines),
                'exists' => true,
                'filename' => "backtest-{$pipeline}-{$date}.log",
                'size' => File::size($logFile),
            ];
        }

        return response()->json([
            'pipelines' => $result,
            'date' => $date,
        ]);
    }

    public function getHtopOutput(Request $request)
    {
        // Use mpstat to get per-CPU usage
        $mpstatCommand = 'mpstat -P ALL 1 1 2>&1';
        exec($mpstatCommand, $mpstatOutput, $mpstatReturn);

        // Use top for process list
        $topCommand = 'top -b -n 1 -w 200 2>&1';
        exec($topCommand, $topOutput, $topReturn);

        $topContent = implode("\n", $topOutput);

        // Get top 20 processes by memory usage
        $memCommand = 'ps aux --sort=-%mem --no-headers 2>&1 | head -20';
        exec($memCommand, $memOutput, $memReturn);

        $memoryUsage = $this->parseMemoryUsage($memOutput);

        // Parse CPU usage from mpstat
        $cpuUsage = $this->parseMpstatOutput($mpstatOutput);

        return response()->json([
            'content' => $topContent,
            'cpuUsage' => $cpuUsage,
            'memoryUsage' => $memoryUsage,
            'success' => $topReturn === 0 && $mpstatReturn === 0,
        ]);
    }

    private function parseMpstatOutput(array $output): array
    {
        $cpus = [];
        $dataStarted = false;

        foreach ($output as $line) {
            // Skip header lines until we find the CPU data
            if (preg_match('/^\d{2}:\d{2}:\d{2}\s+(all|CPU|\d+)\s+/', $line)) {
                $dataStarted = true;
            }

            if (! $dataStarted) {
                continue;
            }

            // Parse CPU line: Time CPU %usr %nice %sys %iowait %irq %soft %steal %guest %gnice %idle
            if (preg_match('/^\d{2}:\d{2}:\d{2}\s+(all|\d+)\s+([\d.]+)\s+([\d.]+)\s+([\d.]+)\s+([\d.]+)\s+([\d.]+)\s+([\d.]+)\s+([\d.]+)\s+([\d.]+)\s+([\d.]+)\s+([\d.]+)/', $line, $matches)) {
                $cpuId = $matches[1];
                $idle = (float) $matches[11];
                $used = 100.0 - $idle;

                $cpus[] = [
                    'id' => $cpuId === 'all' ? 'Average' : "CPU $cpuId",
                    'used' => round($used, 1),
                    'idle' => round($idle, 1),
                    'user' => (float) $matches[2],
                    'system' => (float) $matches[4],
                    'iowait' => (float) $matches[5],
                ];
            }
        }

        // If mpstat fails or returns no data, fallback to simple CPU count
        if (empty($cpus)) {
            $cpuCount = (int) shell_exec('nproc 2>/dev/null') ?: 1;
            for ($i = 0; $i < $cpuCount; $i++) {
                $cpus[] = [
                    'id' => "CPU $i",
                    'used' => 0,
                    'idle' => 100,
                    'user' => 0,
                    'system' => 0,
                    'iowait' => 0,
                ];
            }
        }

        return $cpus;
    }

    private function parseMemoryUsage(array $output): array
    {
        $processes = [];

        foreach ($output as $line) {
            // ps aux columns: USER PID %CPU %MEM VSZ RSS TTY STAT START TIME COMMAND
            $columns = preg_split('/\s+/', trim($line), 11);

            if (count($columns) < 11) {
                continue;
            }

            $processes[] = [
                'user' => $columns[0],
                'pid' => $columns[1],
                'cpu' => (float) $columns[2],
                'mem' => (float) $columns[3],
                'vsz' => $this->formatMemory((int) $columns[4]),
                'rss' => $this->formatMemory((int) $columns[5]),
                'command' => $columns[10],
            ];
        }

        return $processes;
    }

    private function formatMemory(int $kb): string
    {
        if ($kb >= 1048576) {
            return round($kb / 1048576, 1).' GB';
        }
        if ($kb >= 1024) {
            return round($kb / 1024, 1).' MB';
        }

        return $kb.' KB';
    }

    public function getLaravelLog(Request $request)
    {
        $date = now('America/New_York')->format('Y-m-d');
        $logType = $this->resolveLaravelLogType($request);
        $resolvedLog = $this->resolveDailyLogFile($logType['prefix'], $date);

        if (! $resolvedLog) {
            return response()->json([
                'content' => "Log file not found: {$logType['prefix']}-{$date}.log",
                'exists' => false,
                'type' => $logType['key'],
                'label' => $logType['label'],
            ]);
        }

        $lines = (int) $request->get('lines', 500);
        $content = $this->tailDailyLogs($logType['prefix'], $resolvedLog['path'], $lines);

        return response()->json([
            'content' => $content,
            'exists' => true,
            'filename' => $resolvedLog['filename'],
            'type' => $logType['key'],
            'label' => $logType['label'],
            'fallback' => $resolvedLog['is_fallback'],
        ]);
    }

    public function searchLaravelLog(Request $request): \Illuminate\Http\JsonResponse
    {
        $query = (string) $request->get('q', '');
        $context = (int) $request->get('context', 6);
        $logType = $this->resolveLaravelLogType($request);

        if (mb_strlen($query) < 2) {
            return response()->json(['blocks' => [], 'total_matches' => 0]);
        }

        $date = now('America/New_York')->format('Y-m-d');
        $resolvedLog = $this->resolveDailyLogFile($logType['prefix'], $date);

        if (! $resolvedLog) {
            return response()->json(['blocks' => [], 'total_matches' => 0, 'exists' => false]);
        }

        $logFile = $resolvedLog['path'];

        $lines = file($logFile, FILE_IGNORE_NEW_LINES);
        $lowerQuery = mb_strtolower($query);
        $included = [];
        $matchCount = 0;

        foreach ($lines as $i => $line) {
            if (str_contains(mb_strtolower($line), $lowerQuery)) {
                $matchCount++;
                $start = max(0, $i - $context);
                $end = min(count($lines) - 1, $i + $context);

                for ($j = $start; $j <= $end; $j++) {
                    $included[$j] = true;
                }
            }
        }

        if (empty($included)) {
            return response()->json(['blocks' => [], 'total_matches' => 0]);
        }

        ksort($included);
        $indices = array_keys($included);
        $blocks = [];
        $blockStart = $indices[0];
        $blockLines = [$indices[0]];

        for ($i = 1; $i < count($indices); $i++) {
            if ($indices[$i] === $indices[$i - 1] + 1) {
                $blockLines[] = $indices[$i];
            } else {
                $blocks[] = $this->buildBlock($lines, $blockLines, $lowerQuery);
                $blockStart = $indices[$i];
                $blockLines = [$indices[$i]];
            }
        }

        $blocks[] = $this->buildBlock($lines, $blockLines, $lowerQuery);

        return response()->json([
            'blocks' => $blocks,
            'total_matches' => $matchCount,
            'filename' => $resolvedLog['filename'],
            'total_lines' => count($lines),
            'type' => $logType['key'],
            'label' => $logType['label'],
            'fallback' => $resolvedLog['is_fallback'],
        ]);
    }

    /**
     * @return array{key: string, prefix: string, label: string}
     */
    private function resolveLaravelLogType(Request $request): array
    {
        $type = (string) $request->get('type', 'app');

        return match ($type) {
            'testing' => [
                'key' => 'testing',
                'prefix' => 'laravel-testing',
                'label' => 'Testing Log',
            ],
            'realtime' => [
                'key' => 'realtime',
                'prefix' => 'realtime',
                'label' => 'Realtime Log',
            ],
            default => [
                'key' => 'app',
                'prefix' => 'laravel',
                'label' => 'Laravel Log',
            ],
        };
    }

    /**
     * @return array{path: string, filename: string, is_fallback: bool}|null
     */
    private function resolveDailyLogFile(string $prefix, string $date): ?array
    {
        $primaryPath = storage_path("logs/{$prefix}-{$date}.log");

        if (File::exists($primaryPath)) {
            return [
                'path' => $primaryPath,
                'filename' => basename($primaryPath),
                'is_fallback' => false,
            ];
        }

        $matchingFiles = glob(storage_path("logs/{$prefix}-*.log")) ?: [];
        if (empty($matchingFiles)) {
            return null;
        }

        rsort($matchingFiles, SORT_STRING);
        $latestPath = $matchingFiles[0];

        return [
            'path' => $latestPath,
            'filename' => basename($latestPath),
            'is_fallback' => true,
        ];
    }

    /**
     * @param  string[]  $lines
     * @param  int[]  $indices
     * @return array{lines: array<array{text: string, is_match: bool}>}
     */
    private function buildBlock(array $lines, array $indices, string $lowerQuery): array
    {
        $blockLines = [];

        foreach ($indices as $idx) {
            $blockLines[] = [
                'text' => $lines[$idx],
                'is_match' => str_contains(mb_strtolower($lines[$idx]), $lowerQuery),
            ];
        }

        return ['lines' => $blockLines];
    }

    public function getSchedulerLog(Request $request)
    {
        $date = now('America/New_York')->format('Y-m-d');
        $logFile = storage_path("logs/laravel-scheduled-{$date}.log");

        if (! File::exists($logFile)) {
            return response()->json([
                'content' => "Log file not found: laravel-scheduled-{$date}.log",
                'exists' => false,
            ]);
        }

        $lines = (int) $request->get('lines', 500);
        $content = $this->tailFile($logFile, $lines);

        return response()->json([
            'content' => $content,
            'exists' => true,
            'filename' => "laravel-scheduled-{$date}.log",
        ]);
    }

    public function searchSchedulerLog(Request $request): \Illuminate\Http\JsonResponse
    {
        $query = (string) $request->get('q', '');
        $context = (int) $request->get('context', 6);

        if (mb_strlen($query) < 2) {
            return response()->json(['blocks' => [], 'total_matches' => 0]);
        }

        $date = now('America/New_York')->format('Y-m-d');
        $logFile = storage_path("logs/laravel-scheduled-{$date}.log");

        if (! File::exists($logFile)) {
            return response()->json(['blocks' => [], 'total_matches' => 0, 'exists' => false]);
        }

        $lines = file($logFile, FILE_IGNORE_NEW_LINES);
        $lowerQuery = mb_strtolower($query);
        $included = [];
        $matchCount = 0;

        foreach ($lines as $i => $line) {
            if (str_contains(mb_strtolower($line), $lowerQuery)) {
                $matchCount++;
                $start = max(0, $i - $context);
                $end = min(count($lines) - 1, $i + $context);

                for ($j = $start; $j <= $end; $j++) {
                    $included[$j] = true;
                }
            }
        }

        if (empty($included)) {
            return response()->json(['blocks' => [], 'total_matches' => 0, 'total_lines' => count($lines)]);
        }

        ksort($included);
        $indices = array_keys($included);
        $blocks = [];
        $blockLines = [$indices[0]];

        for ($i = 1; $i < count($indices); $i++) {
            if ($indices[$i] === $indices[$i - 1] + 1) {
                $blockLines[] = $indices[$i];
            } else {
                $blocks[] = $this->buildBlock($lines, $blockLines, $lowerQuery);
                $blockLines = [$indices[$i]];
            }
        }

        $blocks[] = $this->buildBlock($lines, $blockLines, $lowerQuery);

        return response()->json([
            'blocks' => $blocks,
            'total_matches' => $matchCount,
            'filename' => "laravel-scheduled-{$date}.log",
            'total_lines' => count($lines),
        ]);
    }

    private function tailFile(string $path, int $lines = 500): string
    {
        $file = new \SplFileObject($path, 'r');
        $file->seek(PHP_INT_MAX);
        $lastLine = $file->key();
        $startLine = max(0, $lastLine - $lines);

        $file->seek($startLine);
        $content = '';

        while (! $file->eof()) {
            $content .= $file->fgets();
        }

        return $content;
    }

    private function tailDailyLogs(string $prefix, string $newestPath, int $lines = 500): string
    {
        $allFiles = glob(storage_path("logs/{$prefix}-*.log")) ?: [];
        if (empty($allFiles)) {
            return '';
        }

        rsort($allFiles, SORT_STRING);
        $newestIndex = array_search($newestPath, $allFiles, true);
        if ($newestIndex === false) {
            $newestIndex = 0;
        }

        $remaining = max(1, $lines);
        $chunks = [];

        for ($i = $newestIndex; $i < count($allFiles) && $remaining > 0; $i++) {
            $chunk = $this->tailFile($allFiles[$i], $remaining);
            if ($chunk === '') {
                continue;
            }

            array_unshift($chunks, $chunk);
            $remaining -= $this->countLinesInChunk($chunk);
        }

        return implode('', $chunks);
    }

    private function countLinesInChunk(string $chunk): int
    {
        if ($chunk === '') {
            return 0;
        }

        $lineCount = substr_count($chunk, "\n");
        if (! str_ends_with($chunk, "\n")) {
            $lineCount++;
        }

        return $lineCount;
    }
}
