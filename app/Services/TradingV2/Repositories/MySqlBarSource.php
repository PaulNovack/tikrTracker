<?php

namespace App\Services\TradingV2\Repositories;

use App\Services\TradingV2\Contracts\BarSourceInterface;
use Illuminate\Support\Facades\DB;

/**
 * Reads bars from five_minute_prices / one_minute_prices MySQL tables.
 * Used for backtest/offline path — allows full historical scanning.
 */
class MySqlBarSource implements BarSourceInterface
{
    public function __construct(
        private readonly bool $fullTable = false,
    ) {}

    /**
     * {@inheritDoc}
     */
    public function getBars(string $timeframe, string $symbol, string $asOfTsEst, int $lookbackMinutes, int $limit): array
    {
        $ny = new \DateTimeZone('America/New_York');
        $asOf = new \DateTime($asOfTsEst, $ny);
        $from = (clone $asOf)->modify("-{$lookbackMinutes} minutes");

        return $this->getBarsRange($timeframe, $symbol, $from->format('Y-m-d H:i:s'), $asOf->format('Y-m-d H:i:s'), $limit);
    }

    /**
     * {@inheritDoc}
     */
    public function getBarsRange(string $timeframe, string $symbol, string $fromTsEst, string $toTsEst, int $limit): array
    {
        $table = $this->resolveTable($timeframe);
        $tradeDate = substr($fromTsEst, 0, 10);

        return DB::table($table)
            ->select(['ts_est as tsEst', 'open', 'high', 'low', 'price as close', 'volume', 'vwap'])
            ->where('symbol', $symbol)
            ->where('trading_date_est', $tradeDate)
            ->where('ts_est', '>=', $fromTsEst)
            ->where('ts_est', '<=', $toTsEst)
            ->orderBy('ts_est')
            ->limit($limit)
            ->get()
            ->map(fn ($row) => (object) [
                'tsEst' => $row->tsEst,
                'open' => (float) $row->open,
                'high' => (float) $row->high,
                'low' => (float) $row->low,
                'close' => (float) $row->close,
                'volume' => (float) $row->volume,
                'vwap' => isset($row->vwap) ? (float) $row->vwap : null,
            ])
            ->all();
    }

    private function resolveTable(string $timeframe): string
    {
        $base = $timeframe === '5m' ? 'five_minute_prices' : 'one_minute_prices';

        return $this->fullTable ? "{$base}_full" : $base;
    }
}
