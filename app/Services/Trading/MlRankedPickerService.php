<?php

namespace App\Services\Trading;

use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;

class MlRankedPickerService
{
    public function scoreTodayAlerts(string $tradingDateEst, int $limit = 10): void
    {
        $modelPath = config('trading.ml_scoring.model_path', 'python_ml/models/winner_model_xgb.joblib');
        $cmd = [
            'python',
            base_path('python_ml/v2/score_trade_alerts.py'),
            '--model-in', base_path($modelPath),
            '--trading-date', $tradingDateEst,
            '--limit', (string) $limit,
        ];

        $p = new Process($cmd, base_path());
        $p->setTimeout(300);
        $p->run();

        if (! $p->isSuccessful()) {
            throw new \RuntimeException('ML scoring failed: '.$p->getErrorOutput());
        }
    }

    public function getTopPicks(string $tradingDateEst, int $limit = 10): array
    {
        return DB::table('trade_alerts')
            ->where('trading_date_est', $tradingDateEst)
            ->whereNotNull('ml_win_prob')
            // your hard gates still apply here:
            ->where('asset_type', 'stock')
            ->where('entry', '>=', 1)
            ->orderByDesc('ml_win_prob')
            ->limit($limit)
            ->get()
            ->toArray();
    }
}
