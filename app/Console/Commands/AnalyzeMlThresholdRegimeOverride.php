<?php

namespace App\Console\Commands;

use App\Models\Setting;
use App\Services\TradingSettingService;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class AnalyzeMlThresholdRegimeOverride extends Command
{
    protected $signature = 'analyze:ml-thresholds-regime-override
        {--days=3 : Lookback days for the short-window regime check}
        {--pipelines= : Optional comma-separated pipelines (e.g. A,B,K)}
        {--min-trades=5 : Minimum trades required to consider lowering or restoring}
        {--step=0.05 : Threshold step below the baseline to test}
        {--min-win-lift=10 : Minimum win-rate lift, in points, versus the baseline to lower}
        {--restore-drop=5 : Win-rate drop, in points, versus the baseline to restore}
        {--max-age-days=2 : Maximum age in days for a temporary override before restoring}
        {--min-pnl-per-day=0 : Minimum pnl/day required to keep a lowered override active}
        {--floor=0.05 : Lowest threshold allowed for a temporary override}
        {--win-cutoff=1.0 : pnl_percent cutoff used to count wins}
        {--dry-run : Do not write DB settings}';

    protected $description = 'Apply or clear temporary ML threshold overrides from a short recent window.';

    public function handle(): int
    {
        $overrideConfig = (array) config('trading.auto_alpaca_orders.ml_threshold_regime_override', []);

        if (! (bool) ($overrideConfig['enabled'] ?? false)) {
            $this->warn('Regime override is disabled in config; no DB writes were made.');

            return self::SUCCESS;
        }

        $days = $this->resolveIntOption('days', (int) ($overrideConfig['lookback_days'] ?? 3), 3);
        $minTrades = $this->resolveIntOption('min-trades', (int) ($overrideConfig['min_trades'] ?? 5), 5);
        $step = $this->resolveFloatOption('step', (float) ($overrideConfig['step'] ?? 0.05), 0.05);
        $minWinLift = $this->resolveFloatOption('min-win-lift', (float) ($overrideConfig['min_win_lift'] ?? 10.0), 10.0);
        $restoreDrop = $this->resolveFloatOption('restore-drop', (float) ($overrideConfig['restore_drop'] ?? 5.0), 5.0);
        $maxAgeDays = $this->resolveIntOption('max-age-days', (int) ($overrideConfig['max_age_days'] ?? 2), 2);
        $minPnlPerDay = $this->resolveFloatOption('min-pnl-per-day', (float) ($overrideConfig['min_pnl_per_day'] ?? 0.0), 0.0);
        $floor = $this->resolveFloatOption('floor', (float) ($overrideConfig['floor'] ?? 0.05), 0.05);
        $winCutoff = $this->resolveFloatOption('win-cutoff', 1.0, 1.0);
        $dryRun = (bool) $this->option('dry-run');
        $pipelineFilter = $this->parsePipelineFilter((string) $this->option('pipelines'));

        [$fromDate, $toDate] = $this->resolveDateWindow($days);
        $windowDays = $this->calculateWindowDays($fromDate, $toDate);

        $this->info('ML Threshold Regime Override');
        $this->line("Date range: {$fromDate} -> {$toDate}");
        $this->line('Min trades: '.number_format($minTrades).' | Step: '.number_format($step, 3));
        $this->line('Min win lift: '.number_format($minWinLift, 2).'% | Restore drop: '.number_format($restoreDrop, 2).'%');
        $this->line('Max override age: '.number_format($maxAgeDays).' day(s) | Min pnl/day: '.number_format($minPnlPerDay, 2).'%');
        $this->line('DB update mode: '.($dryRun ? 'DRY RUN (no writes)' : 'APPLY (writes temporary override settings)'));
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
            ->when($pipelineFilter !== [], function ($query) use ($pipelineFilter): void {
                $query->whereIn('pipeline_run', $pipelineFilter);
            })
            ->get();

        if ($rows->isEmpty()) {
            $this->warn('No analyzed rows with ml_win_prob and pnl_percent were found for the selected filters.');

            return self::SUCCESS;
        }

        $grouped = $rows->groupBy(fn (object $row): string => strtoupper((string) $row->pipeline_run));

        $summary = [];
        $actions = [];

        foreach ($grouped->sortKeys() as $pipeline => $pipelineRows) {
            $baselineThreshold = TradingSettingService::getPipelineMlThresholdBaseline($pipeline)
                ?? TradingSettingService::getGlobalMlThreshold();
            $temporaryOverrideThreshold = TradingSettingService::getPipelineMlThresholdOverride($pipeline);
            $effectiveThreshold = $temporaryOverrideThreshold ?? $baselineThreshold;
            $targetOverrideThreshold = round(max($baselineThreshold - $step, $floor), 3);
            $overrideKey = 'trading.pipeline_'.strtolower($pipeline).'.ml_threshold_override';
            $overrideSetting = Setting::where('name', $overrideKey)->first();
            $overrideAgeDays = null;

            if ($overrideSetting?->updated_at !== null) {
                $overrideAgeDays = (int) floor($overrideSetting->updated_at->diffInHours(now()) / 24);
            }

            $baselineStats = $this->evaluateThreshold($pipelineRows, $baselineThreshold, $winCutoff, $windowDays);
            $targetStats = $this->evaluateThreshold($pipelineRows, $targetOverrideThreshold, $winCutoff, $windowDays);

            $hasEnoughSample = $baselineStats['trades'] >= $minTrades && $targetStats['trades'] >= $minTrades;
            $loweringSignal = $hasEnoughSample
                && ($targetStats['win_rate'] - $baselineStats['win_rate']) >= $minWinLift
                && $targetStats['pnl_per_day'] >= $minPnlPerDay
                && $targetOverrideThreshold < $baselineThreshold;

            $restoreSignal = $temporaryOverrideThreshold !== null
                && (
                    ($overrideAgeDays !== null && $overrideAgeDays >= $maxAgeDays)
                    || ! $hasEnoughSample
                    || $targetStats['win_rate'] <= ($baselineStats['win_rate'] - $restoreDrop)
                    || $targetStats['pnl_per_day'] < $minPnlPerDay
                );

            $action = 'HOLD';
            $reason = 'No regime change strong enough to move the threshold.';
            $nextOverrideValue = $temporaryOverrideThreshold;

            if ($restoreSignal) {
                $action = 'RESTORE';
                $reason = $overrideAgeDays !== null && $overrideAgeDays >= $maxAgeDays
                    ? 'Temporary override aged out.'
                    : 'Recent window weakened or no longer has enough sample.';
                $nextOverrideValue = null;
            } elseif ($loweringSignal) {
                $action = $temporaryOverrideThreshold === null ? 'LOWER' : 'HOLD';
                $reason = sprintf(
                    'Short window outperformed baseline by %.2f points with positive pnl/day.',
                    $targetStats['win_rate'] - $baselineStats['win_rate']
                );
                $nextOverrideValue = $targetOverrideThreshold;
            } elseif ($temporaryOverrideThreshold !== null) {
                $reason = 'Temporary override remains in place.';
            }

            $summary[] = [
                $pipeline,
                number_format($baselineThreshold, 3),
                $temporaryOverrideThreshold === null ? 'none' : number_format($temporaryOverrideThreshold, 3),
                number_format($effectiveThreshold, 3),
                number_format($targetOverrideThreshold, 3),
                number_format((float) $baselineStats['win_rate'], 2).'%',
                number_format((float) $targetStats['win_rate'], 2).'%',
                number_format((float) $baselineStats['pnl_per_day'], 2).'%',
                number_format((float) $targetStats['pnl_per_day'], 2).'%',
                $action,
                $reason,
            ];

            $actions[] = [
                'pipeline' => $pipeline,
                'override_key' => $overrideKey,
                'next_override_value' => $nextOverrideValue,
            ];
        }

        $this->table(
            ['Pipeline', 'Baseline', 'Current Override', 'Current Live', 'Target Override', 'Base WR', 'Target WR', 'Base PnL/Day', 'Target PnL/Day', 'Action', 'Reason'],
            $summary
        );

        $this->newLine();

        if ($dryRun) {
            $this->warn('Dry run enabled. No temporary overrides were written or cleared.');

            return self::SUCCESS;
        }

        foreach ($actions as $action) {
            if ($action['next_override_value'] === null) {
                TradingSettingService::forget($action['override_key']);

                continue;
            }

            TradingSettingService::set(
                $action['override_key'],
                number_format((float) $action['next_override_value'], 3, '.', '')
            );
        }

        $this->info('Temporary regime overrides were updated successfully.');

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
    private function resolveDateWindow(int $days): array
    {
        $days = max(1, $days);
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

        $parts = array_filter(array_map(static fn (string $pipeline): string => strtoupper(trim($pipeline)), explode(',', $raw)));

        return array_values(array_unique($parts));
    }

    private function resolveIntOption(string $optionName, int $configValue, int $signatureDefault): int
    {
        $rawValue = $this->option($optionName);

        if ((string) $rawValue === (string) $signatureDefault) {
            return $configValue;
        }

        return (int) $rawValue;
    }

    private function resolveFloatOption(string $optionName, float $configValue, float $signatureDefault): float
    {
        $rawValue = $this->option($optionName);

        if ((string) $rawValue === (string) $signatureDefault) {
            return $configValue;
        }

        return (float) $rawValue;
    }

    /**
     * @param  Collection<int, object>  $rows
     * @return array{trades:int,wins:int,win_rate:float,avg_pnl:float,total_pnl:float,pnl_per_day:float}
     */
    private function evaluateThreshold(Collection $rows, float $threshold, float $winCutoff, int $windowDays): array
    {
        $selected = $rows->filter(fn (object $row): bool => (float) $row->ml_win_prob >= $threshold)->values();
        $trades = $selected->count();

        if ($trades === 0) {
            return [
                'trades' => 0,
                'wins' => 0,
                'win_rate' => 0.0,
                'avg_pnl' => 0.0,
                'total_pnl' => 0.0,
                'pnl_per_day' => 0.0,
            ];
        }

        $wins = $selected->filter(fn (object $row): bool => (float) $row->pnl_percent >= $winCutoff)->count();
        $winRate = ($wins / $trades) * 100.0;
        $avgPnl = (float) $selected->avg('pnl_percent');
        $totalPnl = (float) $selected->sum('pnl_percent');
        $pnlPerDay = $windowDays > 0 ? $totalPnl / $windowDays : $totalPnl;

        return [
            'trades' => $trades,
            'wins' => $wins,
            'win_rate' => $winRate,
            'avg_pnl' => $avgPnl,
            'total_pnl' => $totalPnl,
            'pnl_per_day' => $pnlPerDay,
        ];
    }
}
