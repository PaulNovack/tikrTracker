<?php

namespace App\Console\Commands;

use App\Events\TradeAlertMLScored;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class CreateTestAlert extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'test:create-alert {--symbol=} {--ml-score=0.75}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Create a test trade alert and trigger ML scoring event';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $symbols = ['AAPL', 'MSFT', 'GOOGL', 'AMZN', 'NVDA', 'TSLA', 'META', 'AMD', 'NFLX', 'INTC'];

        $symbol = $this->option('symbol') ?: $symbols[array_rand($symbols)];
        $mlScore = (float) $this->option('ml-score');

        // Generate realistic price data
        $entry = round(rand(50, 500) + (rand(0, 99) / 100), 2);
        $stop = round($entry * 0.99, 2); // 1% stop loss
        $riskPct = round((($entry - $stop) / $entry) * 100, 2);
        $riskPerShare = round($entry - $stop, 2);
        $atr = round($entry * (rand(50, 150) / 10000), 4); // 0.5% to 1.5% of entry
        $atrPct = round(($atr / $entry) * 100, 2);
        $score = rand(40, 95) + (rand(0, 9) / 10);
        $trailingStop = round($entry * (rand(15, 35) / 1000), 4); // 1.5% to 3.5%
        $trailingStopPct = round(($trailingStop / $entry) * 100, 2);

        $now = now()->setTimezone('America/New_York');

        // Create the alert
        $dedupeKey = "test-{$symbol}-".time();

        $alertId = DB::table('trade_alerts')->insertGetId([
            'symbol' => $symbol,
            'trading_date_est' => $now->format('Y-m-d'),
            'as_of_ts_est' => $now->format('Y-m-d H:i:s'),
            'signal_type' => 'TEST_MOMENTUM_BREAKOUT',
            'entry_type' => 'TEST_PIVOT',
            'time_of_day' => $now->format('H:i:s'),
            'entry' => $entry,
            'stop' => $stop,
            'risk_pct' => $riskPct,
            'risk_per_share' => $riskPerShare,
            'score' => $score,
            'atr' => $atr,
            'atr_pct' => $atrPct,
            'suggested_trailing_stop' => $trailingStop,
            'suggested_trailing_stop_pct' => $trailingStopPct,
            'entry_ts_est' => $now->format('Y-m-d H:i:s'),
            'signal_ts_est' => $now->format('Y-m-d H:i:s'),
            'ml_win_prob' => $mlScore,
            'ml_scored_at' => now(),
            'ml_model_version' => 'test-command',
            'dedupe_key' => $dedupeKey,
            'version' => 'v999.0',
            'pipeline_run' => 'A',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->info("\n✅ Test alert created successfully!");
        $this->info('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');
        $this->info("Alert ID:            {$alertId}");
        $this->info("Symbol:              {$symbol}");
        $this->info("Entry Price:         \${$entry}");
        $this->info("Stop Loss:           \${$stop}");
        $this->info("Risk %:              {$riskPct}%");
        $this->info("ATR:                 \${$atr}");
        $this->info("ATR %:               {$atrPct}%");
        $this->info("Entry Score:         {$score}");
        $this->info("Trailing Stop:       \${$trailingStop}");
        $this->info("Trailing Stop %:     {$trailingStopPct}%");
        $this->info("ML Score:            {$mlScore}%");
        $this->info('Signal Type:         TEST_MOMENTUM_BREAKOUT');
        $this->info('Entry Type:          TEST_PIVOT');
        $this->info('Version:             v999.0');
        $this->info("━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n");

        // Fire the ML scored event
        event(new TradeAlertMLScored($alertId, $symbol, $mlScore, 'test-command'));

        $this->info('✓ Fired TradeAlertMLScored event');

        if ($mlScore >= 0.65) {
            $this->info('✓ ML score >= 65%, order should be placed');
            $this->info('  Check: http://127.0.0.1:8080/alpaca-orders');
            $this->info('  Check: http://127.0.0.1:8080/trade-alerts');

            // Wait a moment and check if order was created
            sleep(2);
            $order = DB::table('alpaca_orders')
                ->where('notes', 'like', "%alert_id:{$alertId}%")
                ->first();

            if ($order) {
                $this->info("✓ Order created: {$order->symbol} {$order->side} {$order->qty} shares");
            } else {
                $this->warn('⚠ No order found yet - queue may be processing');
            }
        } else {
            $this->warn('ML score < 65%, no order will be placed');
        }

        return 0;
    }
}
