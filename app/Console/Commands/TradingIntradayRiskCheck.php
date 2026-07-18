<?php

namespace App\Console\Commands;

use App\Models\AlpacaOrder;
use App\Services\AlpacaPythonService;
use App\Services\TradingSettingService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Runs every 15 minutes during trading hours (9:45 AM – 2:30 PM ET).
 *
 * Calculates today's actual closed P&L from filled Alpaca order pairs.
 * If it exceeds the configured intraday loss halt limit, orders are disabled
 * immediately via TradingSettingService — no worker restart needed.
 *
 * Complementary to the circuit breaker (which fires on 3 rapid stops in a
 * window) and the end-of-day auto risk check (which switches paper trading).
 * This one fires on cumulative dollar loss mid-day.
 */
class TradingIntradayRiskCheck extends Command
{
    protected $signature = 'trading:intraday-risk-check
                            {--dry-run : Log what would happen without making changes}';

    protected $description = 'Intraday risk check: disable orders if actual closed P&L falls below the halt limit. On halt, also closes any open positions that are more than 1R underwater, leaving profitable positions to run.';

    public function handle(AlpacaPythonService $alpacaPythonService): int
    {
        $isDryRun = (bool) $this->option('dry-run');

        $hourEst = (int) now()->timezone('America/New_York')->format('H');

        if ($hourEst < 11) {
            $haltLimit = TradingSettingService::getIntradayHaltLimitPre11am();
            $window = 'pre-11am';
        } elseif ($hourEst < 13) {
            $haltLimit = TradingSettingService::getIntradayHaltLimit11amTo1pm();
            $window = '11am-1pm';
        } else {
            $haltLimit = TradingSettingService::getIntradayHaltLimitPost1pm();
            $window = 'post-1pm';
        }

        if ($isDryRun) {
            $this->warn('[DRY RUN] No changes will be made.');
        }

        // Paper trading generates no real losses — skip halting to collect more training data.
        // Still run the P&L calculation and log whether a halt would have fired.
        if (TradingSettingService::isPaperTrading()) {
            $todayPL = $this->calculateTodayClosedPL();
            $this->info('Paper trading mode — intraday halt skipped.');

            if ($todayPL <= $haltLimit) {
                $this->warn("[PAPER] Would have halted: closed P&L \${$todayPL} ≤ limit \${$haltLimit} [{$window}]");
                Log::warning('[IntradayRisk][PAPER] WOULD HAVE HALTED', [
                    'window' => $window,
                    'today_closed_pl' => $todayPL,
                    'halt_limit' => $haltLimit,
                ]);
            } else {
                Log::info('[IntradayRisk][PAPER] Check passed (paper mode)', [
                    'window' => $window,
                    'today_closed_pl' => $todayPL,
                    'halt_limit' => $haltLimit,
                ]);
            }

            return 0;
        }

        // If orders are already disabled, nothing to do.
        if (! TradingSettingService::isOrdersEnabled()) {
            $this->info('Orders already disabled. Nothing to do.');

            return 0;
        }

        $todayPL = $this->calculateTodayClosedPL();
        $this->line("Time window: {$window}");
        $this->line('Today closed P&L: $'.number_format($todayPL, 2));
        $this->line('Intraday halt limit: $'.number_format($haltLimit, 2));

        if ($todayPL <= $haltLimit) {
            $reason = sprintf(
                'Intraday loss halt [%s]: closed P&L $%s ≤ halt limit $%s',
                $window,
                number_format($todayPL, 2),
                number_format($haltLimit, 2)
            );

            $this->warn("HALT: {$reason}");

            Log::warning('[IntradayRisk] Disabling orders', [
                'reason' => $reason,
                'window' => $window,
                'today_closed_pl' => $todayPL,
                'halt_limit' => $haltLimit,
            ]);

            if (! $isDryRun) {
                TradingSettingService::disableOrders($reason);
                $this->info('✅ Orders disabled. Re-enable via Trading Settings page when ready.');
                $this->closeUnderperformingPositions($alpacaPythonService);
            }

            return 0;
        }

        $this->info('Intraday check passed. Orders remain enabled.');

        Log::info('[IntradayRisk] Check passed', [
            'window' => $window,
            'today_closed_pl' => $todayPL,
            'halt_limit' => $haltLimit,
        ]);

        return 0;
    }

    /**
     * On halt: close any open positions that are more than 1R (1 ATR) underwater.
     *
     * Strategy: halt new orders + close losers, leave winners running.
     * "1R underwater" means current price has dropped more than 1 ATR below entry.
     * Positions still above that threshold are left alone — stops will protect them.
     *
     * Steps per losing position:
     *   1. Cancel the pending stop order (frees up the shares in Alpaca)
     *   2. Place a market sell to exit immediately
     */
    private function closeUnderperformingPositions(AlpacaPythonService $alpacaPythonService): void
    {
        $isPaper = TradingSettingService::isPaperTrading();

        // Load today's open buy positions (buys with no matching filled sell)
        $openBuys = AlpacaOrder::query()
            ->from('alpaca_orders AS buy')
            ->where('buy.side', 'buy')
            ->where('buy.status', 'filled')
            ->where('buy.is_paper', $isPaper)
            ->whereDate('buy.filled_at', today())
            ->whereNotExists(function ($q) {
                $q->from('alpaca_orders AS sell')
                    ->whereColumn('sell.symbol', 'buy.symbol')
                    ->where('sell.side', 'sell')
                    ->where('sell.status', 'filled')
                    ->whereColumn('sell.created_at', '>', 'buy.created_at');
            })
            ->select('buy.alpaca_order_id', 'buy.symbol', 'buy.filled_avg_price', 'buy.filled_qty', 'buy.atr')
            ->get();

        if ($openBuys->isEmpty()) {
            $this->info('[IntradayRisk] No open positions to evaluate.');

            return;
        }

        foreach ($openBuys as $buy) {
            $entry = (float) $buy->filled_avg_price;
            $qty = (float) $buy->filled_qty;
            $atr = (float) $buy->atr;
            $symbol = $buy->symbol;

            // Fetch current price from Alpaca
            $positionResult = $alpacaPythonService->checkPosition($symbol);

            if (! $positionResult['success']) {
                $this->warn("[IntradayRisk] Could not fetch position for {$symbol} — skipping.");

                continue;
            }

            $positionData = json_decode($positionResult['output'], true);
            $currentPrice = (float) ($positionData['current_price'] ?? 0);

            if ($currentPrice <= 0) {
                $this->warn("[IntradayRisk] Invalid current price for {$symbol} — skipping.");

                continue;
            }

            $unrealisedPL = ($currentPrice - $entry) * $qty;
            $rMultiple = $atr > 0 ? ($currentPrice - $entry) / $atr : 0;

            // Only close if more than 1R underwater
            if ($rMultiple >= -1.0) {
                $this->line("[IntradayRisk] {$symbol}: R={$rMultiple} — within 1R, leaving stop in place.");

                continue;
            }

            $this->warn(sprintf(
                '[IntradayRisk] %s: %.2fR underwater ($%.2f unrealised) — cancelling stop & selling.',
                $symbol,
                $rMultiple,
                $unrealisedPL
            ));

            // Step 1: cancel the pending stop order for this symbol
            $cancelResult = $alpacaPythonService->cancelOrdersBySymbol($symbol);

            if ($cancelResult['success']) {
                $this->line("[IntradayRisk] {$symbol}: stop order cancelled.");
                sleep(1); // Allow Alpaca to free the shares
            } else {
                $this->warn("[IntradayRisk] {$symbol}: failed to cancel stop — attempting market sell anyway.");
            }

            // Step 2: place a market sell to exit immediately
            $sellResult = $alpacaPythonService->placeOrder(
                symbol: $symbol,
                qty: $qty,
                side: 'sell',
            );

            if ($sellResult['success']) {
                $this->info("[IntradayRisk] {$symbol}: market sell placed for {$qty} shares.");
                Log::warning('[IntradayRisk] Closed underwater position on halt', [
                    'symbol' => $symbol,
                    'entry' => $entry,
                    'current_price' => $currentPrice,
                    'r_multiple' => round($rMultiple, 2),
                    'unrealised_pl' => round($unrealisedPL, 2),
                    'qty' => $qty,
                ]);
            } else {
                $this->error("[IntradayRisk] {$symbol}: failed to place market sell.");
                Log::error('[IntradayRisk] Failed to close underwater position on halt', [
                    'symbol' => $symbol,
                    'error' => $sellResult['error'] ?? 'unknown',
                ]);
            }
        }
    }

    /**
     * Sum closed P&L for today from filled live buy+stop pairs.
     */
    private function calculateTodayClosedPL(): float
    {
        $pairs = AlpacaOrder::query()
            ->selectRaw('(sell.filled_avg_price - buy.filled_avg_price) * sell.filled_qty AS pl')
            ->from('alpaca_orders AS sell')
            ->join('alpaca_orders AS buy', 'buy.alpaca_order_id', '=', 'sell.parent_alpaca_order_id')
            ->where('sell.side', 'sell')
            ->where('sell.order_type', 'stop')
            ->where('sell.status', 'filled')
            ->whereDate('sell.filled_at', today())
            ->where('buy.is_paper', TradingSettingService::isPaperTrading())
            ->whereNotNull('sell.filled_avg_price')
            ->whereNotNull('buy.filled_avg_price')
            ->get();

        return (float) $pairs->sum('pl');
    }
}
