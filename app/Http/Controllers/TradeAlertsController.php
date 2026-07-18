<?php

namespace App\Http\Controllers;

use App\Models\MarketSchedule;
use App\Services\TradingSettingService;
use App\Support\EstTimezoneHelper;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class TradeAlertsController extends Controller
{
    /** @var list<string> */
    private const TRADE_ALERT_PIPELINES = ['A', 'B', 'C', 'D', 'E', 'F', 'G', 'H', 'I', 'J', 'K', 'L', 'M', 'N', 'O', 'P', 'Q', 'R', 'S', 'MANUAL', 'EXTERNAL'];

    public function index(Request $request)
    {
        \Log::info('TradeAlertsController::index called at '.now());

        // Get filter parameters
        $mlMinThreshold = $request->input('ml_min', 0);
        $dateFilter = $request->input('date', '');
        $symbolFilter = $request->input('symbol', '');
        $pipelineFilter = strtoupper($request->input('pipeline', ''));

        // Validate pipeline filter
        if ($pipelineFilter !== '' && ! in_array($pipelineFilter, self::TRADE_ALERT_PIPELINES, true)) {
            $pipelineFilter = '';
        }

        // Check data health
        $dataHealth = $this->checkDataHealth();

        // Fetch all alerts from both pipelines combined, sorted by entry time (newest first)
        $alerts = $this->fetchAllAlerts('trade_alerts', $mlMinThreshold, $dateFilter, $symbolFilter, $pipelineFilter);
        \Log::info('Found '.$alerts->total().' total alerts');

        return Inertia::render('trade-alerts/index', [
            'alerts' => $alerts->items(),
            'pagination' => [
                'current_page' => $alerts->currentPage(),
                'last_page' => $alerts->lastPage(),
                'per_page' => $alerts->perPage(),
                'total' => $alerts->total(),
                'from' => $alerts->firstItem(),
                'to' => $alerts->lastItem(),
            ],
            'isUnfiltered' => false,
            'mlMinThreshold' => (int) $mlMinThreshold,
            'dateFilter' => $dateFilter,
            'symbolFilter' => $symbolFilter,
            'pipelineFilter' => $pipelineFilter,
            'pipelineMlThresholds' => TradingSettingService::getAllPipelineMlThresholds(),
            'dataHealth' => $dataHealth,
        ]);
    }

    public function unfilteredIndex(Request $request)
    {
        \Log::info('TradeAlertsController::unfilteredIndex called at '.now());

        // Get filter parameters
        $mlMinThreshold = $request->input('ml_min', 0);
        $dateFilter = $request->input('date', '');
        $symbolFilter = $request->input('symbol', '');
        $pipelineFilter = strtoupper($request->input('pipeline', ''));

        // Validate pipeline filter
        if ($pipelineFilter !== '' && ! in_array($pipelineFilter, self::TRADE_ALERT_PIPELINES, true)) {
            $pipelineFilter = '';
        }

        // Check data health
        $dataHealth = $this->checkDataHealth();

        // Fetch unfiltered alerts
        $alerts = $this->fetchAllAlerts('trade_alerts_unfiltered', $mlMinThreshold, $dateFilter, $symbolFilter, $pipelineFilter);
        \Log::info('Found '.$alerts->total().' total unfiltered alerts');

        return Inertia::render('trade-alerts/index', [
            'alerts' => $alerts->items(),
            'pagination' => [
                'current_page' => $alerts->currentPage(),
                'last_page' => $alerts->lastPage(),
                'per_page' => $alerts->perPage(),
                'total' => $alerts->total(),
                'from' => $alerts->firstItem(),
                'to' => $alerts->lastItem(),
            ],
            'isUnfiltered' => true,
            'mlMinThreshold' => (int) $mlMinThreshold,
            'dateFilter' => $dateFilter,
            'symbolFilter' => $symbolFilter,
            'pipelineFilter' => $pipelineFilter,
            'pipelineMlThresholds' => TradingSettingService::getAllPipelineMlThresholds(),
            'dataHealth' => $dataHealth,
        ]);
    }

    private function fetchAllAlerts(string $tableName = 'trade_alerts', int|float $mlMinThreshold = 0, string $dateFilter = '', string $symbolFilter = '', string $pipelineFilter = '')
    {
        $query = DB::table($tableName)
            ->leftJoin('asset_info', function ($join) use ($tableName) {
                $join->on($tableName.'.symbol', '=', 'asset_info.symbol')
                    ->on($tableName.'.asset_type', '=', 'asset_info.asset_type');
            })
            ->select([
                $tableName.'.id',
                $tableName.'.symbol',
                $tableName.'.asset_type',
                $tableName.'.signal_type',
                $tableName.'.entry_type',
                $tableName.'.as_of_ts_est',
                $tableName.'.signal_ts_est',
                $tableName.'.entry_ts_est',
                $tableName.'.entry',
                $tableName.'.stop',
                $tableName.'.risk_pct',
                $tableName.'.score',
                $tableName.'.vol_ratio',
                $tableName.'.atr',
                $tableName.'.atr_pct',
                $tableName.'.suggested_trailing_stop',
                $tableName.'.suggested_trailing_stop_pct',
                $tableName.'.targets',
                $tableName.'.target_hit',
                $tableName.'.created_at',
                $tableName.'.meta',
                $tableName.'.version',
                $tableName.'.pipeline_run',
                $tableName.'.ml_win_prob',
                $tableName.'.ml_scored_at',
                $tableName.'.ml_model_version',
                $tableName.'.ml_live_win_prob',
                $tableName.'.ml_live_scored_at',
                'asset_info.id as asset_id',
            ]);

        // Apply date filter if set
        if ($dateFilter !== '') {
            $query->where($tableName.'.trading_date_est', $dateFilter);
        }

        // Apply symbol filter if set
        if ($symbolFilter !== '') {
            $query->where($tableName.'.symbol', 'like', $symbolFilter.'%');
        }

        // Apply ML filter: -1 means per-pipeline thresholds from settings
        $mlThreshold = (int) $mlMinThreshold;
        if ($mlThreshold === -1) {
            $mlThresholds = TradingSettingService::getAllPipelineMlThresholds();
            $caseSql = 'CASE';
            $bindings = [];
            foreach ($mlThresholds as $pipeline => $threshold) {
                $upper = strtoupper($pipeline);
                $caseSql .= " WHEN {$tableName}.pipeline_run = ? THEN {$tableName}.ml_win_prob >= ?";
                $bindings[] = $upper;
                $bindings[] = $threshold;
            }
            $caseSql .= ' ELSE 1 END';
            $query->whereRaw($caseSql, $bindings);
        } elseif ($mlThreshold > 0) {
            $query->where($tableName.'.ml_win_prob', '>=', $mlThreshold / 100);
        }

        // Apply pipeline filter if set
        if ($pipelineFilter !== '') {
            $query->where($tableName.'.pipeline_run', $pipelineFilter);
        }

        $alerts = $query->orderBy($tableName.'.id', 'desc')
            ->paginate(50);

        // Format the data for the frontend
        $alerts->getCollection()->transform(function ($alert) {
            $meta = $this->safeDecodeMeta($alert->meta);
            $targets = $this->safeDecodeMeta($alert->targets);

            // Determine risk level based on risk percentage
            $riskLevel = 'medium';
            if ($alert->risk_pct !== null) {
                if ($alert->risk_pct >= 3.0) {
                    $riskLevel = 'high';
                } elseif ($alert->risk_pct <= 1.5) {
                    $riskLevel = 'low';
                }
            }

            // Calculate staleness: use entry_ts_est when available and more recent than signal_ts_est,
            // because some scanners (e.g. Pipeline M) anchor signal_ts_est to a 2h lookback bar
            // while the actual entry is much closer to now.
            $signalCarbon = \Carbon\Carbon::createFromFormat('Y-m-d H:i:s', $alert->signal_ts_est, 'America/New_York');
            $entryCarbon = ! empty($alert->entry_ts_est)
                ? \Carbon\Carbon::createFromFormat('Y-m-d H:i:s', $alert->entry_ts_est, 'America/New_York')
                : null;
            $freshnessCarbon = ($entryCarbon && $entryCarbon->gt($signalCarbon)) ? $entryCarbon : $signalCarbon;
            $createdCarbon = \Carbon\Carbon::parse($alert->created_at)->setTimezone('America/New_York');
            $diffSeconds = $signalCarbon->diffInSeconds($createdCarbon);
            $minutes = floor($diffSeconds / 60);
            $seconds = $diffSeconds % 60;
            $stalenessFormatted = sprintf('%dm %ds', $minutes, $seconds);

            return [
                'id' => $alert->id,
                'symbol' => $alert->symbol,
                'asset_id' => $alert->asset_id,
                'signal_type' => $alert->signal_type,
                'signalType' => $alert->signal_type,
                'entry_type' => $alert->entry_type,
                'entryType' => $alert->entry_type,
                'entry' => (float) $alert->entry,
                'stop' => (float) $alert->stop,
                'riskPct' => $alert->risk_pct ? (float) $alert->risk_pct : null,
                'entryScore' => $alert->score ? (float) $alert->score : null,
                'atr' => $alert->atr ? (float) $alert->atr : null,
                'atrPct' => $alert->atr_pct ? (float) $alert->atr_pct : null,
                'suggestedTrailingStop' => $alert->suggested_trailing_stop ? (float) $alert->suggested_trailing_stop : null,
                'suggestedTrailingStopPct' => $alert->suggested_trailing_stop_pct ? (float) $alert->suggested_trailing_stop_pct : null,
                'risk_level' => $riskLevel,
                'version' => $alert->version ?? 'unknown',
                'pipeline_run' => $alert->pipeline_run ?? 'A',
                'created_at' => $alert->created_at,
                'createdAt' => $alert->created_at,
                'formatted_time' => EstTimezoneHelper::formatEstTimestamp($alert->signal_ts_est, 'M j, Y g:i A'),
                'time_ago' => \Carbon\Carbon::createFromFormat('Y-m-d H:i:s', $alert->signal_ts_est, 'America/New_York')->diffForHumans(\Carbon\Carbon::now('America/New_York')),
                'timeAgo' => \Carbon\Carbon::createFromFormat('Y-m-d H:i:s', $alert->signal_ts_est, 'America/New_York')->diffForHumans(\Carbon\Carbon::now('America/New_York')),
                // Signal time (when the pattern/signal occurred)
                'signal_time_est' => EstTimezoneHelper::formatEstTimestamp($alert->signal_ts_est, 'M j g:i A T'),
                'signalTime' => EstTimezoneHelper::formatEstTimestamp($alert->signal_ts_est, 'M j g:i A T'),
                'signal_time_ago' => \Carbon\Carbon::createFromFormat('Y-m-d H:i:s', $alert->signal_ts_est, 'America/New_York')->diffForHumans(\Carbon\Carbon::now('America/New_York')),
                // Alert creation time (when pipeline found and created the alert)
                'alert_created_time_est' => EstTimezoneHelper::formatEstTimestamp($alert->as_of_ts_est, 'M j g:i A T'),
                'alertCreatedTime' => EstTimezoneHelper::formatEstTimestamp($alert->as_of_ts_est, 'M j g:i A T'),
                'alert_created_time_ago' => \Carbon\Carbon::createFromFormat('Y-m-d H:i:s', $alert->as_of_ts_est, 'America/New_York')->diffForHumans(\Carbon\Carbon::now('America/New_York')),
                'db_created_time_est' => \Carbon\Carbon::parse($alert->created_at)->setTimezone('America/New_York')->format('M j g:i A T'),
                'db_created_time_ago' => \Carbon\Carbon::parse($alert->created_at)->setTimezone('America/New_York')->diffForHumans(\Carbon\Carbon::now('America/New_York')),
                // Staleness: difference between signal time and alert creation time
                'staleness_seconds' => $diffSeconds,
                'staleness_formatted' => $stalenessFormatted,
                // Legacy fields for backward compatibility
                'entry_time_est' => EstTimezoneHelper::formatEstTimestamp($alert->entry_ts_est ?? $alert->signal_ts_est, 'M j g:i A T'),
                'entryTime' => EstTimezoneHelper::formatEstTimestamp($alert->entry_ts_est ?? $alert->signal_ts_est, 'M j g:i A T'),
                'entry_time_ago' => \Carbon\Carbon::createFromFormat('Y-m-d H:i:s', $alert->entry_ts_est ?? $alert->signal_ts_est, 'America/New_York')->diffForHumans(\Carbon\Carbon::now('America/New_York')),
                'targets' => $targets,
                'targetHit' => $alert->target_hit,
                'target_hit' => $alert->target_hit,
                'ml_win_prob' => $alert->ml_win_prob ? (float) $alert->ml_win_prob : null,
                'mlWinProb' => $alert->ml_win_prob ? (float) $alert->ml_win_prob : null,
                'ml_scored_at' => $alert->ml_scored_at,
                'mlScoredAt' => $alert->ml_scored_at,
                'ml_model_version' => $alert->ml_model_version,
                'mlModelVersion' => $alert->ml_model_version,
                'ml_live_win_prob' => isset($alert->ml_live_win_prob) ? (float) $alert->ml_live_win_prob : null,
                'mlLiveWinProb' => isset($alert->ml_live_win_prob) ? (float) $alert->ml_live_win_prob : null,
                'ml_live_scored_at' => $alert->ml_live_scored_at ?? null,
                'mlLiveScoredAt' => $alert->ml_live_scored_at ?? null,
                'meta' => array_merge($meta, [
                    'price' => (float) $alert->entry,
                    'stop_loss' => (float) $alert->stop,
                    'risk_percent' => $alert->risk_pct ? (float) $alert->risk_pct : null,
                    'score' => $alert->score ? (float) $alert->score : null,
                    'vol_ratio' => $alert->vol_ratio ? (float) $alert->vol_ratio : null,
                ]),
            ];
        });

        return $alerts;
    }

    private function isStockMarketHoliday(string $date): bool
    {
        return MarketSchedule::where('date', $date)
            ->where('market_type', 'stock')
            ->where('status', 'holiday')
            ->exists();
    }

    private function checkDataHealth(): array
    {
        // Get the last trading day (excluding weekends and market holidays)
        $now = \Carbon\Carbon::now('America/New_York');
        $lastTradingDay = $now->copy();

        // If before market open (9:30 AM), step back one day before searching
        if ($lastTradingDay->format('H:i') < '09:30') {
            $lastTradingDay->subDay();
        }

        // Walk backwards until we find a real trading day (limit 14 days for safety)
        for ($i = 0; $i < 14; $i++) {
            if (! $lastTradingDay->isWeekday()) {
                $lastTradingDay = $lastTradingDay->previous(\Carbon\Carbon::FRIDAY);

                continue;
            }

            if ($this->isStockMarketHoliday($lastTradingDay->toDateString())) {
                $lastTradingDay->subDay();

                continue;
            }

            break;
        }

        $lastTradingDate = $lastTradingDay->format('Y-m-d');

        // Check if daily_prices has data for the last trading day
        $lastDailyPrice = DB::table('daily_prices')
            ->where('date', '>=', $lastTradingDate)
            ->orderBy('date', 'desc')
            ->first();

        $dailyPricesHealthy = $lastDailyPrice && $lastDailyPrice->date >= $lastTradingDate;

        // Get actual last date in daily_prices
        $actualLastDate = DB::table('daily_prices')
            ->select('date')
            ->orderBy('date', 'desc')
            ->limit(1)
            ->value('date');

        return [
            'daily_prices_healthy' => $dailyPricesHealthy,
            'last_trading_day' => $lastTradingDate,
            'actual_last_daily_price_date' => $actualLastDate,
            'message' => ! $dailyPricesHealthy
                ? "⚠️ daily_prices table is missing data for {$lastTradingDate}. Last available: {$actualLastDate}. Scanners cannot find yesterday's movers!"
                : null,
        ];
    }

    /**
     * Decode meta/targets that may be double-encoded (JSON string inside JSON string).
     * Handles: plain array, plain JSON string, double-encoded JSON string, or null.
     *
     * @return array<string, mixed>
     */
    private function safeDecodeMeta(mixed $value): array
    {
        if ($value === null) {
            return [];
        }

        if (is_array($value)) {
            return $value;
        }

        $decoded = json_decode((string) $value, true);

        if (is_array($decoded)) {
            return $decoded;
        }

        // Handle double-encoded: the decode gave us a string — try once more
        if (is_string($decoded)) {
            $inner = json_decode($decoded, true);

            if (is_array($inner)) {
                return $inner;
            }
        }

        return [];
    }
}
