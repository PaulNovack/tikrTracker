<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AlpacaOrder;
use App\Models\TradeAlert;
use App\Services\StockPriceService;
use App\Services\Trading\TradeAlertWriterV1;
use App\Services\TradingSettingService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * External Application Buy API
 *
 * Accepts POST /api/external/buy?token={API_TOKEN} to place buy orders on Alpaca
 * from external applications. Authenticated via a token set in .env.
 */
class ExternalBuyController extends Controller
{
    public function __construct(
        private readonly StockPriceService $priceService,
        private readonly TradeAlertWriterV1 $alertWriter,
    ) {}

    /**
     * Place a buy order from an external application.
     */
    public function __invoke(Request $request): JsonResponse
    {
        // ── 1. Token validation ───────────────────────────────────
        $token = $request->query('token');
        $expectedToken = config('app.external_buy_api_token');

        Log::channel('external-buy')->info('[ExternalBuy] Request received', [
            'symbol' => $request->input('symbol'),
            'shares' => $request->input('shares'),
            'entry_price' => $request->input('entry_price'),
            'ip' => $request->ip(),
        ]);

        if (! $token || ! $expectedToken || ! hash_equals($expectedToken, $token)) {
            Log::channel('external-buy')->warning('[ExternalBuy] Invalid token attempt', ['ip' => $request->ip()]);

            return response()->json(['error' => 'Invalid or missing API token'], 401);
        }

        // ── 2. Validate request body ──────────────────────────────
        $data = $request->validate([
            'symbol' => 'required|string|max:10',
            'shares' => 'nullable|integer|min:0',
            'entry_price' => 'nullable|numeric|min:0',
            'stop_price' => 'nullable|numeric|min:0',
            'entry_type' => 'nullable|string|max:50',
            'notes' => 'nullable|string|max:500',
        ]);

        $symbol = strtoupper($data['symbol']);
        $requestedShares = $data['shares'] !== null ? (int) $data['shares'] : 0;
        $explicitStopPrice = isset($data['stop_price']) ? (float) $data['stop_price'] : null;
        $entryType = strtoupper($data['entry_type'] ?? 'EXTERNAL');
        $notes = $data['notes'] ?? '';

        // ── 2a. Per-symbol lock (prevents duplicate orders from concurrent calls) ─
        $lockKey = 'external_buy_lock:'.$symbol;
        $lock = Cache::lock($lockKey, 30);
        if (! $lock->get()) {
            Log::channel('external-buy')->warning('[ExternalBuy] Blocked duplicate concurrent request', [
                'symbol' => $symbol,
            ]);

            return response()->json(['error' => "A buy order for {$symbol} is already being processed."], 429);
        }

        // Persistently block rapid repeat orders for 120s (even after lock release).
        // This catches sequential non-overlapping requests for the same symbol.
        $recentlyPlacedKey = 'external_buy_placed:'.$symbol;
        if (Cache::get($recentlyPlacedKey)) {
            $lock->release();

            Log::channel('external-buy')->warning('[ExternalBuy] Blocked rapid repeat for symbol (Redis marker)', [
                'symbol' => $symbol,
            ]);

            return response()->json(['error' => "A buy order for {$symbol} was placed recently. Please wait 2 minutes."], 429);
        }

        // Mark as processing immediately so concurrent requests see this before alert creation
        Cache::put($recentlyPlacedKey, true, 120);

        try {
            $now = Carbon::now('America/New_York');
            $asOfTsEst = $now->format('Y-m-d H:i:s');

            // ── 2c. Time slot + market hours check ───────────────────
            // External orders respect the same time slot and hours config
            // that PlaceAlpacaOrderForHighScoreAlerts enforces for pipelines.
            // Round down to the nearest 15-min boundary (matching getEntryTimeSlotKey).
            $slotMinute = (int) floor(((int) $now->format('i')) / 15) * 15;
            $slotKey = $now->format('H').':'.str_pad((string) $slotMinute, 2, '0', STR_PAD_LEFT);

            $startTime = TradingSettingService::getTradingStartTime();
            $endTime = TradingSettingService::getTradingEndTime();

            if ($slotKey < $startTime || $slotKey >= $endTime) {
                Log::channel('external-buy')->warning('[ExternalBuy] Blocked by trading hours', [
                    'symbol' => $symbol,
                    'now' => $slotKey,
                    'hours' => "{$startTime}-{$endTime}",
                ]);

                return response()->json(['error' => "Trading hours are {$startTime}-{$endTime} EST."], 400);
            }

            if (! TradingSettingService::isTimeSlotEnabled($slotKey)) {
                Log::channel('external-buy')->warning('[ExternalBuy] Blocked by time slot', [
                    'symbol' => $symbol,
                    'slot' => $slotKey,
                ]);

                return response()->json(['error' => "Trading is not allowed in the {$slotKey} time slot."], 400);
            }

            // ── 2b. Resolve entry price from live market data ─────────
            // The caller may pass an estimate, but we always use the
            // current market price so the backtest page shows accurate
            // dollar amounts and P&L.
            $latestQuote = $this->priceService->getLatestPrice($symbol);
            $entryPrice = $latestQuote['price'] ?? (float) ($data['entry_price'] ?? 0);

            if ($entryPrice <= 0) {
                return response()->json([
                    'error' => "Could not resolve market price for {$symbol}. Provide an entry_price or ensure market data is available.",
                ], 400);
            }

            // ── 3. Liquidity check ────────────────────────────────────
            $recentAvgDollarVol = DB::table('one_minute_prices')
                ->where('symbol', $symbol)
                ->where('asset_type', 'stock')
                ->where('ts_est', '>=', $now->copy()->subMinutes(5)->format('Y-m-d H:i:s'))
                ->avg(DB::raw('price * volume'));

            if (! $recentAvgDollarVol || $recentAvgDollarVol <= 0) {
                $recentAvgDollarVol = DB::table('one_minute_prices')
                    ->where('symbol', $symbol)
                    ->where('asset_type', 'stock')
                    ->where('ts_est', '>=', $now->copy()->subMinutes(30)->format('Y-m-d H:i:s'))
                    ->avg(DB::raw('price * volume')) ?: 0;
            }

            $minDollarVol = TradingSettingService::getMinDollarVolumePerMinute();
            if ($minDollarVol > 0 && $recentAvgDollarVol < $minDollarVol) {
                return response()->json([
                    'error' => "{$symbol} has insufficient liquidity (\$".number_format((int) $recentAvgDollarVol).'/min — min $'.number_format((int) $minDollarVol).').',
                ], 400);
            }

            // ── 4. Position sizing (matches Pipeline H / TradeAlertWriterV1) ─
            // Uses the same dynamic sizing rules: volume × pct, clamped by min/max.
            // When caller explicitly provides shares > 0, those are used directly.
            $mode = config('trading.position_size_mode', 'fixed');

            if ($requestedShares > 0) {
                $shares = $requestedShares;
            } elseif ($mode === 'dynamic') {
                $maxPct = TradingSettingService::getMaxPositionPctOfLiquidity();
                $minSize = TradingSettingService::getMinPositionSize();
                $maxSize = TradingSettingService::getMaxPositionSize();

                // $recentAvgDollarVol is avg dollar volume per minute (from step 3)
                $targetCost = $recentAvgDollarVol > 0
                    ? $recentAvgDollarVol * ($maxPct / 100)
                    : $maxSize;

                // Apply min/max bounds
                $targetCost = max($minSize, min($maxSize, $targetCost));

                // Cap by buying power for safety
                try {
                    $bpService = app(\App\Services\AlpacaPythonService::class);
                    $bpResult = $bpService->runScript('account_details.py');
                    if ($bpResult['success'] && preg_match('/"buying_power":\s*([\d.]+)/', $bpResult['output'], $bpMatch)) {
                        $targetCost = min((float) $bpMatch[1] * 0.95, $targetCost);
                    }
                } catch (\Throwable) {
                    // ignore, fall through to sizing cap
                }

                $shares = $entryPrice > 0 ? (int) floor($targetCost / $entryPrice) : 0;
            } else {
                // Fixed mode: use configured flat position size, capped by buying power
                $targetCost = TradingSettingService::getMaxPositionSize();
                try {
                    $bpService = app(\App\Services\AlpacaPythonService::class);
                    $bpResult = $bpService->runScript('account_details.py');
                    if ($bpResult['success'] && preg_match('/"buying_power":\s*([\d.]+)/', $bpResult['output'], $bpMatch)) {
                        $targetCost = min((float) $bpMatch[1] * 0.95, $targetCost);
                    }
                } catch (\Throwable) {
                    // ignore, fall through to settings default
                }

                $shares = $entryPrice > 0 ? (int) floor($targetCost / $entryPrice) : 0;
            }

            // ── 5. Stop loss calculation (matches other pipelines: mode + ATR floor/ceiling) ──
            // The caller's stop_price is intentionally ignored — always compute from
            // TradingSettingService so the same mode/bounds config applies everywhere.
            $bars = DB::table('one_minute_prices')
                ->where('asset_type', 'stock')
                ->where('symbol', $symbol)
                ->orderByDesc('ts_est')
                ->limit(19)
                ->get(['high', 'low', 'price'])
                ->reverse()
                ->values()
                ->toArray();

            $atr = 0.0;
            $atrPct = 0.0;
            if (count($bars) >= 15) {
                $trueRanges = [];
                for ($i = 1; $i < count($bars); $i++) {
                    $high = (float) $bars[$i]->high;
                    $low = (float) $bars[$i]->low;
                    $prevClose = (float) $bars[$i - 1]->price;
                    $trueRanges[] = max(
                        $high - $low,
                        abs($high - $prevClose),
                        abs($low - $prevClose)
                    );
                }
                $count = min(14, count($trueRanges));
                $atrSum = 0.0;
                for ($i = count($trueRanges) - $count; $i < count($trueRanges); $i++) {
                    $atrSum += $trueRanges[$i];
                }
                $atr = $count > 0 ? $atrSum / $count : 0.0;
                $atrPct = ($atr > 0 && $entryPrice > 0) ? ($atr / $entryPrice) * 100.0 : 0.0;
            }

            $atrMultiplier = TradingSettingService::getStopLossAtrMultiplier();
            $atrMinPct = TradingSettingService::getStopLossAtrMinPct();
            $atrMaxPct = TradingSettingService::getStopLossAtrMaxPct();
            $stopLossMode = TradingSettingService::getStopLossMode();

            if ($stopLossMode === 'fixed') {
                $trailPct = TradingSettingService::getStopLossFixedPct();
            } else {
                // ATR mode with floor/ceiling — same logic as PlaceAlpacaOrderForHighScoreAlerts
                $calculatedStopPct = ($atr > 0 && $entryPrice > 0)
                    ? (($atr * $atrMultiplier) / $entryPrice) * 100.0
                    : $atrMinPct;
                $trailPct = max($atrMinPct, min($atrMaxPct, $calculatedStopPct));
            }

            $stopPrice = round($entryPrice * (1 - ($trailPct / 100)), 2);

            if ($explicitStopPrice !== null) {
                Log::channel('external-buy')->info('[ExternalBuy] Caller stop_price ignored — using computed stop', [
                    'symbol' => $symbol,
                    'caller_stop' => $explicitStopPrice,
                    'computed_stop' => $stopPrice,
                    'mode' => $stopLossMode,
                    'trail_pct' => $trailPct,
                ]);
            }

            $riskPerShare = max(1e-9, $entryPrice - $stopPrice);
            $totalCost = $shares * $entryPrice;

            // ── 5b. Calculate volume ratio ────────────────────────────
            $avgVolume30m = DB::table('one_minute_prices')
                ->where('symbol', $symbol)
                ->where('asset_type', 'stock')
                ->where('ts_est', '>=', $now->copy()->subMinutes(30)->format('Y-m-d H:i:s'))
                ->avg('volume');

            $recentVolume = DB::table('one_minute_prices')
                ->where('symbol', $symbol)
                ->where('asset_type', 'stock')
                ->where('ts_est', '>=', $now->copy()->subMinutes(5)->format('Y-m-d H:i:s'))
                ->avg('volume');

            $volRatio = ($avgVolume30m && $avgVolume30m > 0 && $recentVolume)
                ? round($recentVolume / $avgVolume30m, 2)
                : 1.0;

            // ── 6. Create trade alert (no order yet) ───────────────
            $alertId = false;
            try {
                DB::beginTransaction();

                $signal = [
                    'symbol' => $symbol,
                    'asset_type' => 'stock',
                    'signal_type' => 'EXTERNAL',
                    'signal_ts_est' => $asOfTsEst,
                    'meta' => ['notes' => $notes],
                ];

                $entry = [
                    'type' => $entryType,
                    'entry_ts_est' => $asOfTsEst,
                    'entry' => $entryPrice,
                    'stop' => $stopPrice,
                    'risk_pct' => $entryPrice > 0 ? round(($riskPerShare / $entryPrice) * 100.0, 3) : null,
                    'risk_per_share' => round($riskPerShare, 6),
                    'score' => 100,
                    'atr' => round($atr, 6),
                    'atr_pct' => round($atrPct, 3),
                    'vol_ratio' => $volRatio,
                    'suggested_trailing_stop' => round($atr * $atrMultiplier, 6),
                    'suggested_trailing_stop_pct' => round($trailPct, 3),
                    'targets' => $entryPrice > 0 ? [
                        '1R' => round($entryPrice + 1.0 * $riskPerShare, 6),
                        '2R' => round($entryPrice + 2.0 * $riskPerShare, 6),
                        '3R' => round($entryPrice + 3.0 * $riskPerShare, 6),
                    ] : null,
                ];

                $alertId = $this->alertWriter->upsertAlert(
                    $signal,
                    $entry,
                    $asOfTsEst,
                    config('app.trade_alert_external_version', 'external'),
                    'EXTERNAL',
                    true
                );

                if (! $alertId) {
                    DB::rollBack();

                    $existing = TradeAlert::where('symbol', $symbol)
                        ->whereDate('entry_ts_est', $now->toDateString())
                        ->orderByDesc('id')
                        ->first(['id', 'entry_ts_est', 'pipeline_run', 'dedupe_key', 'passed_ml', 'ml_win_prob']);

                    $reason = $existing
                        ? sprintf('Duplicate alert #%d exists for %s at %s.', $existing->id, $symbol, $existing->entry_ts_est)
                        : "Could not create alert for {$symbol}. Markets may be closed.";

                    return response()->json(['error' => $reason], 409);
                }

                DB::commit();
            } catch (\Throwable $e) {
                DB::rollBack();

                $lock->release();
                Cache::forget($recentlyPlacedKey);

                return response()->json(['error' => 'Failed to create alert: '.$e->getMessage()], 500);
            }

            // ── 7. Check ML threshold (uses existing score or default) ──
            // External alerts are scored asynchronously by the pipeline; if no
            // score has been computed yet, the trade is allowed through with the
            // existing score or null (which means pass-through).
            $mlThreshold = TradingSettingService::getPipelineMlThreshold('EXTERNAL');
            $mlWinProb = null;
            for ($i = 0; $i < 30; $i++) {
                $row = DB::table((new TradeAlert)->getTable())->where('id', $alertId)->value('ml_win_prob');
                if ($row !== null) {
                    $mlWinProb = (float) $row;
                    break;
                }
                usleep(1_000_000); // 1 second
            }

            if ($mlWinProb !== null && $mlWinProb < $mlThreshold) {
                $lock->release();
                Cache::forget($recentlyPlacedKey);

                Log::channel('external-buy')->warning('[ExternalBuy] Order blocked by ML threshold', [
                    'alert_id' => $alertId,
                    'symbol' => $symbol,
                    'ml_win_prob' => $mlWinProb,
                    'threshold' => $mlThreshold,
                ]);

                return response()->json([
                    'error' => "{$symbol} failed ML threshold (".($mlThreshold * 100).'%). Score: '.round($mlWinProb * 100, 1).'%. Alert #'.$alertId.' created but no order placed.',
                ], 400);
            }

            if ($mlWinProb === null) {
                Log::channel('external-buy')->error('[ExternalBuy] ML scoring returned null — blocking order', [
                    'alert_id' => $alertId,
                    'symbol' => $symbol,
                ]);

                return response()->json([
                    'error' => "{$symbol} ML scoring failed to produce a result. Alert #{$alertId} created but no order placed.",
                ], 400);
            }

            // ── 8. Place the Alpaca buy order ─────────────────────
            $limitPrice = null;
            if (TradingSettingService::isUseLimitOrdersEnabled()) {
                $quote = DB::connection('mysql')
                    ->table('latest_stock_quotes')
                    ->where('symbol', $symbol)
                    ->where('received_at_utc', '>=', now('UTC')->subMinutes(5))
                    ->first(['ask_price', 'bid_price']);

                if ($quote && (float) $quote->ask_price > 0) {
                    $multiplier = (float) config('trading.auto_alpaca_orders.marketable_limit_multiplier', 1.0005);
                    $bidPrice = (float) $quote->bid_price;
                    $askPrice = (float) $quote->ask_price;
                    $mid = ($bidPrice + $askPrice) / 2;

                    if ($bidPrice > 0) {
                        $limitPrice = max($mid, round($askPrice * $multiplier, 2));
                    } else {
                        $limitPrice = round($askPrice * $multiplier, 2);
                    }
                }
            }

            $alpacaService = app(\App\Services\AlpacaPythonService::class);
            $entryResult = $alpacaService->placeOrder(
                $symbol,
                (float) $shares,
                'buy',
                null,
                null,
                null,
                false,
                false,
                $limitPrice,
            );

            if (! $entryResult['success']) {
                $rawError = $entryResult['error'] ?? $entryResult['output'] ?? 'Unknown error';
                $friendlyError = $rawError;

                if (preg_match('/(\{.*\})/s', $rawError, $jsonMatch)) {
                    $parsed = json_decode($jsonMatch[1], true);
                    if ($parsed && isset($parsed['message'])) {
                        $friendlyError = ucfirst((string) $parsed['message']);
                    } elseif ($parsed && isset($parsed['reason'])) {
                        $friendlyError = str_replace('_', ' ', (string) $parsed['reason']);
                    }
                } elseif (preg_match('/ValueError:\s*(.+)/', $rawError, $m)) {
                    $friendlyError = $m[1];
                } elseif (preg_match('/Error:\s*(.+)/', $rawError, $m)) {
                    $friendlyError = $m[1];
                }

                Log::channel('external-buy')->error('[ExternalBuy] Alpaca order placement failed', [
                    'symbol' => $symbol,
                    'error' => $rawError,
                ]);

                return response()->json([
                    'error' => 'Order failed: '.trim($friendlyError),
                ], 500);
            }

            // ── 9. Parse order ID and create AlpacaOrder record ──
            $orderId = null;
            $orderData = null;
            if (! empty($entryResult['output'])) {
                if (preg_match('/(\{.*\})/s', $entryResult['output'], $matches)) {
                    $parsed = json_decode($matches[1], true);
                    if ($parsed && isset($parsed['order']['id'])) {
                        $orderId = $parsed['order']['id'];
                        $orderData = $parsed['order'];
                    }
                }
            }

            if ($orderId) {
                $isPaper = (bool) config('alpaca.paper_trading', true);
                $submittedAt = null;
                if (isset($orderData['submitted_at'])) {
                    try {
                        $submittedAt = now()->parse($orderData['submitted_at']);
                    } catch (\Throwable) {
                        $submittedAt = now();
                    }
                }

                AlpacaOrder::create([
                    'alpaca_order_id' => $orderId,
                    'client_order_id' => $orderData['client_order_id'] ?? null,
                    'trade_alert_id' => $alertId,
                    'paper' => $isPaper,
                    'is_paper' => $isPaper,
                    'symbol' => $symbol,
                    'side' => 'buy',
                    'qty' => $shares,
                    'status' => $orderData['status'] ?? 'new',
                    'order_type' => $orderData['type'] ?? 'market',
                    'time_in_force' => $orderData['time_in_force'] ?? 'day',
                    'submitted_at' => $submittedAt,
                    'limit_price' => $orderData['limit_price'] ?? null,
                    'stop_price' => $stopPrice,
                    'filled_qty' => $orderData['filled_qty'] ?? 0,
                    'filled_avg_price' => $orderData['filled_avg_price'] ?? null,
                ]);

                // Update TradeAlert with actual execution data so backtest results
                // show real dollar amounts instead of the estimated buying power.
                $actualFilledQty = (float) ($orderData['filled_qty'] ?? $shares);
                $actualFilledPrice = (float) ($orderData['filled_avg_price'] ?? $entryPrice);
                $actualCost = $actualFilledQty * $actualFilledPrice;

                TradeAlert::where('id', $alertId)->update([
                    'calculated_position_size' => round($actualCost, 2),
                ]);
            }

            Log::channel('external-buy')->info('[ExternalBuy] Order placed', [
                'symbol' => $symbol,
                'shares' => $shares,
                'price' => $entryPrice,
                'alert_id' => $alertId,
                'order_id' => $orderId,
            ]);

            $response = [
                'success' => true,
                'alert_id' => $alertId,
                'alpaca_order_id' => $orderId,
                'symbol' => $symbol,
                'shares' => $shares,
                'entry_price' => $entryPrice,
                'stop_price' => $stopPrice,
                'total_cost' => round($totalCost, 2),
                'message' => "Buy order placed for {$shares} shares of {$symbol}",
            ];

            $lock->release();

            Log::channel('external-buy')->info('[ExternalBuy] Response', $response);

            return response()->json($response);
        } catch (\Throwable $e) {
            // Ensure lock and marker are released on any unexpected error
            $lock->release();
            Cache::forget($recentlyPlacedKey);

            throw $e;
        }
    }
}
