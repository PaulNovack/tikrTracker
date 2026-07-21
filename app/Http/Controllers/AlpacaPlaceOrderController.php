<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\AlpacaOrder;
use App\Models\TradeAlert;
use App\Services\StockPriceService;
use App\Services\Trading\TradeAlertWriterV1;
use App\Services\TradingSettingService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;
use Inertia\Response;

class AlpacaPlaceOrderController extends Controller
{
    public function __construct(
        private readonly StockPriceService $priceService,
        private readonly TradeAlertWriterV1 $alertWriter,
    ) {}

    /**
     * Show the Place Order form.
     */
    public function index(): Response
    {
        $today = Carbon::today('America/New_York');

        // Get today's active positions for reference
        $todayAlerts = TradeAlert::whereDate('entry_ts_est', $today)
            ->orderByDesc('entry_ts_est')
            ->limit(50)
            ->get(['id', 'symbol', 'entry_type', 'entry_ts_est', 'entry', 'stop', 'pipeline_run', 'ml_win_prob', 'passed_ml']);

        return Inertia::render('alpaca-place-order/index', [
            'todayAlerts' => $todayAlerts,
        ]);
    }

    /**
     * Look up a symbol and return current price, recent alerts, and order info.
     */
    public function lookup(Request $request): JsonResponse
    {
        $data = $request->validate([
            'symbol' => 'required|string|max:10',
        ]);

        $symbol = strtoupper($data['symbol']);

        // Get current price
        $priceData = $this->priceService->getLatestPrice($symbol);

        // Check if there's already an open buy order for this symbol
        $openOrder = AlpacaOrder::where('symbol', $symbol)
            ->where('side', 'buy')
            ->whereIn('status', ['new', 'partially_filled', 'accepted', 'pending_new'])
            ->first();

        // Check if a buy order was already filled today for this symbol
        $boughtToday = AlpacaOrder::where('symbol', $symbol)
            ->where('side', 'buy')
            ->where('status', 'filled')
            ->whereDate('filled_at', Carbon::today('America/New_York'))
            ->exists();

        // Check recent alerts for this symbol today
        $recentAlerts = TradeAlert::where('symbol', $symbol)
            ->orderByDesc('entry_ts_est')
            ->limit(10)
            ->get(['id', 'entry_type', 'entry_ts_est', 'entry', 'stop', 'pipeline_run', 'ml_win_prob', 'passed_ml', 'pnl_percent']);

        return response()->json([
            'symbol' => $symbol,
            'price' => $priceData['price'] ?? null,
            'price_timestamp' => $priceData['timestamp'] ?? null,
            'open_order' => $openOrder,
            'bought_today' => $boughtToday,
            'recent_alerts' => $recentAlerts,
        ]);
    }

    /**
     * Preview the order: calculates max shares, suggests entry price and stop.
     */
    public function preview(Request $request): JsonResponse
    {
        $data = $request->validate([
            'symbol' => 'required|string|max:10',
            'amount' => 'nullable|numeric|min:0',
            'shares' => 'nullable|integer|min:1',
        ]);

        $symbol = strtoupper($data['symbol']);
        $amount = isset($data['amount']) ? (float) $data['amount'] : null;
        $shares = isset($data['shares']) ? (int) $data['shares'] : null;

        $priceData = $this->priceService->getLatestPrice($symbol);

        if (! $priceData || ! isset($priceData['price'])) {
            return response()->json(['error' => "No price data found for {$symbol}"], 404);
        }

        $price = (float) $priceData['price'];

        // Calculate shares from amount if shares not specified
        if ($shares === null && $amount !== null) {
            $maxShares = $this->priceService->calculateMaxShares($amount, $price);
            $shares = $maxShares;
        }

        if ($shares === null) {
            $shares = $this->priceService->calculateMaxShares(500, $price); // default $500
        }

        $totalCost = $shares * $price;

        return response()->json([
            'symbol' => $symbol,
            'price' => $price,
            'price_timestamp' => $priceData['timestamp'] ?? null,
            'shares' => $shares,
            'total_cost' => round($totalCost, 2),
        ]);
    }

    /**
     * Create a trade alert and place an Alpaca buy order.
     */
    public function placeOrder(Request $request): JsonResponse
    {
        $data = $request->validate([
            'symbol' => 'required|string|max:10',
            'shares' => 'required|integer|min:0',
            'entry_price' => 'required|numeric|min:0',
            'stop_price' => 'nullable|numeric|min:0',
            'pipeline_run' => 'nullable|string|max:5',
            'pipeline_run' => 'nullable|string|max:10',
        ]);

        $symbol = strtoupper($data['symbol']);
        $entryPrice = (float) $data['entry_price'];
        $explicitStopPrice = isset($data['stop_price']) ? (float) $data['stop_price'] : null;
        $pipelineRun = $data['pipeline_run'] ?? 'MANUAL';
        $notes = $data['notes'] ?? '';

        $now = Carbon::now('America/New_York');
        $asOfTsEst = $now->format('Y-m-d H:i:s');

        // ──────────────────────────────────────────────────────────────
        // Block if this symbol was already bought today
        // ──────────────────────────────────────────────────────────────
        $boughtToday = AlpacaOrder::where('symbol', $symbol)
            ->where('side', 'buy')
            ->where('status', 'filled')
            ->whereDate('filled_at', $now->toDateString())
            ->exists();

        if ($boughtToday) {
            return response()->json([
                'error' => "{$symbol} was already bought today. Placing another order may trigger a wash trade.",
            ], 409);
        }

        // Also check for open buy orders (not yet filled)
        $openBuyOrder = AlpacaOrder::where('symbol', $symbol)
            ->where('side', 'buy')
            ->whereIn('status', ['new', 'partially_filled', 'accepted', 'pending_new'])
            ->exists();

        if ($openBuyOrder) {
            return response()->json([
                'error' => "{$symbol} already has an open buy order. Please wait for it to fill or cancel it first.",
            ], 409);
        }

        // ──────────────────────────────────────────────────────────────
        // Position sizing (shares = 0 means auto-calculate)
        // ──────────────────────────────────────────────────────────────
        $requestedShares = (int) $data['shares'];
        $autoSize = $requestedShares <= 0;

        // Fetch recent 5-minute avg dollar volume for liquidity check
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

        // Enforce minimum dollar volume
        $minDollarVol = TradingSettingService::getMinDollarVolumePerMinute();
        if ($minDollarVol > 0 && $recentAvgDollarVol < $minDollarVol) {
            return response()->json([
                'error' => "{$symbol} has insufficient liquidity (\$".number_format((int) $recentAvgDollarVol).'/min — min $'.number_format((int) $minDollarVol).').',
            ], 400);
        }

        // Max position size from settings (default $25k)
        $maxPositionCost = TradingSettingService::getMaxPositionSize();
        try {
            $bpService = app(\App\Services\AlpacaPythonService::class);
            $bpResult = $bpService->runScript('account_details.py');
            if ($bpResult['success'] && preg_match('/"buying_power":\s*([\d.]+)/', $bpResult['output'], $bpMatch)) {
                $maxPositionCost = min((float) $bpMatch[1] * 0.95, $maxPositionCost);
            }
        } catch (\Throwable) {
            // ignore, fall through to settings default
        }

        if ($autoSize) {
            // Auto-size: 10 % of recent 1-minute dollar volume, capped by max position cost
            $liquidityBasedCost = $recentAvgDollarVol * 0.10;
            $targetCost = min($liquidityBasedCost, $maxPositionCost);
            $shares = $entryPrice > 0 ? (int) floor($targetCost / $entryPrice) : 0;
            $shares = max(1, $shares);
        } else {
            // User specified share count — cap by max position cost
            $shares = $requestedShares;
            $maxAffordable = $entryPrice > 0 ? (int) floor($maxPositionCost / $entryPrice) : 0;
            if ($maxAffordable > 0 && $shares > $maxAffordable) {
                $shares = $maxAffordable;
            }
        }

        // Calculate ATR from recent 1-minute bars
        [$atr, $atrPct] = $this->calculateAtrFromRecentBars($symbol, $entryPrice);

        // Derive ATR-based stop using the same config-driven rules as other pipelines
        $atrMultiplier = TradingSettingService::getStopLossAtrMultiplier();
        $atrMinPct = TradingSettingService::getStopLossAtrMinPct();
        $atrMaxPct = TradingSettingService::getStopLossAtrMaxPct();

        $calculatedStopPct = ($atr > 0 && $entryPrice > 0)
            ? (($atr * $atrMultiplier) / $entryPrice) * 100.0
            : $atrMinPct;
        $trailPct = max($atrMinPct, min($atrMaxPct, $calculatedStopPct));

        $stopPrice = $explicitStopPrice ?? round($entryPrice * (1 - ($trailPct / 100)), 2);
        $riskPerShare = max(1e-9, $entryPrice - $stopPrice);
        $riskPct = $entryPrice > 0 ? round(($riskPerShare / $entryPrice) * 100.0, 3) : null;
        $suggestedTrailingStop = round($atr * $atrMultiplier, 6);
        $suggestedTrailingStopPct = round($trailPct, 3);
        $targets = $entryPrice > 0 ? [
            '1R' => round($entryPrice + 1.0 * $riskPerShare, 6),
            '2R' => round($entryPrice + 2.0 * $riskPerShare, 6),
            '3R' => round($entryPrice + 3.0 * $riskPerShare, 6),
        ] : null;

        if (! TradingSettingService::isPipelineRunCronEnabled('manual')) {
            return response()->json([
                'error' => 'Manual pipeline is off. Turn it on before placing manual Alpaca orders.',
                'symbol' => $symbol,
                'entry_ts_est' => $asOfTsEst,
                'pipeline_run' => $pipelineRun,
            ], 409);
        }

        try {
            DB::beginTransaction();

            // 1. Create a trade alert
            $signal = [
                'symbol' => $symbol,
                'asset_type' => 'stock',
                'signal_type' => 'MANUAL',
                'signal_ts_est' => $asOfTsEst,
                'meta' => [],
            ];

            $entry = [
                'type' => 'MANUAL',
                'entry_ts_est' => $asOfTsEst,
                'entry' => $entryPrice,
                'stop' => $stopPrice,
                'risk_pct' => $riskPct,
                'risk_per_share' => round($riskPerShare, 6),
                'score' => 100,
                'atr' => round($atr, 6),
                'atr_pct' => round($atrPct, 3),
                'vol_ratio' => null,
                'suggested_trailing_stop' => $suggestedTrailingStop,
                'suggested_trailing_stop_pct' => $suggestedTrailingStopPct,
                'targets' => $targets,
            ];

            $alertId = $this->alertWriter->upsertAlert(
                $signal,
                $entry,
                $asOfTsEst,
                'manual',
                $pipelineRun,
                true  // isRealtime — triggers ML scoring dispatch
            );

            if (! $alertId) {
                DB::rollBack();

                $existing = TradeAlert::where('symbol', $symbol)
                    ->whereDate('entry_ts_est', $now->toDateString())
                    ->orderByDesc('id')
                    ->first(['id', 'entry_ts_est', 'pipeline_run', 'dedupe_key', 'passed_ml', 'ml_win_prob']);

                $reason = 'Unknown';
                if ($existing) {
                    $reason = sprintf(
                        'Order not placed because a duplicate alert already exists for %s at %s (alert #%d, pipeline=%s, dedupe=%s). This is alert deduplication, not a position check.',
                        $symbol,
                        $existing->entry_ts_est,
                        $existing->id,
                        $existing->pipeline_run,
                        $existing->dedupe_key
                    );

                    $reason .= ' If markets are closed, no new 1-min price data will be available for a fresh alert today.';
                } elseif (! TradingSettingService::isPipelineRunCronEnabled('manual')) {
                    $reason = 'Manual pipeline is off. Turn it on before placing manual Alpaca orders.';
                } else {
                    $reason = sprintf(
                        'Order not placed for %s because a new trade alert could not be created. Markets may be closed, there may be no new 1-min price data for today, or the symbol may have no recent trading data.',
                        $symbol
                    );
                }

                return response()->json([
                    'error' => $reason,
                    'symbol' => $symbol,
                    'entry_ts_est' => $asOfTsEst,
                    'pipeline_run' => $pipelineRun,
                ], 409);
            }

            // Enforce ML threshold — only block if scoring completed AND failed
            $mlThreshold = TradingSettingService::getPipelineMlThreshold('MANUAL');
            $alertRecord = TradeAlert::find($alertId);
            if ($alertRecord && $alertRecord->passed_ml === 0 && $alertRecord->ml_win_prob !== null) {
                DB::rollBack();

                return response()->json([
                    'error' => "{$symbol} failed ML threshold (".($mlThreshold * 100).'%). Score: '.round($alertRecord->ml_win_prob * 100, 1).'%.',
                ], 400);
            }

            // 2. Check bid-ask spread before placing the order
            $quote = DB::connection('mysql')
                ->table('latest_stock_quotes')
                ->where('symbol', $symbol)
                ->where('received_at_utc', '>=', now('UTC')->subMinutes(5))
                ->first(['ask_price', 'bid_price']);

            if ($quote && (float) $quote->ask_price > 0 && (float) $quote->bid_price > 0) {
                $askPrice = (float) $quote->ask_price;
                $bidPrice = (float) $quote->bid_price;

                if ($askPrice > $bidPrice) {
                    $mid = ($bidPrice + $askPrice) / 2;
                    $spreadPct = (($askPrice - $bidPrice) / $mid) * 100;
                    $maxSpreadPct = TradingSettingService::getMaxSpreadPct();

                    if ($spreadPct > $maxSpreadPct) {
                        DB::rollBack();

                        return response()->json([
                            'error' => "{$symbol} spread is too wide ({$spreadPct}% > {$maxSpreadPct}% max). Bid: \${$bidPrice}, Ask: \${$askPrice}.",
                        ], 400);
                    }
                }
            }

            // 3. Place the Alpaca buy order (plain market order, no bracket).
            // Use a marketable limit price when limit orders are enabled, so the
            // manual order follows the same rules as automated pipelines.
            $limitPrice = null;
            if (TradingSettingService::isUseLimitOrdersEnabled()) {
                if ($quote && (float) $quote->ask_price > 0) {
                    $multiplier = (float) config('trading.auto_alpaca_orders.marketable_limit_multiplier', 1.0005);
                    $askPrice = (float) $quote->ask_price;
                    $bidPrice = (float) $quote->bid_price;
                    $mid = ($bidPrice + $askPrice) / 2;

                    if ($bidPrice > 0) {
                        // Use ceiling: max(mid, ask * multiplier) to stay marketable
                        $limitPrice = max($mid, round($askPrice * $multiplier, 2));
                    } else {
                        $limitPrice = round($askPrice * $multiplier, 2);
                    }
                }
            }

            // The automated trailing-stop system will handle stop-loss after fill.
            $alpacaService = app(\App\Services\AlpacaPythonService::class);
            $entryResult = $alpacaService->placeOrder(
                $symbol,
                (float) $shares,
                'buy',
                null,       // stopPrice (null = no bracket)
                null,       // takeProfit
                null,       // stopLimit
                false,      // stopOnly
                false,      // fractional
                $limitPrice, // limitPrice (null = market order)
            );
            if (! $entryResult['success']) {
                DB::rollBack();

                // Extract a human-readable message from the raw output (Python traceback or JSON response)
                $rawError = $entryResult['error'] ?? $entryResult['output'] ?? 'Unknown error';
                $friendlyError = $rawError;

                // Check if output is JSON (e.g. entry_rejected from buying power check)
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

                Log::error('[AlpacaPlaceOrder] Alpaca order placement failed', [
                    'symbol' => $symbol,
                    'error' => $rawError,
                ]);

                return response()->json([
                    'error' => 'Order failed: '.trim($friendlyError),
                ], 500);
            }

            // Parse order ID from Alpaca output (JSON: {"mode":"...","order":{"id":"uuid",...}})
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

            // 3. Create the AlpacaOrder record linking trade alert to order
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
            }

            DB::commit();

            Log::info('[AlpacaPlaceOrder] Manual order placed', [
                'symbol' => $symbol,
                'shares' => $shares,
                'price' => $entryPrice,
                'alert_id' => $alertId,
                'order_id' => $orderId,
            ]);

            return response()->json([
                'success' => true,
                'message' => "Buy order placed for {$shares} shares of {$symbol} at \${$entryPrice}",
                'alert_id' => $alertId,
                'order_id' => $orderId,
            ]);
        } catch (\Throwable $e) {
            DB::rollBack();
            Log::error('[AlpacaPlaceOrder] Failed to place order', [
                'symbol' => $symbol,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'error' => 'Failed to place order: '.$e->getMessage(),
            ], 500);
        }
    }

    /**
     * Calculate ATR from the last 14+ one-minute bars using the true range formula.
     *
     * @return array{0: float, 1: float} [atr, atr_pct]
     */
    private function calculateAtrFromRecentBars(string $symbol, float $entryPrice, int $period = 14): array
    {
        $bars = DB::table('one_minute_prices')
            ->where('asset_type', 'stock')
            ->where('symbol', $symbol)
            ->orderByDesc('ts_est')
            ->limit($period + 5)
            ->get(['high', 'low', 'price'])
            ->reverse()
            ->values()
            ->toArray();

        if (count($bars) < $period + 1) {
            return [0.0, 0.0];
        }

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

        $count = min($period, count($trueRanges));
        $atrSum = 0.0;
        for ($i = count($trueRanges) - $count; $i < count($trueRanges); $i++) {
            $atrSum += $trueRanges[$i];
        }

        $atr = $count > 0 ? $atrSum / $count : 0.0;
        $atrPct = ($atr > 0 && $entryPrice > 0) ? ($atr / $entryPrice) * 100.0 : 0.0;

        return [$atr, $atrPct];
    }

    /**
     * Calculate shares as 10% of the average dollar volume per minute.
     */
    private function calculateSharesFromVolume(string $symbol, float $entryPrice): int
    {
        $avgVol = DB::table('one_minute_prices')
            ->where('symbol', $symbol)
            ->where('asset_type', 'stock')
            ->where('ts_est', '>=', now('America/New_York')->subMinutes(30))
            ->avg('volume');

        if (! $avgVol || $avgVol <= 0 || $entryPrice <= 0) {
            return 1;
        }

        // 10% of 1-minute avg volume = target shares
        $shares = (int) floor(($avgVol * 0.10));

        return max(1, $shares);
    }
}
