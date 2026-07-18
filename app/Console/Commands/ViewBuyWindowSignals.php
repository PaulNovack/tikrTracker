<?php

namespace App\Console\Commands;

use App\Models\BuyWindowSignal;
use Carbon\Carbon;
use Illuminate\Console\Command;

class ViewBuyWindowSignals extends Command
{
    protected $signature = 'buy-window:signals 
                            {--date= : Filter by date (YYYY-MM-DD)}
                            {--symbol= : Filter by symbol}
                            {--min-score=5 : Minimum score}
                            {--limit=50 : Max results to show}
                            {--optimal-only : Show only signals at optimal time (10:15 AM)}';

    protected $description = 'View stored buy window signals from database';

    public function handle(): int
    {
        $query = BuyWindowSignal::query();

        // Apply filters
        if ($date = $this->option('date')) {
            $start = Carbon::parse($date)->startOfDay();
            $end = Carbon::parse($date)->endOfDay();
            $query->whereBetween('signal_time', [$start, $end]);
        }

        if ($symbol = $this->option('symbol')) {
            $query->where('symbol', strtoupper($symbol));
        }

        if ($minScore = $this->option('min-score')) {
            $query->where('score', '>=', $minScore);
        }

        if ($this->option('optimal-only')) {
            $query->where('is_optimal_time', true);
        }

        $limit = (int) $this->option('limit');

        // Get results
        $signals = $query->orderBy('signal_time', 'desc')
            ->orderBy('score', 'desc')
            ->limit($limit)
            ->get();

        if ($signals->isEmpty()) {
            $this->warn('No signals found matching criteria.');

            return 0;
        }

        $this->info("Found {$signals->count()} signals");
        $this->newLine();

        // Display table
        $headers = ['Time', 'Symbol', 'Score', 'Price', 'Range%', 'Vol Surge', 'VWAP', 'Optimal'];
        $rows = [];

        foreach ($signals as $signal) {
            $rows[] = [
                $signal->signal_time->format('Y-m-d H:i'),
                $signal->symbol,
                $signal->score,
                '$'.number_format($signal->last_price, 2),
                number_format($signal->range_pct, 2).'%',
                number_format($signal->volume_surge, 1).'x',
                '$'.number_format($signal->vwap, 2),
                $signal->is_optimal_time ? '✓' : '',
            ];
        }

        $this->table($headers, $rows);

        // Show summary stats
        $this->newLine();
        $this->info('=== Summary ===');
        $this->info('Avg Score: '.round($signals->avg('score'), 1));
        $this->info('Avg Volume Surge: '.round($signals->avg('volume_surge'), 1).'x');
        $this->info('Optimal Time Signals: '.$signals->where('is_optimal_time', true)->count());

        // Show top reasons
        $allReasons = [];
        foreach ($signals as $signal) {
            foreach ($signal->reasons as $reason) {
                $allReasons[] = $reason;
            }
        }

        $reasonCounts = array_count_values($allReasons);
        arsort($reasonCounts);

        $this->newLine();
        $this->info('Top Scoring Reasons:');
        foreach (array_slice($reasonCounts, 0, 5, true) as $reason => $count) {
            $this->line("  • $reason ($count times)");
        }

        return 0;
    }
}
