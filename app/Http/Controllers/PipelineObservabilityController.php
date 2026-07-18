<?php

namespace App\Http\Controllers;

use App\Services\TradingSettingService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class PipelineObservabilityController extends Controller
{
    /** @var array<string, array{version: string, enabled: bool, label: string}> */
    private array $pipelines = [];

    public function __construct()
    {
        $this->pipelines = [
            'A' => ['version' => env('TRADE_ALERT_A_VERSION', 'v90.1'),   'enabled' => TradingSettingService::isPipelineRunCronEnabled('A'), 'label' => $this->getPipelineLabel('A', env('TRADE_ALERT_A_VERSION', 'v90.1'))],
            'B' => ['version' => env('TRADE_ALERT_B_VERSION', 'v120.0'),  'enabled' => TradingSettingService::isPipelineRunCronEnabled('B'), 'label' => $this->getPipelineLabel('B', env('TRADE_ALERT_B_VERSION', 'v120.0'))],
            'C' => ['version' => env('TRADE_ALERT_C_VERSION', 'v600.0'),  'enabled' => TradingSettingService::isPipelineRunCronEnabled('C'), 'label' => $this->getPipelineLabel('C', env('TRADE_ALERT_C_VERSION', 'v600.0'))],
            'D' => ['version' => env('TRADE_ALERT_D_VERSION', 'v60.3'),   'enabled' => TradingSettingService::isPipelineRunCronEnabled('D'), 'label' => $this->getPipelineLabel('D', env('TRADE_ALERT_D_VERSION', 'v60.3'))],
            'E' => ['version' => env('TRADE_ALERT_E_VERSION', 'v400.0'),  'enabled' => TradingSettingService::isPipelineRunCronEnabled('E'), 'label' => $this->getPipelineLabel('E', env('TRADE_ALERT_E_VERSION', 'v400.0'))],
            'F' => ['version' => env('TRADE_ALERT_F_VERSION', 'v900.1'),  'enabled' => TradingSettingService::isPipelineRunCronEnabled('F'), 'label' => $this->getPipelineLabel('F', env('TRADE_ALERT_F_VERSION', 'v900.1'))],
            'G' => ['version' => env('TRADE_ALERT_G_VERSION', 'v210.0'),  'enabled' => TradingSettingService::isPipelineRunCronEnabled('G'), 'label' => $this->getPipelineLabel('G', env('TRADE_ALERT_G_VERSION', 'v210.0'))],
            'H' => ['version' => env('TRADE_ALERT_H_VERSION', 'v25.2'),   'enabled' => TradingSettingService::isPipelineRunCronEnabled('H'), 'label' => $this->getPipelineLabel('H', env('TRADE_ALERT_H_VERSION', 'v25.2'))],
            'I' => ['version' => env('TRADE_ALERT_I_VERSION', 'v17.0'),   'enabled' => TradingSettingService::isPipelineRunCronEnabled('I'), 'label' => $this->getPipelineLabel('I', env('TRADE_ALERT_I_VERSION', 'v17.0'))],
            'J' => ['version' => env('TRADE_ALERT_J_VERSION', 'v2000.0'),  'enabled' => TradingSettingService::isPipelineRunCronEnabled('J'), 'label' => $this->getPipelineLabel('J', env('TRADE_ALERT_J_VERSION', 'v2000.0'))],
            'K' => ['version' => env('TRADE_ALERT_K_VERSION', 'v1100.0'), 'enabled' => TradingSettingService::isPipelineRunCronEnabled('K'), 'label' => $this->getPipelineLabel('K', env('TRADE_ALERT_K_VERSION', 'v1100.0'))],
            'L' => ['version' => env('TRADE_ALERT_L_VERSION', 'v1600.0'), 'enabled' => TradingSettingService::isPipelineRunCronEnabled('L'), 'label' => $this->getPipelineLabel('L', env('TRADE_ALERT_L_VERSION', 'v1600.0'))],
            'M' => ['version' => env('TRADE_ALERT_M_VERSION', 'v1400.0'), 'enabled' => TradingSettingService::isPipelineRunCronEnabled('M'), 'label' => $this->getPipelineLabel('M', env('TRADE_ALERT_M_VERSION', 'v1400.0'))],
            'N' => ['version' => env('TRADE_ALERT_N_VERSION', 'v1200.0'), 'enabled' => TradingSettingService::isPipelineRunCronEnabled('N'), 'label' => $this->getPipelineLabel('N', env('TRADE_ALERT_N_VERSION', 'v1200.0'))],
            'O' => ['version' => env('TRADE_ALERT_O_VERSION', 'v1500.0'), 'enabled' => TradingSettingService::isPipelineRunCronEnabled('O'), 'label' => $this->getPipelineLabel('O', env('TRADE_ALERT_O_VERSION', 'v1500.0'))],
            'P' => ['version' => env('TRADE_ALERT_P_VERSION', 'v140.0'), 'enabled' => TradingSettingService::isPipelineRunCronEnabled('P'), 'label' => $this->getPipelineLabel('P', env('TRADE_ALERT_P_VERSION', 'v140.0'))],
            'Q' => ['version' => env('TRADE_ALERT_Q_VERSION', 'v27.0'), 'enabled' => TradingSettingService::isPipelineRunCronEnabled('Q'), 'label' => $this->getPipelineLabel('Q', env('TRADE_ALERT_Q_VERSION', 'v27.0'))],
            'R' => ['version' => env('TRADE_ALERT_R_VERSION', 'rt-v1.0'), 'enabled' => TradingSettingService::isPipelineRunCronEnabled('R'), 'label' => $this->getPipelineLabel('R', env('TRADE_ALERT_R_VERSION', 'rt-v1.0'))],
            'S' => ['version' => env('TRADE_ALERT_S_VERSION', 'rt-vwap-reversal-v1.0'), 'enabled' => TradingSettingService::isPipelineRunCronEnabled('S'), 'label' => $this->getPipelineLabel('S', env('TRADE_ALERT_S_VERSION', 'rt-vwap-reversal-v1.0'))],
        ];
    }

    /**
     * Try to load scanner name directly from the scanner class.
     */
    private function getScannerNameFromClass(string $version): ?string
    {
        $versionClean = str_replace(['.', '-'], '_', ltrim($version, 'v'));
        $className = "App\\Services\\Trading\\FiveMinuteSignalScannerV{$versionClean}";

        if (class_exists($className)) {
            try {
                $scanner = app($className);
                if (method_exists($scanner, 'getName')) {
                    return $scanner->getName();
                }
            } catch (\Throwable $e) {
                // Fall back
            }
        }

        return null;
    }

    /**
     * Get pipeline label by loading from scanner class or falling back to "Pipeline — vX.X".
     */
    private function getPipelineLabel(string $pipelineLetter, string $version): string
    {
        $name = $this->getScannerNameFromClass($version);

        return $name ? "{$pipelineLetter} — {$version} — {$name}" : "Pipeline ({$version})";
    }

    public function index(): \Inertia\Response
    {
        return Inertia::render('pipeline-observability/index', [
            'metrics' => $this->collectMetrics($this->resolveDate(request()->query('date'))),
            'lastUpdated' => now()->setTimezone('America/New_York')->format('Y-m-d H:i:s T'),
            'selectedDate' => $this->resolveDate(request()->query('date')),
        ]);
    }

    public function api(): \Illuminate\Http\JsonResponse
    {
        $date = $this->resolveDate(request()->query('date'));

        return response()->json([
            'metrics' => $this->collectMetrics($date),
            'lastUpdated' => now()->setTimezone('America/New_York')->format('Y-m-d H:i:s T'),
            'selectedDate' => $date,
        ]);
    }

    private function resolveDate(?string $date): string
    {
        if ($date && preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            return $date;
        }

        return Carbon::now('America/New_York')->toDateString();
    }

    /**
     * @return array<string, mixed>
     */
    private function collectMetrics(string $dateEst): array
    {
        $todayEst = $dateEst;
        $nowEst = Carbon::now('America/New_York');
        $isToday = $todayEst === $nowEst->toDateString();
        $isMarketHours = $isToday
            && $nowEst->isWeekday()
            && ($nowEst->hour > 9 || ($nowEst->hour === 9 && $nowEst->minute >= 30))
            && $nowEst->hour < 16;

        return [
            'pipelines' => $this->pipelineStatuses($todayEst, $isMarketHours),
            'throughput' => $this->alertThroughput($todayEst),
            'skip_reasons' => $this->skipReasons($todayEst),
            'gap_analysis' => $this->gapAnalysis($todayEst),
            'miss_rate' => $this->missRateByVersion(),
            'is_market_hours' => $isMarketHours,
            'today_est' => $todayEst,
            'is_today' => $isToday,
        ];
    }

    /**
     * Per-pipeline cron health: enabled status, last real-time alert, alert count today.
     *
     * @return array<int, array<string, mixed>>
     */
    private function pipelineStatuses(string $todayEst, bool $isMarketHours): array
    {
        $rows = DB::select('
            SELECT
                pipeline_run,
                is_realtime,
                COUNT(*) AS alert_count,
                MAX(created_at) AS last_alert_at
            FROM trade_alerts
            WHERE trading_date_est = ?
            GROUP BY pipeline_run, is_realtime
        ', [$todayEst]);

        $byPipeline = [];
        foreach ($rows as $row) {
            $key = $row->pipeline_run;
            if (! isset($byPipeline[$key])) {
                $byPipeline[$key] = ['realtime_count' => 0, 'backtest_count' => 0, 'last_realtime_at' => null, 'last_backtest_at' => null];
            }
            if ($row->is_realtime) {
                $byPipeline[$key]['realtime_count'] = $row->alert_count;
                $byPipeline[$key]['last_realtime_at'] = $row->last_alert_at;
            } else {
                $byPipeline[$key]['backtest_count'] = $row->alert_count;
                $byPipeline[$key]['last_backtest_at'] = $row->last_alert_at;
            }
        }

        $statuses = [];
        foreach ($this->pipelines as $run => $config) {
            $data = $byPipeline[$run] ?? ['realtime_count' => 0, 'backtest_count' => 0, 'last_realtime_at' => null, 'last_backtest_at' => null];
            $effectiveMlThreshold = $this->effectiveMlThresholdForPipeline($run);

            $lastAt = $data['last_realtime_at']
                ? Carbon::parse($data['last_realtime_at'])->setTimezone('America/New_York')
                : null;

            $minutesSinceLast = $lastAt ? $lastAt->diffInMinutes(now('America/New_York')) : null;

            $health = 'disabled';
            if ($config['enabled']) {
                if ($minutesSinceLast === null) {
                    $health = $isMarketHours ? 'no_alerts_today' : 'waiting';
                } elseif ($isMarketHours && $minutesSinceLast > 15) {
                    $health = 'stale';
                } else {
                    $health = 'active';
                }
            }

            $statuses[] = [
                'pipeline_run' => $run,
                'label' => $config['label'],
                'version' => $config['version'],
                'ml_threshold' => $effectiveMlThreshold,
                'enabled' => $config['enabled'],
                'health' => $health,
                'realtime_count' => $data['realtime_count'],
                'backtest_count' => $data['backtest_count'],
                'last_realtime_at' => $lastAt?->format('H:i:s'),
                'minutes_since_last' => $minutesSinceLast,
            ];
        }

        return $statuses;
    }

    private function effectiveMlThresholdForPipeline(string $pipelineRun): float
    {
        return TradingSettingService::getPipelineMlThreshold($pipelineRun);
    }

    /**
     * Hourly alert throughput for today (realtime vs backtest).
     *
     * @return array<int, array<string, mixed>>
     */
    private function alertThroughput(string $todayEst): array
    {
        $rows = DB::select("
            SELECT
                HOUR(CONVERT_TZ(created_at, 'UTC', 'America/New_York')) AS hour_est,
                is_realtime,
                COUNT(*) AS cnt
            FROM trade_alerts
            WHERE trading_date_est = ?
            GROUP BY hour_est, is_realtime
            ORDER BY hour_est
        ", [$todayEst]);

        $byHour = [];
        foreach ($rows as $row) {
            $h = (int) $row->hour_est;
            if (! isset($byHour[$h])) {
                $byHour[$h] = ['hour' => $h, 'label' => sprintf('%d:00', $h), 'realtime' => 0, 'backtest' => 0];
            }
            if ($row->is_realtime) {
                $byHour[$h]['realtime'] = (int) $row->cnt;
            } else {
                $byHour[$h]['backtest'] = (int) $row->cnt;
            }
        }

        ksort($byHour);

        return array_values($byHour);
    }

    /**
     * Skip reason breakdown for today.
     *
     * @return array<int, array<string, mixed>>
     */
    private function skipReasons(string $todayEst): array
    {
        $rows = DB::select('
            SELECT
                id,
                version,
                symbol,
                skipped_reason,
                skip_price,
                entry,
                entry_ts_est,
                signal_ts_est,
                CONVERT_TZ(created_at, \'+00:00\', \'America/New_York\') AS created_at_est,
                avg_dollar_volume_per_minute,
                CONVERT_TZ(skipped_at, \'+00:00\', \'America/New_York\') AS skipped_at_est,
                CASE WHEN skipped_reason = \'price_extension\' AND skip_price IS NOT NULL AND entry > 0
                    THEN ROUND(((skip_price - entry) / entry) * 100, 2)
                    ELSE NULL END AS extension_pct,
                    CASE WHEN skipped_reason = \'age_too_old\' AND skipped_at IS NOT NULL
                        THEN ROUND(GREATEST(0, TIMESTAMPDIFF(SECOND,
                            COALESCE(
                                CONVERT_TZ(signal_ts_est, \'America/New_York\', \'UTC\'),
                                CONVERT_TZ(entry_ts_est, \'America/New_York\', \'UTC\'),
                                created_at
                            ),
                            skipped_at
                        )) / 60, 4)
                    ELSE NULL END AS age_minutes,
                ml_live_win_prob,
                ml_win_prob
            FROM trade_alerts
            WHERE trading_date_est = ?
              AND skipped_reason IS NOT NULL
            ORDER BY skipped_at DESC
        ', [$todayEst]);

        return array_map(fn ($r) => [
            'id' => (int) $r->id,
            'version' => $r->version,
            'symbol' => $r->symbol,
            'reason' => $r->skipped_reason,
            'skip_price' => $r->skip_price !== null ? (float) $r->skip_price : null,
            'entry' => $r->entry !== null ? (float) $r->entry : null,
            'extension_pct' => $r->extension_pct !== null ? (float) $r->extension_pct : null,
            'age_minutes' => $r->age_minutes !== null ? (float) $r->age_minutes : null,
            'avg_dollar_volume_per_minute' => $r->avg_dollar_volume_per_minute !== null ? (float) $r->avg_dollar_volume_per_minute : null,
            'ml_live_win_prob' => $r->ml_live_win_prob !== null ? (float) $r->ml_live_win_prob : null,
            'ml_win_prob' => $r->ml_win_prob !== null ? (float) $r->ml_win_prob : null,
            'skipped_at' => $r->skipped_at_est,
            'created_at' => $r->created_at_est,
        ], $rows);
    }

    /**
     * Backtest-only signals today that real-time never fired.
     *
     * @return array<int, array<string, mixed>>
     */
    private function gapAnalysis(string $todayEst): array
    {
        $rows = DB::select('
            SELECT
                b.symbol,
                b.version,
                b.pipeline_run,
                b.entry_ts_est,
                b.score AS entry_score,
                b.ml_win_prob
            FROM trade_alerts b
            WHERE b.is_realtime = 0
              AND b.trading_date_est = ?
              AND NOT EXISTS (
                  SELECT 1 FROM trade_alerts r
                  WHERE r.symbol           = b.symbol
                    AND r.version          = b.version
                    AND r.trading_date_est = b.trading_date_est
                    AND r.is_realtime      = 1
              )
            ORDER BY b.score DESC
            LIMIT 100
        ', [$todayEst]);

        return array_map(fn ($r) => [
            'symbol' => $r->symbol,
            'version' => $r->version,
            'pipeline_run' => $r->pipeline_run,
            'entry_ts_est' => $r->entry_ts_est,
            'entry_score' => $r->entry_score !== null ? (float) $r->entry_score : null,
            'ml_win_prob' => $r->ml_win_prob !== null ? (float) $r->ml_win_prob : null,
        ], $rows);
    }

    /**
     * 7-day real-time miss rate by version.
     *
     * @return array<int, array<string, mixed>>
     */
    private function missRateByVersion(): array
    {
        $since = Carbon::now('America/New_York')->subDays(7)->toDateString();

        $enabledPipelineRuns = array_keys(array_filter(
            $this->pipelines,
            fn (array $config): bool => (bool) ($config['enabled'] ?? false)
        ));

        if (empty($enabledPipelineRuns)) {
            return [];
        }

        $pipelineInList = implode(',', array_map(
            fn (string $run): string => "'".str_replace("'", "''", $run)."'",
            $enabledPipelineRuns
        ));

        $rows = DB::select("
            WITH bt AS (
                SELECT
                    b.id,
                    b.version,
                    b.pipeline_run,
                    b.symbol,
                    b.trading_date_est,
                    b.created_at,
                    COALESCE(b.signal_ts_est, b.entry_ts_est, b.as_of_ts_est) AS bt_ts_est
                FROM trade_alerts b
                WHERE b.is_realtime = 0
                  AND b.trading_date_est >= ?
                  AND b.pipeline_run IN ({$pipelineInList})
            ),
            matched AS (
                SELECT bt.id
                FROM bt
                WHERE EXISTS (
                    SELECT 1
                    FROM trade_alerts r
                    WHERE r.is_realtime = 1
                      AND r.version = bt.version
                      AND r.pipeline_run = bt.pipeline_run
                      AND r.symbol = bt.symbol
                      AND r.trading_date_est = bt.trading_date_est
                      AND ABS(TIMESTAMPDIFF(
                          MINUTE,
                          COALESCE(
                              CONVERT_TZ(r.signal_ts_est, 'America/New_York', 'UTC'),
                              CONVERT_TZ(r.entry_ts_est, 'America/New_York', 'UTC'),
                              r.created_at
                          ),
                          COALESCE(
                              CONVERT_TZ(bt.bt_ts_est, 'America/New_York', 'UTC'),
                              bt.created_at
                          )
                      )) <= 10
                )
            )
            SELECT
                bt.version,
                COUNT(*) AS backtest_total,
                SUM(CASE WHEN m.id IS NOT NULL THEN 1 ELSE 0 END) AS also_realtime,
                SUM(CASE WHEN m.id IS NULL THEN 1 ELSE 0 END) AS missed,
                ROUND(100.0 * SUM(CASE WHEN m.id IS NULL THEN 1 ELSE 0 END) / NULLIF(COUNT(*), 0), 1) AS miss_rate_pct
            FROM bt
            LEFT JOIN matched m ON m.id = bt.id
            GROUP BY bt.version
            ORDER BY miss_rate_pct DESC
        ", [$since]);

        return array_map(fn ($r) => [
            'version' => $r->version,
            'backtest_total' => (int) $r->backtest_total,
            'also_realtime' => (int) $r->also_realtime,
            'missed' => (int) $r->missed,
            'miss_rate_pct' => $r->miss_rate_pct !== null ? (float) $r->miss_rate_pct : 0.0,
        ], $rows);
    }
}
