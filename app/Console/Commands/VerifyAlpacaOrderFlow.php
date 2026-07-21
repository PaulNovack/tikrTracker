<?php

namespace App\Console\Commands;

use App\Models\AlpacaOrder;
use Illuminate\Console\Command;

class VerifyAlpacaOrderFlow extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'alpaca:verify-order-flow {--date= : Date to check (YYYY-MM-DD)}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Verify that every buy order has either a filled stop loss or EOD sell';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $date = $this->option('date') ?: now()->format('Y-m-d');

        $this->info("Verifying Alpaca order flow for {$date}");
        $this->newLine();

        // Get all filled buy orders for the date
        $buyOrders = AlpacaOrder::query()
            ->where('side', 'buy')
            ->where('status', 'filled')
            ->whereNotNull('filled_at')
            ->whereDate('filled_at', $date)
            ->orderBy('filled_at')
            ->get();

        if ($buyOrders->isEmpty()) {
            $this->info("No filled buy orders found for {$date}");

            return Command::SUCCESS;
        }

        $this->info("Found {$buyOrders->count()} filled buy orders");
        $this->newLine();

        $table = [];
        $orphanedBuys = [];
        $properlyExited = [];
        $stillOpen = [];

        foreach ($buyOrders as $buy) {
            // Look for corresponding sell orders
            $sellOrders = AlpacaOrder::query()
                ->where('symbol', $buy->symbol)
                ->where('side', 'sell')
                ->where('status', 'filled')
                ->where('filled_at', '>=', $buy->filled_at)
                ->whereDate('filled_at', $date)
                ->get();

            $stopLossSell = $sellOrders->firstWhere('order_type', 'stop');
            $marketSell = $sellOrders->firstWhere('order_type', 'market');

            // Check for pending stop loss orders
            $pendingStopLoss = AlpacaOrder::query()
                ->where('symbol', $buy->symbol)
                ->where('side', 'sell')
                ->where('order_type', 'stop')
                ->whereIn('status', ['pending_new', 'new', 'accepted'])
                ->exists();

            $exitType = 'NONE';
            $exitPrice = '-';
            $exitTime = '-';
            $status = '❌ ORPHANED';

            if ($stopLossSell) {
                $exitType = 'STOP LOSS';
                $exitPrice = '$'.number_format($stopLossSell->filled_avg_price, 2);
                $exitTime = $stopLossSell->filled_at->format('H:i:s');
                $status = '✓ Stop Loss';
                $properlyExited[] = $buy->symbol;
            } elseif ($marketSell) {
                $exitType = 'MARKET (EOD)';
                $exitPrice = '$'.number_format($marketSell->filled_avg_price, 2);
                $exitTime = $marketSell->filled_at->format('H:i:s');
                $status = '✓ EOD Sell';
                $properlyExited[] = $buy->symbol;
            } elseif ($pendingStopLoss) {
                $exitType = 'PENDING STOP';
                $status = '⏳ Still Open';
                $stillOpen[] = $buy->symbol;
            } else {
                $orphanedBuys[] = $buy->symbol;
            }

            $table[] = [
                'Symbol' => $buy->symbol,
                'Buy Price' => '$'.number_format($buy->filled_avg_price, 2),
                'Buy Time' => $buy->filled_at->format('H:i:s'),
                'Qty' => $buy->filled_qty,
                'Exit Type' => $exitType,
                'Exit Price' => $exitPrice,
                'Exit Time' => $exitTime,
                'Status' => $status,
            ];
        }

        $this->table(
            ['Symbol', 'Buy Price', 'Buy Time', 'Qty', 'Exit Type', 'Exit Price', 'Exit Time', 'Status'],
            $table
        );

        $this->newLine();
        $this->info('=== Summary ===');
        $this->info("Total Buy Orders: {$buyOrders->count()}");
        $this->info('Properly Exited: '.count($properlyExited).' ('.implode(', ', $properlyExited).')');

        if (! empty($stillOpen)) {
            $this->warn('Still Open: '.count($stillOpen).' ('.implode(', ', $stillOpen).')');
        }

        if (! empty($orphanedBuys)) {
            $this->error('Orphaned (No Exit): '.count($orphanedBuys).' ('.implode(', ', $orphanedBuys).')');
            $this->newLine();
            $this->warn('⚠️  Orphaned orders indicate missing stop loss or EOD sell orders');
            $this->warn('   This could be due to:');
            $this->warn('   - Testing/manual orders without proper flow');
            $this->warn('   - System errors during order placement');
            $this->warn('   - Canceled orders that should have executed');
        }

        $this->newLine();

        // Check for any canceled stop loss orders
        $canceledStops = AlpacaOrder::query()
            ->where('order_type', 'stop')
            ->where('side', 'sell')
            ->where('status', 'canceled')
            ->whereDate('updated_at', $date)
            ->get();

        if ($canceledStops->isNotEmpty()) {
            $this->warn('=== Canceled Stop Loss Orders ===');
            $canceledTable = [];
            foreach ($canceledStops as $stop) {
                $canceledTable[] = [
                    'Symbol' => $stop->symbol,
                    'Stop Price' => '$'.number_format($stop->stop_price, 2),
                    'Canceled At' => $stop->canceled_at?->format('H:i:s') ?? 'Unknown',
                ];
            }
            $this->table(['Symbol', 'Stop Price', 'Canceled At'], $canceledTable);
        }

        return Command::SUCCESS;
    }
}
