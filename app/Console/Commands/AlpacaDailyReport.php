<?php

namespace App\Console\Commands;

use App\Models\AlpacaOrder;
use Illuminate\Console\Command;

class AlpacaDailyReport extends Command
{
    protected $signature = 'alpaca:daily-report {--date=today}';

    protected $description = 'Generate daily trading report';

    public function handle(): int
    {
        $date = $this->option('date') === 'today' ? today() : now()->parse($this->option('date'));

        $this->info("📊 Alpaca Trading Report - {$date->format('Y-m-d')}");
        $this->newLine();

        // Get all orders for the day
        $orders = AlpacaOrder::whereDate('created_at', $date)->get();

        if ($orders->isEmpty()) {
            $this->line('No trading activity on this date.');

            return 0;
        }

        // Buy orders
        $buyOrders = $orders->where('side', 'buy')->where('status', 'filled');
        $sellOrders = $orders->where('side', 'sell')->where('status', 'filled');

        $this->info('🟢 Buy Orders: '.$buyOrders->count());
        if ($buyOrders->isNotEmpty()) {
            $totalBuyCost = $buyOrders->sum(fn ($o) => $o->filled_qty * $o->filled_avg_price);
            $this->line('   Total: $'.number_format($totalBuyCost, 2));

            $this->table(
                ['Symbol', 'Qty', 'Price', 'Cost', 'Time'],
                $buyOrders->map(fn ($o) => [
                    $o->symbol,
                    $o->filled_qty,
                    '$'.number_format($o->filled_avg_price, 2),
                    '$'.number_format($o->filled_qty * $o->filled_avg_price, 2),
                    $o->filled_at?->format('H:i:s') ?? $o->created_at->format('H:i:s'),
                ])
            );
        }
        $this->newLine();

        $this->info('🔴 Sell Orders: '.$sellOrders->count());
        if ($sellOrders->isNotEmpty()) {
            $totalSellProceeds = $sellOrders->sum(fn ($o) => $o->filled_qty * $o->filled_avg_price);
            $this->line('   Total: $'.number_format($totalSellProceeds, 2));

            $this->table(
                ['Symbol', 'Qty', 'Price', 'Proceeds', 'Time'],
                $sellOrders->map(fn ($o) => [
                    $o->symbol,
                    $o->filled_qty,
                    '$'.number_format($o->filled_avg_price, 2),
                    '$'.number_format($o->filled_qty * $o->filled_avg_price, 2),
                    $o->filled_at?->format('H:i:s') ?? $o->created_at->format('H:i:s'),
                ])
            );
        }
        $this->newLine();

        // Calculate P&L for closed positions
        $this->info('💰 Profit/Loss:');

        $symbols = $orders->where('status', 'filled')->pluck('symbol')->unique();

        $totalPL = 0;
        $trades = [];

        foreach ($symbols as $symbol) {
            $buys = $buyOrders->where('symbol', $symbol);
            $sells = $sellOrders->where('symbol', $symbol);

            $buyQty = $buys->sum('filled_qty');
            $sellQty = $sells->sum('filled_qty');
            $buyCost = $buys->sum(fn ($o) => $o->filled_qty * $o->filled_avg_price);
            $sellProceeds = $sells->sum(fn ($o) => $o->filled_qty * $o->filled_avg_price);

            if ($sellQty > 0) {
                $pl = $sellProceeds - ($buyCost * ($sellQty / $buyQty));
                $totalPL += $pl;

                $trades[] = [
                    'symbol' => $symbol,
                    'buy_qty' => $buyQty,
                    'sell_qty' => $sellQty,
                    'pl' => $pl,
                    'status' => $buyQty == $sellQty ? 'Closed' : 'Partial',
                ];
            }
        }

        if (! empty($trades)) {
            $this->table(
                ['Symbol', 'Buy Qty', 'Sell Qty', 'P&L', 'Status'],
                collect($trades)->map(fn ($t) => [
                    $t['symbol'],
                    $t['buy_qty'],
                    $t['sell_qty'],
                    ($t['pl'] >= 0 ? '+' : '').'$'.number_format($t['pl'], 2),
                    $t['status'],
                ])
            );

            $this->newLine();
            $emoji = $totalPL >= 0 ? '✅' : '❌';
            $this->line("{$emoji} Total P&L: ".($totalPL >= 0 ? '+' : '').'$'.number_format($totalPL, 2));
        } else {
            $this->line('   No closed positions today');
        }

        // Issues
        $this->newLine();
        $this->info('⚠️  Issues:');

        $partialFills = $orders->where('status', 'partially_filled');
        $canceled = $orders->whereIn('status', ['canceled', 'cancelled']);
        $rejected = $orders->where('status', 'rejected');

        if ($partialFills->count() > 0) {
            $this->line("   - {$partialFills->count()} partially filled orders");
        }
        if ($canceled->count() > 0) {
            $this->line("   - {$canceled->count()} canceled orders");
        }
        if ($rejected->count() > 0) {
            $this->line("   - {$rejected->count()} rejected orders");
        }

        if ($partialFills->isEmpty() && $canceled->isEmpty() && $rejected->isEmpty()) {
            $this->line('   None');
        }

        return 0;
    }
}
