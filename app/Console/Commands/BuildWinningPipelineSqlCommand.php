<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class BuildWinningPipelineSqlCommand extends Command
{
    protected $signature = 'trading:build-winning-pipeline-sql
        {pipeline_run=ALL : Pipeline letter to optimize, or ALL}
        {--target-pnl=2.0 : Minimum pnl_percent that counts as a winner}
        {--min-samples=25 : Minimum samples required before a gate is considered}
        {--iterations=6 : Maximum greedy optimization iterations per pipeline}
        {--low-quantile=0.20 : Lower quantile used for lower-bound thresholds}
        {--high-quantile=0.80 : Upper quantile used for upper-bound thresholds}
        {--min-improvement=0.05 : Minimum average pnl improvement required to keep a new gate set}
        {--version-string= : Optional version_string for the new alert_version row}
        {--output= : Optional file path to write the generated SQL to}
    ';

    protected $description = 'Generate SQL inserts for an optimized pipeline version using winners and losers from trade_alerts and trade_alerts_backtest_candidates.';

    public function handle(): int
    {
        $requestedPipeline = strtoupper((string) $this->argument('pipeline_run'));
        $targetPnl = (float) $this->option('target-pnl');
        $minSamples = max(1, (int) $this->option('min-samples'));
        $iterations = max(1, (int) $this->option('iterations'));
        $lowQuantile = $this->clampPercent((float) $this->option('low-quantile'));
        $highQuantile = $this->clampPercent((float) $this->option('high-quantile'));
        $minImprovement = max(0.0, (float) $this->option('min-improvement'));

        $versions = $this->loadActiveVersions($requestedPipeline === 'ALL' ? null : $requestedPipeline);
        if (empty($versions)) {
            $this->error('No active alert_versions rows found for the requested scope.');

            return self::FAILURE;
        }

        $results = [];
        foreach ($versions as $version) {
            $rows = $this->loadEvaluationRows((string) $version->pipeline_letter);
            if (empty($rows)) {
                $this->warn("Skipping pipeline {$version->pipeline_letter}; no analyzed rows were found.");

                continue;
            }

            $optimized = $this->optimizeVersion(
                currentVersion: $version,
                rows: $rows,
                targetPnl: $targetPnl,
                minSamples: $minSamples,
                iterations: $iterations,
                lowQuantile: $lowQuantile,
                highQuantile: $highQuantile,
                minImprovement: $minImprovement
            );

            if ($optimized !== null) {
                $results[] = $optimized;
            }
        }

        if (empty($results)) {
            $this->error('No viable optimized candidate was produced.');

            return self::FAILURE;
        }

        usort($results, static function (array $left, array $right): int {
            $avgCompare = $right['metrics']['avg_pnl'] <=> $left['metrics']['avg_pnl'];
            if ($avgCompare !== 0) {
                return $avgCompare;
            }

            $winRateCompare = $right['metrics']['win_rate'] <=> $left['metrics']['win_rate'];
            if ($winRateCompare !== 0) {
                return $winRateCompare;
            }

            return $right['metrics']['sample_count'] <=> $left['metrics']['sample_count'];
        });

        $best = $results[0];
        $versionString = (string) ($this->option('version-string') ?: $this->deriveVersionString((string) $best['currentVersion']->version_string));
        $sql = $this->buildSql(
            currentVersion: $best['currentVersion'],
            versionString: $versionString,
            gateData: $best['gateData'],
            targetPnl: $targetPnl,
            metrics: $best['metrics'],
            selectedGateCount: $best['selectedGateCount']
        );

        $this->newLine();
        $this->info('Pipeline ranking');
        $this->table(
            ['Pipeline', 'Samples', 'Win Rate', 'Avg P&L', 'Selected Gates'],
            array_map(static function (array $result): array {
                return [
                    $result['currentVersion']->pipeline_letter,
                    $result['metrics']['sample_count'],
                    number_format($result['metrics']['win_rate'], 1).'%',
                    number_format($result['metrics']['avg_pnl'], 2).'%',
                    $result['selectedGateCount'],
                ];
            }, $results)
        );

        $this->newLine();
        $this->info('Best candidate');
        $this->line('Pipeline: '.$best['currentVersion']->pipeline_letter);
        $this->line('Version string: '.$versionString);
        $this->line('Samples: '.$best['metrics']['sample_count']);
        $this->line('Win rate: '.number_format($best['metrics']['win_rate'], 1).'%');
        $this->line('Average pnl: '.number_format($best['metrics']['avg_pnl'], 2).'%');
        $this->line('Winner average pnl: '.number_format($best['metrics']['winner_avg_pnl'], 2).'%');
        $this->line('Selected gates: '.$best['selectedGateCount']);

        $this->line($sql);

        $outputPath = (string) $this->option('output');
        if ($outputPath !== '') {
            file_put_contents($outputPath, $sql."\n");
            $this->info("SQL written to {$outputPath}");
        }

        return self::SUCCESS;
    }

    /**
     * @return array<int, object>
     */
    private function loadActiveVersions(?string $pipelineRun): array
    {
        $query = DB::table('alert_versions')
            ->where('enabled', 1)
            ->orderBy('pipeline_letter');

        if ($pipelineRun !== null) {
            $query->where('pipeline_letter', $pipelineRun);
        }

        return $query->get()->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function loadEvaluationRows(string $pipelineRun): array
    {
        $rows = [];

        foreach (DB::table('trade_alerts')->where('pipeline_run', $pipelineRun)->whereNotNull('pnl_percent')->get() as $row) {
            $rows[] = $this->normalizeLiveAlertRow($row);
        }

        foreach (DB::table('trade_alerts_backtest_candidates')
            ->where('pipeline_run', $pipelineRun)
            ->whereNotNull('pnl_percent')
            ->where('analyzed', true)
            ->get() as $row) {
            $rows[] = $this->normalizeCandidateRow($row);
        }

        $unique = [];
        foreach ($rows as $row) {
            $unique[$row['dedupe_key']] = $row;
        }

        return array_values($unique);
    }

    /**
     * @return array{currentVersion: object, rows: array<int, array<string, mixed>>, metrics: array<string, float|int>, gateData: array<int, array<string, mixed>>, selectedGateCount: int}|null
     */
    private function optimizeVersion(object $currentVersion, array $rows, float $targetPnl, int $minSamples, int $iterations, float $lowQuantile, float $highQuantile, float $minImprovement): ?array
    {
        $gates = DB::table('alert_version_gates')
            ->where('alert_version_id', $currentVersion->id)
            ->orderBy('timeframe')
            ->orderBy('gate_name')
            ->get()
            ->all();

        if (empty($gates)) {
            return null;
        }

        $candidateRules = $this->buildCandidateRules($gates, $rows, $lowQuantile, $highQuantile);
        $selectedRules = [];
        $bestMetrics = $this->evaluateGateSet($rows, $selectedRules, $targetPnl);

        for ($iteration = 0; $iteration < $iterations; $iteration++) {
            $bestRule = null;
            $bestRuleMetrics = $bestMetrics;

            foreach ($candidateRules as $candidateRule) {
                if ($this->ruleAlreadySelected($selectedRules, $candidateRule)) {
                    continue;
                }

                $trialRules = array_merge($selectedRules, [$candidateRule]);
                $metrics = $this->evaluateGateSet($rows, $trialRules, $targetPnl);

                if ($metrics['sample_count'] < $minSamples) {
                    continue;
                }

                if ($this->isBetterMetrics($metrics, $bestRuleMetrics)) {
                    $bestRule = $candidateRule;
                    $bestRuleMetrics = $metrics;
                }
            }

            if ($bestRule === null) {
                break;
            }

            $improvement = $bestRuleMetrics['avg_pnl'] - $bestMetrics['avg_pnl'];
            if ($bestRuleMetrics['avg_pnl'] < $targetPnl && $improvement < $minImprovement) {
                break;
            }

            $selectedRules[] = $bestRule;
            $bestMetrics = $bestRuleMetrics;
        }

        $gateData = $this->buildGateSqlRows($gates, $selectedRules, $rows);

        return [
            'currentVersion' => $currentVersion,
            'rows' => $rows,
            'metrics' => $bestMetrics,
            'gateData' => $gateData,
            'selectedGateCount' => count($selectedRules),
        ];
    }

    /**
     * @param  array<int, object>  $gates
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<int, array<string, mixed>>
     */
    private function buildCandidateRules(array $gates, array $rows, float $lowQuantile, float $highQuantile): array
    {
        $rules = [];

        foreach ($gates as $gate) {
            $values = $this->collectGateValues($rows, (string) $gate->timeframe, (string) $gate->gate_name);
            if (empty($values)) {
                continue;
            }

            foreach ($this->candidateThresholds($values, $gate, $lowQuantile, $highQuantile) as $thresholds) {
                $rules[] = [
                    'gate_name' => (string) $gate->gate_name,
                    'timeframe' => (string) $gate->timeframe,
                    'threshold_min' => $thresholds['threshold_min'],
                    'threshold_max' => $thresholds['threshold_max'],
                ];
            }
        }

        return $rules;
    }

    /**
     * @param  array<int, object>  $gates
     * @param  array<int, array<string, mixed>>  $selectedRules
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<int, array<string, mixed>>
     */
    private function buildGateSqlRows(array $gates, array $selectedRules, array $rows): array
    {
        $gateData = [];

        foreach ($gates as $gate) {
            $selectedRule = $this->findSelectedRule($selectedRules, (string) $gate->gate_name, (string) $gate->timeframe);
            $values = $this->collectGateValues($rows, (string) $gate->timeframe, (string) $gate->gate_name);

            $gateData[] = [
                'gate' => $gate,
                'threshold_min' => $selectedRule['threshold_min'] ?? $gate->threshold_min,
                'threshold_max' => $selectedRule['threshold_max'] ?? $gate->threshold_max,
                'enabled' => $selectedRule !== null ? true : (bool) $gate->enabled,
                'sample_count' => count($values),
                'source' => $selectedRule !== null ? 'optimized_combo' : 'current',
                'pass_rate' => empty($values) ? null : $this->passRate($values),
            ];
        }

        return $gateData;
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<int, float>
     */
    private function collectGateValues(array $rows, string $timeframe, string $gateName): array
    {
        $values = [];

        foreach ($rows as $row) {
            $gateValues = $row['gate_values'] ?? [];
            if (! is_array($gateValues)) {
                continue;
            }

            $value = $gateValues[$gateName] ?? null;
            if ($value === null && isset($gateValues[$timeframe]) && is_array($gateValues[$timeframe])) {
                $value = $gateValues[$timeframe][$gateName] ?? null;
            }

            if ($value === null || $value === '') {
                continue;
            }

            if (is_bool($value)) {
                $values[] = $value ? 1.0 : 0.0;

                continue;
            }

            if (is_numeric($value)) {
                $values[] = (float) $value;
            }
        }

        return $values;
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @param  array<int, array<string, mixed>>  $rules
     * @return array<string, float|int>
     */
    private function evaluateGateSet(array $rows, array $rules, float $targetPnl): array
    {
        $passed = [];
        foreach ($rows as $row) {
            if ($this->rowPassesRules($row, $rules)) {
                $passed[] = $row;
            }
        }

        $sampleCount = count($passed);
        $winnerRows = array_values(array_filter($passed, static fn (array $row): bool => (float) ($row['pnl_percent'] ?? 0) >= $targetPnl));
        $loserRows = array_values(array_filter($passed, static fn (array $row): bool => (float) ($row['pnl_percent'] ?? 0) < $targetPnl));

        $avgPnl = $this->average(array_map(static fn (array $row): float => (float) $row['pnl_percent'], $passed));
        $winnerAvgPnl = $this->average(array_map(static fn (array $row): float => (float) $row['pnl_percent'], $winnerRows));
        $winRate = $sampleCount > 0 ? (count($winnerRows) / $sampleCount) * 100 : 0.0;

        return [
            'sample_count' => $sampleCount,
            'win_count' => count($winnerRows),
            'loss_count' => count($loserRows),
            'avg_pnl' => $avgPnl,
            'winner_avg_pnl' => $winnerAvgPnl,
            'win_rate' => $winRate,
        ];
    }

    /**
     * @param  array<int, float>  $values
     * @return array<int, array{threshold_min: ?float, threshold_max: ?float}>
     */
    private function candidateThresholds(array $values, object $gate, float $lowQuantile, float $highQuantile): array
    {
        sort($values, SORT_NUMERIC);
        $type = $this->gateType($gate);

        if ($type === 'boolean') {
            return [['threshold_min' => null, 'threshold_max' => null]];
        }

        $thresholds = [];
        $baseQuantiles = [0.10, 0.25, 0.50, 0.75, 0.90, $lowQuantile, $highQuantile];

        if ($type === 'range') {
            $mins = [0.05, 0.10, 0.25, $lowQuantile];
            $maxes = [0.60, 0.75, 0.90, $highQuantile];

            foreach ($mins as $minQuantile) {
                $minValue = $this->percentile($values, $minQuantile);
                if ($minValue === null) {
                    continue;
                }

                foreach ($maxes as $maxQuantile) {
                    $maxValue = $this->percentile($values, $maxQuantile);
                    if ($maxValue === null || $minValue > $maxValue) {
                        continue;
                    }

                    $thresholds[] = ['threshold_min' => $minValue, 'threshold_max' => $maxValue];
                }
            }

            return $thresholds;
        }

        foreach ($baseQuantiles as $quantile) {
            $value = $this->percentile($values, $quantile);
            if ($value === null) {
                continue;
            }

            if ($type === 'upper') {
                $thresholds[] = ['threshold_min' => null, 'threshold_max' => $value];
            } else {
                $thresholds[] = ['threshold_min' => $value, 'threshold_max' => null];
            }
        }

        return $thresholds;
    }

    /**
     * @param  array<int, array<string, mixed>>  $row
     * @param  array<int, array<string, mixed>>  $rules
     */
    private function rowPassesRules(array $row, array $rules): bool
    {
        foreach ($rules as $rule) {
            $value = $this->getGateValue($row, (string) $rule['timeframe'], (string) $rule['gate_name']);

            if ($value === null) {
                return false;
            }

            if ($rule['threshold_min'] === null && $rule['threshold_max'] === null) {
                if (! (bool) $value) {
                    return false;
                }

                continue;
            }

            if ($rule['threshold_min'] !== null && $value < (float) $rule['threshold_min']) {
                return false;
            }

            if ($rule['threshold_max'] !== null && $value > (float) $rule['threshold_max']) {
                return false;
            }
        }

        return true;
    }

    private function getGateValue(array $row, string $timeframe, string $gateName): float|bool|null
    {
        $gateValues = $row['gate_values'] ?? [];
        if (! is_array($gateValues)) {
            return null;
        }

        if (array_key_exists($gateName, $gateValues)) {
            $value = $gateValues[$gateName];

            return is_numeric($value) ? (float) $value : (is_bool($value) ? (bool) $value : null);
        }

        if (isset($gateValues[$timeframe]) && is_array($gateValues[$timeframe]) && array_key_exists($gateName, $gateValues[$timeframe])) {
            $value = $gateValues[$timeframe][$gateName];

            return is_numeric($value) ? (float) $value : (is_bool($value) ? (bool) $value : null);
        }

        return null;
    }

    private function gateType(object $gate): string
    {
        $hasMin = $gate->threshold_min !== null;
        $hasMax = $gate->threshold_max !== null;

        if ($hasMin && $hasMax) {
            return 'range';
        }

        if ($hasMax && ! $hasMin) {
            return 'upper';
        }

        if (! $hasMin && ! $hasMax) {
            return 'boolean';
        }

        return 'lower';
    }

    private function ruleAlreadySelected(array $selectedRules, array $candidateRule): bool
    {
        foreach ($selectedRules as $selectedRule) {
            if ($this->sameRule($selectedRule, $candidateRule)) {
                return true;
            }
        }

        return false;
    }

    private function sameRule(array $left, array $right): bool
    {
        return $left['gate_name'] === $right['gate_name']
            && $left['timeframe'] === $right['timeframe']
            && $left['threshold_min'] === $right['threshold_min']
            && $left['threshold_max'] === $right['threshold_max'];
    }

    private function findSelectedRule(array $selectedRules, string $gateName, string $timeframe): ?array
    {
        foreach ($selectedRules as $rule) {
            if ($rule['gate_name'] === $gateName && $rule['timeframe'] === $timeframe) {
                return $rule;
            }
        }

        return null;
    }

    private function isBetterMetrics(array $candidate, array $baseline): bool
    {
        if ($candidate['avg_pnl'] !== $baseline['avg_pnl']) {
            return $candidate['avg_pnl'] > $baseline['avg_pnl'];
        }

        if ($candidate['win_rate'] !== $baseline['win_rate']) {
            return $candidate['win_rate'] > $baseline['win_rate'];
        }

        return $candidate['sample_count'] > $baseline['sample_count'];
    }

    private function average(array $values): float
    {
        if (empty($values)) {
            return 0.0;
        }

        return array_sum($values) / count($values);
    }

    /**
     * @param  array<int, array<string, mixed>>  $gateData
     */
    private function buildSql(object $currentVersion, string $versionString, array $gateData, float $targetPnl, array $metrics, int $selectedGateCount): string
    {
        $now = now()->format('Y-m-d H:i:s');
        $sql = [];

        $sql[] = '-- Generated from winner+loser greedy optimization over all analyzed pipelines';
        $sql[] = '-- Target pnl_percent >= '.$this->formatNumber($targetPnl, 2).'%';
        $sql[] = '-- Optimized samples: '.$metrics['sample_count'].' | win rate: '.$this->formatNumber((float) $metrics['win_rate'], 2).'% | avg pnl: '.$this->formatNumber((float) $metrics['avg_pnl'], 2).'% | selected gates: '.$selectedGateCount;
        $sql[] = '';
        $sql[] = 'INSERT INTO `alert_versions` (`pipeline_letter`, `version_string`, `signal_type`, `entry_finder_class`, `scanner_score_formula`, `enabled`, `created_at`, `updated_at`)';
        $sql[] = 'VALUES (';
        $sql[] = implode(', ', [
            $this->sqlValue($currentVersion->pipeline_letter),
            $this->sqlValue($versionString),
            $this->sqlValue($currentVersion->signal_type),
            $this->sqlValue($currentVersion->entry_finder_class),
            $this->sqlValue($currentVersion->scanner_score_formula),
            '1',
            $this->sqlValue($now),
            $this->sqlValue($now),
        ]);
        $sql[] = ');';
        $sql[] = '';
        $sql[] = 'SET @new_alert_version_id := LAST_INSERT_ID();';
        $sql[] = '';
        $sql[] = 'INSERT INTO `alert_version_gates` (`alert_version_id`, `timeframe`, `gate_name`, `threshold_min`, `threshold_max`, `enabled`, `created_at`, `updated_at`)';
        $sql[] = 'VALUES';

        $gateRows = [];
        foreach ($gateData as $data) {
            $gate = $data['gate'];
            $gateRows[] = sprintf(
                "-- %s %s | samples=%d | source=%s%s\n(%s, %s, %s, %s, %s, %s, %s, %s)",
                $gate->timeframe,
                $gate->gate_name,
                $data['sample_count'],
                $data['source'],
                $data['pass_rate'] !== null ? ' | pass_rate='.number_format($data['pass_rate'] * 100, 1).'%' : '',
                '@new_alert_version_id',
                $this->sqlValue($gate->timeframe),
                $this->sqlValue($gate->gate_name),
                $this->sqlValue($this->sqlDecimalOrNull($data['threshold_min'])),
                $this->sqlValue($this->sqlDecimalOrNull($data['threshold_max'])),
                $data['enabled'] ? '1' : '0',
                $this->sqlValue($now),
                $this->sqlValue($now)
            );
        }

        if (! empty($gateRows)) {
            $lastIndex = array_key_last($gateRows);
            foreach ($gateRows as $index => $gateRow) {
                if ($index !== $lastIndex) {
                    $gateRows[$index] = $gateRow.',';
                } else {
                    $gateRows[$index] = $gateRow.';';
                }
            }
        }

        $sql = array_merge($sql, $gateRows);

        return implode("\n", $sql);
    }

    private function normalizeLiveAlertRow(object $row): array
    {
        $rowArray = get_object_vars($row);

        return [
            'dedupe_key' => (string) ($row->dedupe_key ?? implode('|', [(string) ($row->pipeline_run ?? ''), (string) ($row->symbol ?? ''), (string) ($row->entry_ts_est ?? '')])),
            'pnl_percent' => (float) ($row->pnl_percent ?? 0),
            'gate_values' => $this->extractGateValues($rowArray),
        ];
    }

    private function normalizeCandidateRow(object $row): array
    {
        $gateValues = $this->decodeJson((string) ($row->gate_values ?? ''));

        return [
            'dedupe_key' => (string) ($row->dedupe_key ?? implode('|', [(string) ($row->pipeline_run ?? ''), (string) ($row->symbol ?? ''), (string) ($row->entry_ts_est ?? '')])),
            'pnl_percent' => (float) ($row->pnl_percent ?? 0),
            'gate_values' => array_merge(
                is_array($gateValues['5m'] ?? null) ? $gateValues['5m'] : [],
                is_array($gateValues['1m'] ?? null) ? $gateValues['1m'] : []
            ),
        ];
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function extractGateValues(array $row): array
    {
        $excluded = array_flip([
            'id', 'symbol', 'asset_type', 'trading_date_est', 'as_of_ts_est', 'signal_type', 'signal_ts_est', 'time_of_day',
            'entry_type', 'entry_ts_est', 'entry', 'stop', 'risk_pct', 'risk_per_share', 'score', 'vol_ratio',
            'avg_dollar_volume_per_minute', 'calculated_position_size', 'exit_price', 'exit_ts_est', 'exit_reason',
            'pnl_percent', 'pnl_dollar', 'max_adverse_excursion', 'hold_time_minutes', 'r_multiple', 'target_hit',
            'analyzed', 'analyzed_at', 'passed_gates', 'failed_timeframe', 'failed_gate', 'failure_reason', 'failure_value',
            'failure_min', 'failure_max', 'gate_values', 'meta', 'dedupe_key', 'version', 'pipeline_run', 'query_source',
            'is_paper', 'is_realtime', 'blacklisted', 'notes', 'created_at', 'updated_at', 'sentiment_boost',
            'sentiment_score_1_100', 'daily_trend_5d_pct', 'range_position_60m', 'targets',
        ]);

        $gateValues = [];
        foreach ($row as $key => $value) {
            if (isset($excluded[$key])) {
                continue;
            }

            if (is_bool($value)) {
                $gateValues[$key] = $value ? 1 : 0;

                continue;
            }

            if (is_numeric($value)) {
                $gateValues[$key] = $value + 0;
            }
        }

        $meta = $this->decodeJson((string) ($row['meta'] ?? ''));
        foreach (['signal_meta', 'entry_meta'] as $metaKey) {
            if (! isset($meta[$metaKey]) || ! is_array($meta[$metaKey])) {
                continue;
            }

            foreach ($meta[$metaKey] as $key => $value) {
                if (is_bool($value)) {
                    $gateValues[$key] = $value ? 1 : 0;

                    continue;
                }

                if (is_numeric($value)) {
                    $gateValues[$key] = $value + 0;
                }
            }
        }

        return $gateValues;
    }

    /**
     * @param  array<int, float>  $values
     */
    private function percentile(array $values, float $quantile): ?float
    {
        if (empty($values)) {
            return null;
        }

        sort($values, SORT_NUMERIC);

        $quantile = $this->clampPercent($quantile);
        $index = $quantile * (count($values) - 1);
        $lower = (int) floor($index);
        $upper = (int) ceil($index);

        if ($lower === $upper) {
            return round((float) $values[$lower], 4);
        }

        $weight = $index - $lower;

        return round(((1 - $weight) * (float) $values[$lower]) + ($weight * (float) $values[$upper]), 4);
    }

    /**
     * @param  array<int, float>  $values
     */
    private function passRate(array $values): float
    {
        if (empty($values)) {
            return 0.0;
        }

        $passed = 0;
        foreach ($values as $value) {
            if ($value > 0) {
                $passed++;
            }
        }

        return $passed / count($values);
    }

    private function deriveVersionString(string $currentVersionString): string
    {
        return $currentVersionString.'-combo';
    }

    private function clampPercent(float $value): float
    {
        return max(0.0, min(1.0, $value));
    }

    private function sqlValue(mixed $value): string
    {
        if ($value === null) {
            return 'NULL';
        }

        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        return "'".str_replace("'", "''", (string) $value)."'";
    }

    private function sqlDecimalOrNull(mixed $value): ?string
    {
        if ($value === null || $value === '' || ! is_numeric($value)) {
            return null;
        }

        return number_format((float) $value, 4, '.', '');
    }

    private function formatNumber(float $value, int $decimals): string
    {
        return number_format($value, $decimals, '.', '');
    }

    private function decodeJson(string $json): array
    {
        $decoded = json_decode($json, true);

        return is_array($decoded) ? $decoded : [];
    }
}
