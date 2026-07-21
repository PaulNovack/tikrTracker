<?php

namespace App\Http\Controllers\Internal;

use App\Http\Controllers\Controller;
use App\Services\Trading\TradeAlertWriterV1;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;

class CppTradeSignalController extends Controller
{
    public function __invoke(Request $request, TradeAlertWriterV1 $writer): JsonResponse
    {
        $expectedToken = (string) config('trading.cpp_signal_token', '');

        if ($expectedToken !== '' && ! hash_equals($expectedToken, (string) $request->header('X-Trade-Token', ''))) {
            abort(403, 'Invalid token');
        }

        Log::channel('scheduled')->info('[CppTradeSignal] Request received', [
            'symbol' => $request->input('symbol'),
            'version' => $request->input('version'),
            'pipeline_run' => $request->input('pipeline_run', 'H'),
            'signal_ts_est' => $request->input('signal_ts_est'),
            'entry' => $request->input('entry'),
            'calculated_position_size' => $request->input('calculated_position_size'),
            'backtest_mode' => $request->input('backtest_mode', false),
            'ip' => $request->ip(),
        ]);

        // Cheap local rate limit. Keep this endpoint bound/firewalled to localhost for production.
        $rateKey = 'cpp-trade-signal:'.$request->ip();
        if (RateLimiter::tooManyAttempts($rateKey, 600)) {
            return response()->json(['ok' => false, 'error' => 'rate_limited'], 429);
        }
        RateLimiter::hit($rateKey, 60);

        // IMPORTANT:
        // Request::validate() returns only whitelisted keys. Any C++ feature missing
        // from this list will be silently dropped before TradeAlertWriterV1 sees it.
        $payload = $request->validate([
            'symbol' => ['required', 'string', 'max:20'],
            'asset_type' => ['required', 'string', 'max:20'],
            'pipeline_run' => ['nullable', 'string', 'max:20'],
            'version' => ['required', 'string', 'max:80'],
            'signal_type' => ['required', 'string', 'max:80'],
            'signal_ts_est' => ['required', 'date_format:Y-m-d H:i:s'],
            'as_of_ts_est' => ['nullable', 'date_format:Y-m-d H:i:s'],
            'entry_ts_est' => ['required', 'date_format:Y-m-d H:i:s'],
            'entry' => ['required', 'numeric', 'gt:0'],
            'stop' => ['required', 'numeric', 'gt:0'],
            'score' => ['nullable', 'numeric'],
            'type' => ['nullable', 'string', 'max:80'],
            'targets' => ['nullable', 'array'],
            'meta' => ['nullable', 'array'],
            'is_realtime' => ['nullable', 'boolean'],
            'backtest_mode' => ['nullable', 'boolean'],

            // Core risk/liquidity/volatility fields written directly by TradeAlertWriterV1.
            'risk_pct' => ['nullable', 'numeric'],
            'risk_per_share' => ['nullable', 'numeric'],
            'vol_ratio' => ['nullable', 'numeric'],
            'atr' => ['nullable', 'numeric'],
            'atr_pct' => ['nullable', 'numeric'],
            'rsi' => ['nullable', 'numeric'],
            'rsi_14_1m' => ['nullable', 'numeric'],
            'suggested_trailing_stop' => ['nullable', 'numeric'],
            'suggested_trailing_stop_pct' => ['nullable', 'numeric'],

            // 5-minute / scanner features.
            'move_30m_pct' => ['nullable', 'numeric'],
            'rvol_5m' => ['nullable', 'numeric'],
            'atr_pct_5m' => ['nullable', 'numeric'],
            'notional_last5m' => ['nullable', 'numeric'],
            'pct_nd' => ['nullable', 'numeric'],
            'spy_move_30m_pct' => ['nullable', 'numeric'],
            'universe_size' => ['nullable', 'integer'],
            'five_min_directional_changes' => ['nullable', 'integer'],
            'five_min_green_bar_pct' => ['nullable', 'numeric'],
            'five_min_net_progress' => ['nullable', 'numeric'],

            // Entry quality fields.
            'hod' => ['nullable', 'numeric'],
            'room_to_hod_pct' => ['nullable', 'numeric'],
            'room_to_hod_atr' => ['nullable', 'numeric'],
            'above_vwap_entry_pct' => ['nullable', 'numeric'],
            'entry_body_pct' => ['nullable', 'numeric'],
            'entry_close_position' => ['nullable', 'numeric'],
            'entry_volume_ratio' => ['nullable', 'numeric'],
            'entry_notional_1m' => ['nullable', 'numeric'],
            'calculated_position_size' => ['nullable', 'numeric'],
            'calculated_shares' => ['nullable', 'integer'],
            'max_trade_dollars' => ['nullable', 'numeric'],
            'max_minute_volume_pct' => ['nullable', 'numeric'],
            'cap_by_max_trade' => ['nullable', 'numeric'],
            'cap_by_minute_volume' => ['nullable', 'numeric'],
            'cap_by_ask_size' => ['nullable', 'numeric'],
            'quote_bid' => ['nullable', 'numeric'],
            'quote_ask' => ['nullable', 'numeric'],
            'quote_bid_size' => ['nullable', 'integer'],
            'quote_ask_size' => ['nullable', 'integer'],
            'quote_spread_pct' => ['nullable', 'numeric'],
            'quote_age_seconds' => ['nullable', 'numeric'],
            'quote_received_at_utc' => ['nullable', 'string', 'max:40'],
            'entry_price_source' => ['nullable', 'string', 'max:80'],
            'bar_close_entry' => ['nullable', 'numeric'],
            'entry_spread_strength' => ['nullable', 'numeric'],
            'entry_vwap_dist_score' => ['nullable', 'numeric'],
            'entry_atr_score' => ['nullable', 'numeric'],
            'entry_vol_score' => ['nullable', 'numeric'],
            'entry_candle_score' => ['nullable', 'numeric'],
            'entry_time_bonus' => ['nullable', 'numeric'],

            // Pattern-specific fields used by the existing PHP v25.2 finder/writer contract.
            'consolidation_bars' => ['nullable', 'integer'],
            'consolidation_bars_count' => ['nullable', 'integer'],
            'breakout_volume_ratio' => ['nullable', 'numeric'],
            'vwap_reclaim_strength_pct' => ['nullable', 'numeric'],
            'vwap_reclaim_wick_below_pct' => ['nullable', 'numeric'],
            'or_high_v252' => ['nullable', 'numeric'],
            'or_break_distance_pct' => ['nullable', 'numeric'],
            'or_retest_depth_pct' => ['nullable', 'numeric'],
            'or_hold_close_pct' => ['nullable', 'numeric'],
            'bars_since_or_break' => ['nullable', 'integer'],
            'ema9_pullback_depth_pct' => ['nullable', 'numeric'],
            'ema9_reclaim_pct' => ['nullable', 'numeric'],
        ]);

        try {
            $meta = (array) ($payload['meta'] ?? []);
            $symbol = strtoupper((string) $payload['symbol']);
            $assetType = (string) ($payload['asset_type'] ?? 'stock');
            $pipelineRun = strtoupper((string) ($payload['pipeline_run'] ?? 'H'));
            $version = (string) $payload['version'];
            $asOfTsEst = (string) ($payload['as_of_ts_est'] ?? $payload['entry_ts_est']);
            $backtestMode = (bool) ($payload['backtest_mode'] ?? false);
            $isRealtime = array_key_exists('is_realtime', $payload)
                ? (bool) $payload['is_realtime']
                : ! $backtestMode;

            // Important for historical posts: this suppresses stale-alert rejection and ML job spam.
            $writer->setBacktestMode($backtestMode);

            $get = static fn (string $key, mixed $default = null) => $payload[$key] ?? $meta[$key] ?? $default;
            $num = static fn (mixed $v) => $v === null || $v === '' ? null : (float) $v;
            $int = static fn (mixed $v) => $v === null || $v === '' ? null : (int) $v;

            $signalMetaKeys = [
                'move_30m_pct', 'rvol_5m', 'atr_pct_5m', 'notional_last5m',
                'pct_nd', 'spy_move_30m_pct', 'universe_size',
                'gap_open_pct', 'above_vwap_pct', 'vwap_stability_score',
                'bars_above_vwap', 'total_bars', 'current_price',
            ];

            $signalMeta = $meta;
            foreach ($signalMetaKeys as $key) {
                if (array_key_exists($key, $payload)) {
                    $signalMeta[$key] = $payload[$key];
                }
            }

            $signal = [
                'symbol' => $symbol,
                'asset_type' => $assetType,
                'signal_type' => (string) $payload['signal_type'],
                'signal_ts_est' => (string) $payload['signal_ts_est'],
                'score' => $num($get('score')) ?? 0.0,
                'atr' => $num($get('atr')),
                'atr_pct' => $num($get('atr_pct')),
                'meta' => array_merge($signalMeta, [
                    'source' => $signalMeta['source'] ?? 'cpp_daemon',
                    'external_writer' => true,
                    'writer_adapter' => 'TradeAlertWriterV1::upsertAlert',
                    'version' => $version,
                    'pipeline_run' => $pipelineRun,
                ]),
            ];

            $entry = [
                'type' => (string) ($get('type') ?? $get('entry_type') ?? 'CPP_ENTRY'),
                'symbol' => $symbol,
                'asset_type' => $assetType,
                'signal_ts_est' => (string) $payload['signal_ts_est'],
                'entry_ts_est' => (string) $payload['entry_ts_est'],
                'entry' => (float) $payload['entry'],
                'stop' => (float) $payload['stop'],
                'score' => $num($get('score')),
                'targets' => $payload['targets'] ?? null,
            ];

            $numericEntryKeys = [
                'vol_ratio', 'risk_per_share', 'risk_pct', 'atr', 'atr_pct',
                'hod', 'room_to_hod_pct', 'room_to_hod_atr', 'above_vwap_entry_pct',
                'entry_body_pct', 'entry_close_position', 'entry_volume_ratio', 'entry_notional_1m',
                'calculated_position_size', 'max_trade_dollars', 'max_minute_volume_pct',
                'cap_by_max_trade', 'cap_by_minute_volume', 'cap_by_ask_size',
                'quote_bid', 'quote_ask', 'quote_spread_pct', 'quote_age_seconds', 'bar_close_entry',
                'entry_spread_strength', 'entry_vwap_dist_score', 'entry_atr_score',
                'entry_vol_score', 'entry_candle_score', 'entry_time_bonus',
                'breakout_volume_ratio', 'five_min_green_bar_pct', 'five_min_net_progress',
                'suggested_trailing_stop', 'suggested_trailing_stop_pct',
                'vwap_reclaim_strength_pct', 'vwap_reclaim_wick_below_pct',
                'or_high_v252', 'or_break_distance_pct', 'or_retest_depth_pct', 'or_hold_close_pct',
                'ema9_pullback_depth_pct', 'ema9_reclaim_pct',
            ];

            foreach ($numericEntryKeys as $key) {
                $entry[$key] = $num($get($key));
            }

            $entry['rsi'] = $num($get('rsi')) ?? $num($get('rsi_14_1m'));
            $entry['consolidation_bars'] = $int($get('consolidation_bars')) ?? $int($get('consolidation_bars_count'));
            $entry['five_min_directional_changes'] = $int($get('five_min_directional_changes'));
            $entry['bars_since_or_break'] = $int($get('bars_since_or_break'));
            $entry['calculated_shares'] = $int($get('calculated_shares'));
            $entry['quote_bid_size'] = $int($get('quote_bid_size'));
            $entry['quote_ask_size'] = $int($get('quote_ask_size'));
            $entry['entry_price_source'] = $get('entry_price_source');
            $entry['quote_received_at_utc'] = $get('quote_received_at_utc');

            $entry['cpp_meta'] = $meta;

            $alertId = $writer->upsertAlert(
                $signal,
                $entry,
                $asOfTsEst,
                $version,
                $pipelineRun,
                $isRealtime
            );

            // TradeAlertWriterV1 calculates position size from Laravel settings.
            // When the C++ daemon sends stricter live quote/liquidity sizing,
            // overwrite calculated_position_size so the order placer can honor:
            // max $25k, max 10% 1-minute volume, and visible ask-size cap.
            if ($alertId && $num($get('calculated_position_size')) !== null) {
                $tableName = config('trading.pipelines.'.strtolower($pipelineRun).'.no_filter_finder', false)
                    ? 'trade_alerts_unfiltered'
                    : 'trade_alerts';

                \Illuminate\Support\Facades\DB::table($tableName)
                    ->where('id', $alertId)
                    ->update([
                        'calculated_position_size' => $num($get('calculated_position_size')),
                        'updated_at' => now(),
                    ]);
            }

            Log::channel('scheduled')->info('[CppTradeSignal] Result', [
                'symbol' => $symbol,
                'alert_id' => $alertId ?: null,
                'deduped_or_suppressed' => ! $alertId,
                'pipeline_run' => $pipelineRun,
                'backtest_mode' => $backtestMode,
                'cpp_position_size' => $num($get('calculated_position_size')),
            ]);

            return response()->json([
                'ok' => (bool) $alertId,
                'alert_id' => $alertId ?: null,
                'deduped_or_suppressed' => ! $alertId,
                'pipeline_run' => $pipelineRun,
                'backtest_mode' => $backtestMode,
                'is_realtime' => $isRealtime,
            ]);
        } catch (\Throwable $e) {
            Log::error('[CppTradeSignalController] failed to write via TradeAlertWriterV1', [
                'payload' => $payload,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'ok' => false,
                'error' => $e->getMessage(),
            ], 500);
        }
    }
}
