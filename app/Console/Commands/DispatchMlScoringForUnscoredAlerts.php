<?php

namespace App\Console\Commands;

use App\Jobs\ScoreTradeAlertWithMl;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class DispatchMlScoringForUnscoredAlerts extends Command
{
    protected $signature = 'trade:dispatch-ml-scoring
        {--age=30 : Minimum age in seconds before an alert is eligible for catch-up scoring}
        {--limit=50 : Max alerts to dispatch per run}
    ';

    protected $description = "Dispatches ML scoring jobs for any of today's unscored alerts (catch-up for alerts inserted by concurrent backtests).";

    public function handle(): int
    {
        if (! config('trading.ml_scoring.enabled', true)) {
            return self::SUCCESS;
        }

        $todayEst = now('America/New_York')->format('Y-m-d');
        $ageSeconds = max(1, (int) $this->option('age'));
        $limit = max(1, (int) $this->option('limit'));

        $cutoff = now()->subSeconds($ageSeconds)->format('Y-m-d H:i:s');

        $alerts = DB::table('trade_alerts')
            ->select('id', 'pipeline_run')
            ->where('trading_date_est', $todayEst)
            ->whereNull('ml_scored_at')
            ->where('created_at', '<=', $cutoff)
            ->limit($limit)
            ->get();

        foreach ($alerts as $alert) {
            // Keep catch-up work off the live scoring queue to avoid delaying fresh alerts.
            ScoreTradeAlertWithMl::dispatch($alert->id, 'trade_alerts', $alert->pipeline_run)->onQueue('ml-scoring-catchup');
        }

        if ($alerts->count() > 0) {
            $this->line("Dispatched ML scoring for {$alerts->count()} unscored alert(s).");
        }

        return self::SUCCESS;
    }
}
