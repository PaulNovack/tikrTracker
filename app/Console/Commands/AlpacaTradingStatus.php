<?php

namespace App\Console\Commands;

use App\Models\AlpacaOrder;
use App\Services\AlpacaPythonService;
use Illuminate\Console\Command;

class AlpacaTradingStatus extends Command
{
    protected $signature = 'alpaca:status {--detailed}';

    protected $description = 'Show current Alpaca trading status with position verification';

    public function handle(): int
    {
        $this->info('🔍 Alpaca Trading System Status');
        $this->newLine();

        // 1. Configuration
        $this->info('⚙️  Configuration:');
        $this->line('  Auto Orders: '.config('services.alpaca.auto_orders_enabled', 'false'));
        $this->line('  ML Threshold: '.config('services.alpaca.ml_threshold', 'N/A'));
        $this->line('  Position Size: $'.number_format(config('services.alpaca.position_size', 0)));
        $this->line('  Stop Loss: '.config('services.alpaca.stop_loss_pct', 'N/A').'%');
        $this->line('  Paper Trading: '.(config('services.alpaca.paper_trading') ? 'YES ✅' : 'NO ❌ LIVE MONEY'));

        // Check Alpaca account settings
        try {
            $alpacaService = app(AlpacaPythonService::class);
            $accountResult = $alpacaService->runScript('get_account.py');

            if ($accountResult['success']) {
                $accountData = json_decode($accountResult['output'], true);
                $shortingEnabled = $accountData['shorting_enabled'] ?? null;

                if ($shortingEnabled === false) {
                    $this->line('  Shorting Blocked: YES ✅ (Protected at broker level)');
                } elseif ($shortingEnabled === true) {
                    $this->line('  Shorting Blocked: NO ⚠️  (Shorting enabled - potential risk!)');
                } else {
                    $this->line('  Shorting Blocked: UNKNOWN');
                }
            }
        } catch (\Exception $e) {
            // Silently skip if can't check
        }

        $this->newLine();

        // 2. Today's Activity
        $this->info('📊 Today\'s Activity:');
        $todayBuys = AlpacaOrder::whereDate('created_at', today())
            ->where('side', 'buy')
            ->count();
        $todaySells = AlpacaOrder::whereDate('created_at', today())
            ->where('side', 'sell')
            ->count();

        $this->line("  Buy orders: {$todayBuys}");
        $this->line("  Sell orders: {$todaySells}");
        $this->newLine();

        // 3. Order Status Summary
        $this->info('📝 Order Status Breakdown:');
        $statuses = AlpacaOrder::selectRaw('status, side, count(*) as count')
            ->whereDate('created_at', '>=', today()->subDays(3))
            ->groupBy('status', 'side')
            ->get();

        foreach ($statuses as $stat) {
            $emoji = match ($stat->status) {
                'filled' => '✅',
                'partially_filled' => '⏳',
                'canceled', 'cancelled' => '❌',
                'pending_new' => '🔄',
                default => '❓'
            };
            $this->line("  {$emoji} {$stat->status} ({$stat->side}): {$stat->count}");
        }
        $this->newLine();

        // 4. Check for Issues
        $this->info('🚨 Potential Issues:');
        $issues = [];

        // Check for partially filled orders stuck for >1 hour
        $stuckPartials = AlpacaOrder::where('status', 'partially_filled')
            ->where('created_at', '<', now()->subHour())
            ->get();

        if ($stuckPartials->isNotEmpty()) {
            $issues[] = "⚠️  {$stuckPartials->count()} partially filled orders stuck >1 hour";
            foreach ($stuckPartials as $order) {
                $this->line("     - {$order->symbol}: {$order->filled_qty}/{$order->qty} shares (Order: {$order->alpaca_order_id})");
            }
        }

        // Check for orders without stop losses
        $buyOrdersWithoutStops = AlpacaOrder::where('side', 'buy')
            ->where('status', 'filled')
            ->whereDate('filled_at', '>=', today()->subDays(2))
            ->whereDoesntHave('stopLossOrder')
            ->get();

        if ($buyOrdersWithoutStops->isNotEmpty()) {
            $issues[] = "⚠️  {$buyOrdersWithoutStops->count()} filled buy orders without stop losses";
            foreach ($buyOrdersWithoutStops as $order) {
                $this->line("     - {$order->symbol}: {$order->filled_qty} shares @ \${$order->filled_avg_price} (Filled: {$order->filled_at})");
            }
        }

        // Check for open positions in database
        $dbPositions = AlpacaOrder::where('status', 'filled')
            ->selectRaw('symbol, SUM(CASE WHEN side="buy" THEN filled_qty ELSE -filled_qty END) as net_qty')
            ->groupBy('symbol')
            ->having('net_qty', '!=', 0)
            ->get();

        if ($dbPositions->isEmpty() && empty($issues)) {
            $this->line('  ✅ No issues detected');
        }
        $this->newLine();

        // 5. Position Verification (compare DB vs Alpaca)
        $this->info('📍 Position Verification (DB vs Alpaca):');

        if ($dbPositions->isNotEmpty()) {
            $this->line('  Database shows:');
            foreach ($dbPositions as $pos) {
                $emoji = $pos->net_qty > 0 ? '🟢' : '🔴';
                $this->line("    {$emoji} {$pos->symbol}: {$pos->net_qty} shares");
            }
            $this->newLine();
        }

        try {
            $alpacaService = app(AlpacaPythonService::class);
            $positionsResult = $alpacaService->runScript('get_positions.py');

            if ($positionsResult['success']) {
                $positions = json_decode($positionsResult['output'], true);

                if (! empty($positions['positions'])) {
                    $this->line('  Alpaca shows:');
                    foreach ($positions['positions'] as $symbol => $data) {
                        $qty = $data['qty'] ?? 0;
                        $emoji = $qty > 0 ? '🟢' : '🔴';
                        $this->line("    {$emoji} {$symbol}: {$qty} shares @ \${$data['avg_entry_price']} (Current: \${$data['current_price']})");
                    }
                    $this->newLine();

                    // Check for discrepancies
                    $dbSymbols = $dbPositions->pluck('symbol')->toArray();
                    $alpacaSymbols = array_keys($positions['positions']);

                    $onlyInDb = array_diff($dbSymbols, $alpacaSymbols);
                    $onlyInAlpaca = array_diff($alpacaSymbols, $dbSymbols);

                    if (! empty($onlyInDb) || ! empty($onlyInAlpaca)) {
                        $this->error('  ⚠️  DISCREPANCY DETECTED:');
                        if (! empty($onlyInDb)) {
                            $this->line('    - In DB but not Alpaca: '.implode(', ', $onlyInDb));
                        }
                        if (! empty($onlyInAlpaca)) {
                            $this->line('    - In Alpaca but not DB: '.implode(', ', $onlyInAlpaca));
                        }
                    } else {
                        $this->info('  ✅ Database matches Alpaca');
                    }
                } else {
                    $this->line('  ✅ Alpaca: No open positions');

                    if ($dbPositions->isNotEmpty()) {
                        $this->error('  ⚠️  DISCREPANCY: DB shows positions but Alpaca has none!');
                    }
                }
            } else {
                $this->error('  ❌ Could not fetch positions from Alpaca');
            }
        } catch (\Exception $e) {
            $this->error('  ❌ Error checking Alpaca positions: '.$e->getMessage());
        }

        $this->newLine();

        // 6. Detailed mode - show recent orders
        if ($this->option('detailed')) {
            $this->info('📋 Recent Orders (Last 24 Hours):');
            $recentOrders = AlpacaOrder::whereDate('created_at', '>=', today()->subDay())
                ->orderBy('created_at', 'desc')
                ->get();

            if ($recentOrders->isEmpty()) {
                $this->line('  No orders in last 24 hours');
            } else {
                $this->table(
                    ['Time', 'Symbol', 'Side', 'Qty', 'Price', 'Status'],
                    $recentOrders->map(fn ($o) => [
                        $o->created_at->format('H:i:s'),
                        $o->symbol,
                        $o->side,
                        $o->filled_qty.'/'.$o->qty,
                        $o->filled_avg_price ? '$'.number_format($o->filled_avg_price, 2) : '-',
                        $o->status,
                    ])
                );
            }
        }

        return 0;
    }
}
