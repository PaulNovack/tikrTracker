<?php

namespace App\Console\Commands;

use App\Models\AlpacaOrder;
use App\Services\AlpacaPythonService;
use Illuminate\Console\Command;

class AlpacaSyncCheck extends Command
{
    protected $signature = 'alpaca:sync-check {--fix}';

    protected $description = 'Check for database/Alpaca sync issues and optionally fix them';

    public function handle(): int
    {
        $this->info('🔄 Checking for sync issues between database and Alpaca...');
        $this->newLine();

        $alpacaService = app(AlpacaPythonService::class);
        $issues = [];

        // 1. Check for orders with wrong status
        $this->info('1️⃣  Checking order statuses...');

        $recentOrders = AlpacaOrder::whereIn('status', ['pending_new', 'partially_filled', 'filled'])
            ->whereDate('created_at', '>=', today()->subDays(3))
            ->get();

        foreach ($recentOrders as $order) {
            try {
                $result = $alpacaService->runScript('check_order_status.py', [
                    'order_id' => $order->alpaca_order_id,
                ]);

                if ($result['success']) {
                    $statusData = json_decode($result['output'], true);
                    $alpacaStatus = $statusData['status'] ?? null;

                    if ($alpacaStatus && $alpacaStatus !== $order->status) {
                        $issues[] = [
                            'type' => 'status_mismatch',
                            'order' => $order,
                            'db_status' => $order->status,
                            'alpaca_status' => $alpacaStatus,
                            'alpaca_data' => $statusData,
                        ];

                        $this->line("  ⚠️  {$order->symbol} (Order {$order->alpaca_order_id}): DB={$order->status}, Alpaca={$alpacaStatus}");

                        if ($this->option('fix')) {
                            $order->update([
                                'status' => $alpacaStatus,
                                'filled_qty' => $statusData['filled_qty'] ?? $order->filled_qty,
                                'filled_avg_price' => $statusData['filled_avg_price'] ?? $order->filled_avg_price,
                                'paper' => (bool) config('alpaca.paper_trading', true),
                            ]);
                            $this->info("     ✅ Fixed: Updated to {$alpacaStatus}");
                        }
                    }
                }

                usleep(100000); // Rate limit: 100ms between requests
            } catch (\Exception $e) {
                $this->error("  ❌ Error checking order {$order->alpaca_order_id}: ".$e->getMessage());
            }
        }

        if (empty($issues)) {
            $this->info('  ✅ All order statuses match');
        }
        $this->newLine();

        // 2. Check for missing filled_at timestamps
        $this->info('2️⃣  Checking for filled orders without filled_at timestamps...');

        $missingTimestamps = AlpacaOrder::where('status', 'filled')
            ->whereNull('filled_at')
            ->whereDate('created_at', '>=', today()->subDays(3))
            ->get();

        if ($missingTimestamps->isNotEmpty()) {
            $this->line("  ⚠️  Found {$missingTimestamps->count()} filled orders without filled_at timestamp");

            if ($this->option('fix')) {
                foreach ($missingTimestamps as $order) {
                    $order->update([
                        'filled_at' => $order->updated_at ?? $order->created_at,
                        'paper' => (bool) config('alpaca.paper_trading', true),
                    ]);
                }
                $this->info('     ✅ Fixed: Set filled_at to updated_at');
            }
        } else {
            $this->info('  ✅ All filled orders have timestamps');
        }
        $this->newLine();

        // 3. Check for shorts (negative positions)
        $this->info('3️⃣  Checking for accidental short positions...');

        $positions = AlpacaOrder::where('status', 'filled')
            ->selectRaw('symbol, SUM(CASE WHEN side="buy" THEN filled_qty ELSE -filled_qty END) as net_qty')
            ->groupBy('symbol')
            ->having('net_qty', '<', 0)
            ->get();

        if ($positions->isNotEmpty()) {
            $this->error('  🚨 SHORT POSITIONS DETECTED:');
            foreach ($positions as $pos) {
                $this->line("     - {$pos->symbol}: {$pos->net_qty} shares (SHOULD NOT HAPPEN!)");
            }
        } else {
            $this->info('  ✅ No short positions detected');
        }
        $this->newLine();

        // 4. Summary
        if (! $this->option('fix') && ! empty($issues)) {
            $this->newLine();
            $this->warn('💡 Run with --fix flag to automatically correct these issues');
            $this->line('   php artisan alpaca:sync-check --fix');
        }

        if (empty($issues) && $missingTimestamps->isEmpty() && $positions->isEmpty()) {
            $this->newLine();
            $this->info('✅ All checks passed! System looks healthy.');
        }

        return 0;
    }
}
