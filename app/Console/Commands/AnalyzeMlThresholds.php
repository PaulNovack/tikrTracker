<?php

namespace App\Console\Commands;

use App\Services\TradingSettingService;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class AnalyzeMlThresholds extends Command
{
    protected $signature = 'analyze:ml-thresholds
        {--days=30 : Lookback days when --from/--to are not provided}
        {--from= : Start trading date (EST) YYYY-mm-dd}
        {--to= : End trading date (EST) YYYY-mm-dd}
        {--pipelines= : Optional comma-separated pipelines (e.g. A,B,L)}
        {--min-threshold=0.05 : Minimum ML threshold to test}
        {--max-threshold=0.95 : Maximum ML threshold to test}
        {--step=0.05 : Threshold sweep increment}
        {--min-trades=20 : Minimum trades required at a threshold}
        {--metric=expectancy : Optimization metric: expectancy|win_rate|total_pnl}
        {--max-picks : Choose threshold that maximizes picks while meeting --min-win-rate}
        {--max_picks : Alias of --max-picks}
        {--win-cutoff=1.0 : pnl_percent cutoff used to count wins}
        {--min-win-rate=80 : If best win rate is below this percent, force disable threshold}
        {--min_win_rate= : Alias of --min-win-rate}
        {--min-pnl-per-day=0.5 : Minimum avg PnL per trade (%). Pipelines below this are forced off}
        {--disable-threshold=0.99 : Threshold used to effectively disable weak pipelines}
        {--lower-only : Only lower existing DB thresholds; never raise or remove them}
        {--dry-run : Do not write suggested thresholds to DB}
        {--top=3 : Show top N thresholds per pipeline}';

    protected $description = 'Iterate pipelines and recommend ML threshold settings from historical outcomes.';

    public function handle(): int
    {
        try {
            [$fromDate, $toDate] = $this->resolveDateWindow();
        } catch (\InvalidArgumentException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $windowDays = $this->calculateWindowDays($fromDate, $toDate);

        $minThreshold = (float) $this->option('min-threshold');
        $maxThreshold = (float) $this->option('max-threshold');
        $step = (float) $this->option('step');
        $minTrades = max(1, (int) $this->option('min-trades'));
        $winCutoff = (float) $this->option('win-cutoff');
        $topN = max(1, (int) $this->option('top'));
        $metric = strtolower((string) $this->option('metric'));
        $maxPicks = (bool) $this->option('max-picks') || (bool) $this->option('max_picks');
        $dryRun = (bool) $this->option('dry-run');
        $minWinRateOption = $this->option('min-win-rate');
        $minWinRateAliasOption = $this->option('min_win_rate');
        $minWinRate = $minWinRateAliasOption !== null && $minWinRateAliasOption !== ''
            ? (float) $minWinRateAliasOption
            : (float) $minWinRateOption;
        $minPnlPerDay = (float) $this->option('min-pnl-per-day');
        $disableThreshold = (float) $this->option('disable-threshold');
        $lowerOnly = (bool) $this->option('lower-only');

        if (! in_array($metric, ['expectancy', 'win_rate', 'total_pnl'], true)) {
            $this->error('Invalid --metric. Use one of: expectancy, win_rate, total_pnl');

            return self::FAILURE;
        }

        if ($minThreshold < 0 || $maxThreshold > 1.1 || $minThreshold >= $maxThreshold) {
            $this->error('Threshold bounds must satisfy: 0 <= min-threshold < max-threshold <= 1.1');

            return self::FAILURE;
        }

        if ($step <= 0) {
            $this->error('--step must be > 0');

            return self::FAILURE;
        }

        if ($minWinRate < 0 || $minWinRate > 100) {
            $this->error('--min-win-rate must be between 0 and 100');

            return self::FAILURE;
        }

        if ($minPnlPerDay < 0) {
            $this->error('--min-pnl-per-day must be >= 0');

            return self::FAILURE;
        }

        if ($disableThreshold < 0 || $disableThreshold > 1) {
            $this->error('--disable-threshold must be between 0 and 1');

            return self::FAILURE;
        }

        $pipelineFilter = $this->parsePipelineFilter((string) $this->option('pipelines'));

        $this->info('ML Threshold Optimizer');
        $this->line("Date range: {$fromDate} -> {$toDate}");
        $this->line("Metric: {$metric} | Min trades: {$minTrades} | Win cutoff: {$winCutoff}%");
        $this->line('Selection mode: '.($maxPicks ? 'max-picks (subject to min-win-rate)' : 'metric-ranked'));
        $this->line('Min win rate gate: '.number_format($minWinRate, 2).'% | Disable threshold: '.number_format($disableThreshold, 3));
        $this->line('Min avg PnL/trade gate: '.number_format($minPnlPerDay, 2).'%');
        $this->line('DB update mode: '.($dryRun ? 'DRY RUN (no writes)' : ($lowerOnly ? 'APPLY (lower-only; never raise/remove stored thresholds)' : 'APPLY (writes suggested thresholds)')));
        if ($pipelineFilter !== []) {
            $this->line('Pipelines: '.implode(', ', $pipelineFilter));
        }
        $this->newLine();

        $rows = DB::table('trade_alerts')
            ->select(['pipeline_run', 'trading_date_est', 'ml_win_prob', 'pnl_percent'])
            ->whereBetween('trading_date_est', [$fromDate, $toDate])
            ->whereNotNull('pipeline_run')
            ->whereNotNull('ml_win_prob')
            ->whereNotNull('pnl_percent')
            ->where('analyzed', 1)
            ->where('pipeline_run', '!=', 'X') // Pipeline X is used as a soft-delete target; always skip it
            ->when($pipelineFilter !== [], function ($query) use ($pipelineFilter): void {
                $query->whereIn('pipeline_run', $pipelineFilter);
            })
            ->get();

        if ($rows->isEmpty()) {
            $this->warn('No analyzed rows with ml_win_prob and pnl_percent found for the selected filters.');

            return self::SUCCESS;
        }

        $thresholds = $this->buildThresholds($minThreshold, $maxThreshold, $step);
        $grouped = $rows->groupBy(fn (object $row): string => strtoupper((string) $row->pipeline_run));

        $summary = [];
        $recommendations = [];

        foreach ($grouped->sortKeys() as $pipeline => $pipelineRows) {
            $evaluated = $this->evaluatePipeline($pipelineRows, $thresholds, $minTrades, $winCutoff, $metric);

            $settingKey = 'trading.pipeline_'.strtolower($pipeline).'.ml_threshold';
            $currentThreshold = $this->getCurrentThreshold($pipeline);
            $currentStoredThresholdRaw = TradingSettingService::getRaw($settingKey);
            $currentStoredThreshold = $currentStoredThresholdRaw !== null ? (float) $currentStoredThresholdRaw : null;

            if ($evaluated->isEmpty()) {
                $summary[] = [
                    $pipeline,
                    number_format($currentThreshold, 3),
                    'n/a',
                    $pipelineRows->count(),
                    'n/a',
                    'n/a',
                    'n/a',
                    'No threshold met min-trades',
                ];

                continue;
            }

            $best = $this->selectBestThreshold($evaluated, $minWinRate, $maxPicks);
            $bestWinRate = (float) $best['win_rate'];
            $pipelineAuc = TradingSettingService::getPipelineAuc($pipeline);
            $minAuc = TradingSettingService::getMinAuc();
            $pipelinePrecisionAtK = TradingSettingService::getPrecisionAtK($pipeline);
            $minPrecisionAtK = TradingSettingService::getMinPrecisionAt10();
            $forcedByNoModel = $pipelineAuc === null && $pipelinePrecisionAtK === null;
            $forcedByAuc = $pipelineAuc !== null && $pipelineAuc < $minAuc;
            $forcedByPrecisionAtK = $pipelinePrecisionAtK !== null && $pipelinePrecisionAtK < $minPrecisionAtK;
            $forcedByWinRate = $bestWinRate < $minWinRate;
            $candidateThreshold = ($forcedByNoModel || $forcedByWinRate || $forcedByAuc || $forcedByPrecisionAtK)
                ? $disableThreshold
                : (float) $best['threshold'];

            $estimation = $this->estimateTradesPerDay($pipelineRows, $candidateThreshold, $windowDays);
            $forcedByPnlPerDay = (float) $estimation['avg_pnl_per_trade'] < $minPnlPerDay;
            $suggestedThreshold = ($forcedByNoModel || $forcedByWinRate || $forcedByPnlPerDay || $forcedByAuc || $forcedByPrecisionAtK)
                ? $disableThreshold
                : (float) $best['threshold'];

            if ($forcedByPnlPerDay && ! $forcedByWinRate) {
                $estimation = $this->estimateTradesPerDay($pipelineRows, $disableThreshold, $windowDays);
            }

            $forceReasons = [];
            if ($forcedByNoModel) {
                $forceReasons[] = 'no ML model — untestable';
            }
            if ($forcedByAuc) {
                $forceReasons[] = 'AUC '.number_format((float) $pipelineAuc, 3).' < min '.number_format($minAuc, 3);
            }
            if ($forcedByPrecisionAtK) {
                $forceReasons[] = 'P@10 '.number_format((float) $pipelinePrecisionAtK, 3).' < min '.number_format($minPrecisionAtK, 3);
            }
            if ($forcedByWinRate) {
                $forceReasons[] = 'win_rate<'.number_format($minWinRate, 0).'%';
            }
            if ($forcedByPnlPerDay) {
                $forceReasons[] = 'avg_pnl/trade<'.number_format($minPnlPerDay, 2).'%';
            }

            $scoreLabel = $forceReasons !== []
                ? 'FORCED_OFF ('.implode(', ', $forceReasons).')'
                : ($maxPicks ? 'MAX_PICKS' : number_format((float) $best['score'], 3));

            $topCandidates = $maxPicks
                ? $this->rankThresholdsForMaxPicks($evaluated, $minWinRate)
                : $evaluated;

            $summary[] = [
                $pipeline,
                number_format($currentThreshold, 3),
                number_format($suggestedThreshold, 3),
                (int) $best['trades'],
                number_format($bestWinRate, 2).'%',
                number_format((float) $best['avg_pnl'], 3).'%',
                number_format((float) $best['total_pnl'], 2).'%',
                $scoreLabel,
            ];

            $recommendations[] = [
                'pipeline' => $pipeline,
                'setting_key' => $settingKey,
                'threshold' => $suggestedThreshold,
                'current_stored_threshold' => $currentStoredThreshold,
                'should_apply' => $this->shouldApplyThreshold($currentStoredThreshold, $suggestedThreshold, $lowerOnly),
                'forced_off' => $forcedByNoModel || $forcedByWinRate || $forcedByPnlPerDay || $forcedByAuc || $forcedByPrecisionAtK,
                'forced_by_win_rate' => $forcedByWinRate,
                'forced_by_pnl_per_day' => $forcedByPnlPerDay,
                'forced_by_auc' => $forcedByAuc,
                'forced_by_no_model' => $forcedByNoModel,
                'forced_by_precision_at_10' => $forcedByPrecisionAtK,
                'best_win_rate' => $bestWinRate,
                'min_pnl_per_day' => $minPnlPerDay,
                'pipeline_auc' => $pipelineAuc,
                'min_auc' => $minAuc,
                'pipeline_precision_at_k' => $pipelinePrecisionAtK,
                'min_precision_at_10' => $minPrecisionAtK,
                'top' => $topCandidates->take($topN)->values(),
            ];

            $recommendations[array_key_last($recommendations)]['estimated_trades'] = $estimation['trades'];
            $recommendations[array_key_last($recommendations)]['estimated_trades_per_day'] = $estimation['per_day'];
            $recommendations[array_key_last($recommendations)]['estimated_total_pnl'] = $estimation['total_pnl'];
            $recommendations[array_key_last($recommendations)]['estimated_pnl_per_day'] = $estimation['avg_pnl_per_trade'];
            $recommendations[array_key_last($recommendations)]['estimated_win_rate'] = $estimation['win_rate'];
            $recommendations[array_key_last($recommendations)]['estimated_wins'] = $estimation['wins'];
        }

        $this->table(
            ['Pipeline', 'Current', 'Suggested', 'Trades', 'Win Rate', 'Avg PnL', 'Total PnL', 'Score'],
            $summary
        );

        $this->newLine();
        $this->line('Recommended DB settings (trading settings keys):');
        foreach ($recommendations as $rec) {
            $lineThreshold = (float) $rec['threshold'];
            if ($lowerOnly && ! (bool) ($rec['should_apply'] ?? false) && ($rec['current_stored_threshold'] ?? null) !== null) {
                $lineThreshold = (float) $rec['current_stored_threshold'];
            }

            $line = $rec['setting_key'].'='.number_format($lineThreshold, 3, '.', '');
            if ((bool) ($rec['forced_off'] ?? false)) {
                $reasons = [];
                if ((bool) ($rec['forced_by_win_rate'] ?? false)) {
                    $reasons[] = 'best win rate '.number_format((float) ($rec['best_win_rate'] ?? 0.0), 2).'%';
                }
                if ((bool) ($rec['forced_by_pnl_per_day'] ?? false)) {
                    $reasons[] = 'avg pnl/trade < '.number_format((float) ($rec['min_pnl_per_day'] ?? 0.5), 2).'%';
                }

                $line .= '  # forced off ('.implode('; ', $reasons).')';
            } elseif ($lowerOnly) {
                if ((bool) ($rec['should_apply'] ?? false)) {
                    $line .= '  # lowered only';
                } elseif (($rec['current_stored_threshold'] ?? null) === null) {
                    $line .= '  # skipped (no stored threshold to lower)';
                } else {
                    $line .= '  # skipped (suggestion was not lower)';
                }
            }

            $this->line($line);
        }

        $this->newLine();
        if ($dryRun) {
            $this->warn('Dry run enabled. No DB settings were changed.');
        } else {
            foreach ($recommendations as $rec) {
                if ((bool) ($rec['should_apply'] ?? false)) {
                    TradingSettingService::set($rec['setting_key'], (string) number_format((float) $rec['threshold'], 3, '.', ''));

                    if ($this->shouldClearOverride($lowerOnly)) {
                        TradingSettingService::forget(str_replace('.ml_threshold', '.ml_threshold_override', $rec['setting_key']));
                    }
                }
            }

            $this->info($lowerOnly
                ? 'Applied lower-only ML threshold updates to DB settings successfully.'
                : 'Applied suggested ML thresholds to DB settings successfully.');
        }

        $this->newLine();
        $this->line(sprintf(
            'Top %d thresholds per pipeline (%s):',
            $topN,
            $maxPicks ? 'max-picks-ranked' : 'score-ranked'
        ));
        foreach ($recommendations as $rec) {
            $this->line('');
            $this->info('Pipeline '.$rec['pipeline']);

            $rowsOut = [];
            foreach ($rec['top'] as $rank => $row) {
                $rowsOut[] = [
                    '#'.($rank + 1),
                    number_format((float) $row['threshold'], 3),
                    (int) $row['trades'],
                    number_format((float) $row['win_rate'], 2).'%',
                    number_format((float) $row['avg_pnl'], 3).'%',
                    number_format((float) $row['total_pnl'], 2).'%',
                    number_format((float) $row['score'], 3),
                ];
            }

            $this->table(['Rank', 'Threshold', 'Trades', 'Win Rate', 'Avg PnL', 'Total PnL', 'Score'], $rowsOut);
        }

        if ($recommendations !== []) {
            $this->newLine();
            $this->line("Estimated trades/day using suggested thresholds (window: {$windowDays} day(s)):");

            $estimateRows = [];
            $totalEstimatedPerDay = 0.0;
            $totalEstimatedPnlWindow = 0.0;
            $totalEstimatedPnlPerDay = 0.0;
            $totalEstimatedTradesWindow = 0;
            $totalEstimatedWinsWindow = 0;
            foreach ($recommendations as $rec) {
                $estimatedTrades = (int) ($rec['estimated_trades'] ?? 0);
                $estimatedPerDay = (float) ($rec['estimated_trades_per_day'] ?? 0.0);
                $estimatedPnlWindow = (float) ($rec['estimated_total_pnl'] ?? 0.0);
                $estimatedPnlPerDay = (float) ($rec['estimated_pnl_per_day'] ?? 0.0);
                $estimatedWinRate = (float) ($rec['estimated_win_rate'] ?? 0.0);
                $totalEstimatedPerDay += $estimatedPerDay;
                $totalEstimatedPnlWindow += $estimatedPnlWindow;
                $totalEstimatedPnlPerDay += $estimatedPnlPerDay;
                $totalEstimatedTradesWindow += $estimatedTrades;
                $totalEstimatedWinsWindow += (int) ($rec['estimated_wins'] ?? 0);

                $estimateRows[] = [
                    $rec['pipeline'],
                    number_format((float) $rec['threshold'], 3),
                    $estimatedTrades,
                    number_format($estimatedPerDay, 2),
                    number_format($estimatedWinRate, 2).'%',
                    number_format($estimatedPnlWindow, 2).'%',
                    number_format($estimatedPnlPerDay, 2).'%',
                ];
            }

            $this->table(['Pipeline', 'Suggested', 'Est Trades (Window)', 'Est Trades/Day', 'Est Win Rate', 'Est PnL (Window)', 'Est PnL/Day'], $estimateRows);
            $this->line('Estimated total trades/day (all suggested pipelines): '.number_format($totalEstimatedPerDay, 2));
            $overallWinRate = $totalEstimatedTradesWindow > 0
                ? ($totalEstimatedWinsWindow / $totalEstimatedTradesWindow) * 100.0
                : 0.0;
            $this->line('Estimated overall win rate (weighted): '.number_format($overallWinRate, 2).'%');
            $this->line('Estimated total PnL (window, all suggested pipelines): '.number_format($totalEstimatedPnlWindow, 2).'%');
            $this->line('Estimated total PnL/day (all suggested pipelines): '.number_format($totalEstimatedPnlPerDay, 2).'%');
        }

        return self::SUCCESS;
    }

    private function calculateWindowDays(string $fromDate, string $toDate): int
    {
        $from = strtotime($fromDate);
        $to = strtotime($toDate);

        if ($from === false || $to === false || $to < $from) {
            return 1;
        }

        return (int) floor(($to - $from) / 86400) + 1;
    }

    /**
     * @return array{0:string,1:string}
     */
    private function resolveDateWindow(): array
    {
        $from = (string) $this->option('from');
        $to = (string) $this->option('to');

        if ($from !== '' || $to !== '') {
            if ($from === '' || $to === '') {
                throw new \InvalidArgumentException('Both --from and --to are required when either is provided.');
            }

            if ($from > $to) {
                throw new \InvalidArgumentException('--from must be <= --to.');
            }

            return [$from, $to];
        }

        $days = max(1, (int) $this->option('days'));
        $toDate = now('America/New_York')->toDateString();
        $fromDate = now('America/New_York')->subDays($days - 1)->toDateString();

        return [$fromDate, $toDate];
    }

    /**
     * @return array<int,string>
     */
    private function parsePipelineFilter(string $raw): array
    {
        if (trim($raw) === '') {
            return [];
        }

        $parts = array_filter(
            array_map(static fn (string $p): string => strtoupper(trim($p)), explode(',', $raw)),
            static fn (string $p): bool => $p !== 'X', // Pipeline X is used as a soft-delete target
        );

        return array_values(array_unique($parts));
    }

    /**
     * @return array<int,float>
     */
    private function buildThresholds(float $minThreshold, float $maxThreshold, float $step): array
    {
        $thresholds = [];
        for ($value = $minThreshold; $value <= $maxThreshold + 1e-9; $value += $step) {
            $thresholds[] = round($value, 6);
        }

        return $thresholds;
    }

    /**
     * @param  Collection<int, object>  $rows
     * @param  array<int,float>  $thresholds
     * @return Collection<int, array{threshold:float,trades:int,win_rate:float,avg_pnl:float,total_pnl:float,score:float}>
     */
    private function evaluatePipeline(Collection $rows, array $thresholds, int $minTrades, float $winCutoff, string $metric): Collection
    {
        $evaluated = collect();

        foreach ($thresholds as $threshold) {
            $selected = $rows->filter(fn (object $row): bool => (float) $row->ml_win_prob >= $threshold)->values();
            $trades = $selected->count();

            if ($trades < $minTrades) {
                continue;
            }

            $wins = $selected->filter(fn (object $row): bool => (float) $row->pnl_percent >= $winCutoff)->count();
            $winRate = ($wins / $trades) * 100.0;
            $avgPnl = (float) $selected->avg('pnl_percent');
            $totalPnl = (float) $selected->sum('pnl_percent');

            $score = match ($metric) {
                'win_rate' => $winRate,
                'total_pnl' => $totalPnl,
                default => $avgPnl,
            };

            $evaluated->push([
                'threshold' => $threshold,
                'trades' => $trades,
                'win_rate' => $winRate,
                'avg_pnl' => $avgPnl,
                'total_pnl' => $totalPnl,
                'score' => $score,
            ]);
        }

        return $evaluated
            ->sort(function (array $a, array $b): int {
                if ($a['score'] !== $b['score']) {
                    return $a['score'] < $b['score'] ? 1 : -1;
                }

                if ($a['trades'] !== $b['trades']) {
                    return $a['trades'] < $b['trades'] ? 1 : -1;
                }

                if ($a['threshold'] === $b['threshold']) {
                    return 0;
                }

                return $a['threshold'] < $b['threshold'] ? -1 : 1;
            })
            ->values();
    }

    private function getCurrentThreshold(string $pipeline): float
    {
        return TradingSettingService::getPipelineMlThreshold($pipeline);
    }

    private function shouldApplyThreshold(?float $currentStoredThreshold, float $suggestedThreshold, bool $lowerOnly): bool
    {
        if (! $lowerOnly) {
            return true;
        }

        return $currentStoredThreshold !== null && $suggestedThreshold < $currentStoredThreshold;
    }

    private function shouldClearOverride(bool $lowerOnly): bool
    {
        return ! $lowerOnly;
    }

    /**
     * @param  Collection<int, array{threshold:float,trades:int,win_rate:float,avg_pnl:float,total_pnl:float,score:float}>  $evaluated
     * @return array{threshold:float,trades:int,win_rate:float,avg_pnl:float,total_pnl:float,score:float}
     */
    private function selectBestThreshold(Collection $evaluated, float $minWinRate, bool $maxPicks): array
    {
        if (! $maxPicks) {
            /** @var array{threshold:float,trades:int,win_rate:float,avg_pnl:float,total_pnl:float,score:float} $best */
            $best = $evaluated->first();

            return $best;
        }

        /** @var array{threshold:float,trades:int,win_rate:float,avg_pnl:float,total_pnl:float,score:float} $best */
        $best = $this->rankThresholdsForMaxPicks($evaluated, $minWinRate)->first();

        return $best;
    }

    /**
     * @param  Collection<int, array{threshold:float,trades:int,win_rate:float,avg_pnl:float,total_pnl:float,score:float}>  $evaluated
     * @return Collection<int, array{threshold:float,trades:int,win_rate:float,avg_pnl:float,total_pnl:float,score:float}>
     */
    private function rankThresholdsForMaxPicks(Collection $evaluated, float $minWinRate): Collection
    {
        $qualified = $evaluated
            ->filter(fn (array $row): bool => (float) $row['win_rate'] >= $minWinRate)
            ->values();

        if ($qualified->isEmpty()) {
            return $evaluated;
        }

        return $qualified
            ->sort(function (array $a, array $b): int {
                if ($a['trades'] !== $b['trades']) {
                    return $a['trades'] < $b['trades'] ? 1 : -1;
                }

                if ($a['win_rate'] !== $b['win_rate']) {
                    return $a['win_rate'] < $b['win_rate'] ? 1 : -1;
                }

                if ($a['score'] !== $b['score']) {
                    return $a['score'] < $b['score'] ? 1 : -1;
                }

                if ($a['threshold'] === $b['threshold']) {
                    return 0;
                }

                return $a['threshold'] < $b['threshold'] ? -1 : 1;
            })
            ->values();
    }

    /**
     * @param  Collection<int, object>  $rows
     * @return array{trades:int,per_day:float,total_pnl:float,pnl_per_day:float,win_rate:float,wins:int}
     */
    /**
     * Estimate key metrics for a pipeline at a given ML threshold.
     *
     * Previously the pnl gate was total_pnl / calendar_days (pnl_per_day),
     * which unfairly disabled low-frequency pipelines. A signal that fires
     * once in 120 days with 100% win rate and 1.21% avg profit IS a good
     * signal — it just has low volume, which is a separate concern.
     *
     * Changed to avg_pnl_per_trade so the gate measures signal QUALITY
     * (average return per trade), not signal FREQUENCY.
     */
    private function estimateTradesPerDay(Collection $rows, float $threshold, int $windowDays): array
    {
        $selected = $rows->filter(fn (object $row): bool => (float) $row->ml_win_prob >= $threshold);
        $trades = $selected->count();
        $totalPnl = (float) $selected->sum('pnl_percent');
        $wins = $selected->filter(fn (object $row): bool => (float) $row->pnl_percent >= (float) $this->option('win-cutoff'))->count();
        $safeWindowDays = max(1, $windowDays);
        $winRate = $trades > 0 ? ($wins / $trades) * 100.0 : 0.0;

        return [
            'trades' => $trades,
            'per_day' => $trades / $safeWindowDays,
            'total_pnl' => $totalPnl,
            'avg_pnl_per_trade' => $trades > 0 ? $totalPnl / $trades : 0.0,
            'win_rate' => $winRate,
            'wins' => $wins,
        ];
    }
}
