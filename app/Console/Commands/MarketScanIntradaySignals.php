<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

final class MarketScanIntradaySignals extends Command
{
    protected $signature = 'market:scan-intraday-signals
        {asset_type=stock : stock|crypto}
        {--tf=1m : 1m|5m}
        {--asOf= : "YYYY-mm-dd HH:ii:ss" in ts_est timezone (UTC-5 fixed). Default: now}
        {--rsiPeriod=14 : RSI period (Wilder smoothing)}
        {--volAvg=20 : Volume average window (bars)}
        {--minVolMult=1.2 : Volume must exceed avg*mult for VWAP reclaim setup}
        {--rsiCross=40 : RSI cross threshold for VWAP reclaim setup}
        {--pullLow=40 : Pullback RSI low bound}
        {--pullHigh=45 : Pullback RSI high bound}
        {--failLevel=60 : RSI failure level for exit condition}
        {--emaFast=9 : Fast EMA length (e.g. 9 or 10)}
        {--emaSlow=21 : Slow EMA length (e.g. 21 or 20)}
        {--maxSymbols=0 : 0 = unlimited, else stop after N symbols matched}
        {--csv=1 : 1=CSV output, 0=pretty table}';

    protected $description = 'Scan intraday signals: (1) RSI cross up + VWAP reclaim + vol>avg, (2) RSI pullback + EMA hold, (3) RSI fail + EMA cross down';

    public function handle(): int
    {
        $assetType = strtolower((string) $this->argument('asset_type'));
        $tf = strtolower((string) $this->option('tf'));
        $asOfOpt = $this->option('asOf');

        $rsiPeriod = (int) $this->option('rsiPeriod');
        $volAvgN = (int) $this->option('volAvg');
        $minVolMult = (float) $this->option('minVolMult');

        $rsiCross = (float) $this->option('rsiCross');
        $pullLow = (float) $this->option('pullLow');
        $pullHigh = (float) $this->option('pullHigh');
        $failLevel = (float) $this->option('failLevel');

        $emaFastN = (int) $this->option('emaFast');
        $emaSlowN = (int) $this->option('emaSlow');

        $maxSymbols = (int) $this->option('maxSymbols');
        $csv = ((string) $this->option('csv')) !== '0';

        if (! in_array($assetType, ['stock', 'crypto'], true)) {
            $this->error('asset_type must be stock or crypto');

            return self::FAILURE;
        }
        if (! in_array($tf, ['1m', '5m'], true)) {
            $this->error('--tf must be 1m or 5m');

            return self::FAILURE;
        }
        if ($emaFastN < 2 || $emaSlowN < 2 || $emaFastN >= $emaSlowN) {
            $this->error('--emaFast and --emaSlow must be >=2 and emaFast < emaSlow (ex: 9/21 or 10/20)');

            return self::FAILURE;
        }

        $table = $tf === '1m' ? 'one_minute_prices' : 'five_minute_prices';

        // ts_est in your schema is UTC - 5 hours (fixed "EST"). We'll default "now" accordingly.
        $asOf = $asOfOpt ? (string) $asOfOpt : $this->nowEstFixedUtcMinus5();
        $tradeDate = substr($asOf, 0, 10);
        $sessionStart = $tradeDate.' 09:30:00';

        $sql = "
            SELECT symbol, ts_est, price, `open`, high, low, volume
            FROM {$table}
            WHERE asset_type = :atype
              AND ts_est >= :start_ts
              AND ts_est <= :asof_ts
            ORDER BY symbol ASC, ts_est ASC
        ";

        $pdo = DB::connection()->getPdo();
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            ':atype' => $assetType,
            ':start_ts' => $sessionStart,
            ':asof_ts' => $asOf,
        ]);

        $out = [];
        $matchedSymbols = 0;

        $curSym = null;

        // VWAP running sums
        $cumPV = 0.0;
        $cumVol = 0.0;

        // EMAs
        $emaFast = null;
        $emaSlow = null;

        // RSI Wilder
        $prevPrice = null;
        $avgGain = null;
        $avgLoss = null;
        $rsi = null;

        // Rolling volume avg
        $volQueue = [];
        $volSum = 0.0;

        // prev/last snapshots per symbol
        $prev = $this->blankSnap();
        $last = $this->blankSnap();

        // EMA alphas
        $alphaFast = 2.0 / ($emaFastN + 1.0);
        $alphaSlow = 2.0 / ($emaSlowN + 1.0);

        while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
            $sym = (string) $row['symbol'];
            $ts = (string) $row['ts_est'];
            $price = (float) $row['price'];
            $vol = isset($row['volume']) ? (float) $row['volume'] : 0.0;

            // boundary: evaluate previous symbol
            if ($curSym !== null && $sym !== $curSym) {
                $maybe = $this->evaluateSymbol(
                    $curSym, $prev, $last,
                    $rsiCross, $pullLow, $pullHigh, $failLevel,
                    $minVolMult,
                    $emaFastN, $emaSlowN
                );
                if ($maybe !== null) {
                    $out[] = $maybe;
                    $matchedSymbols++;
                    if ($maxSymbols > 0 && $matchedSymbols >= $maxSymbols) {
                        break;
                    }
                }

                // reset per-symbol state
                $cumPV = 0.0;
                $cumVol = 0.0;
                $emaFast = null;
                $emaSlow = null;
                $prevPrice = null;
                $avgGain = null;
                $avgLoss = null;
                $rsi = null;
                $volQueue = [];
                $volSum = 0.0;
                $prev = $this->blankSnap();
                $last = $this->blankSnap();
            }
            $curSym = $sym;

            // VWAP (close*vol)
            $cumPV += $price * $vol;
            $cumVol += $vol;
            $vwap = ($cumVol > 0.0) ? ($cumPV / $cumVol) : null;

            // EMAs
            if ($emaFast === null) {
                $emaFast = $price;
                $emaSlow = $price;
            } else {
                $emaFast = ($price * $alphaFast) + ($emaFast * (1.0 - $alphaFast));
                $emaSlow = ($price * $alphaSlow) + ($emaSlow * (1.0 - $alphaSlow));
            }

            // Rolling vol avg
            $volQueue[] = $vol;
            $volSum += $vol;
            $volAvg = null;
            if (count($volQueue) > 0) {
                if (count($volQueue) > (int) $this->option('volAvg')) {
                    $volSum -= array_shift($volQueue);
                }
                $volAvg = $volSum / count($volQueue);
            }

            // RSI Wilder (lightweight streaming)
            $prevRsi = $rsi;

            if ($prevPrice === null) {
                $prevPrice = $price;
            } else {
                $delta = $price - $prevPrice;
                $gain = $delta > 0 ? $delta : 0.0;
                $loss = $delta < 0 ? abs($delta) : 0.0;

                if ($avgGain === null || $avgLoss === null) {
                    $avgGain = $gain;
                    $avgLoss = $loss;
                } else {
                    $avgGain = (($avgGain * ($rsiPeriod - 1)) + $gain) / $rsiPeriod;
                    $avgLoss = (($avgLoss * ($rsiPeriod - 1)) + $loss) / $rsiPeriod;
                }

                if ($avgLoss == 0.0) {
                    $rsi = 100.0;
                } else {
                    $rs = $avgGain / $avgLoss;
                    $rsi = 100.0 - (100.0 / (1.0 + $rs));
                }

                $prevPrice = $price;
            }

            // shift snapshots
            $prev = $last;
            $last = [
                'ts' => $ts,
                'price' => $price,
                'vwap' => $vwap,
                'vol' => $vol,
                'volAvg' => $volAvg,
                'emaF' => $emaFast,
                'emaS' => $emaSlow,
                'rsi' => $rsi,
                'prevRsi' => $prevRsi, // convenience
            ];
        }

        // evaluate last symbol
        if ($curSym !== null && ($maxSymbols === 0 || $matchedSymbols < $maxSymbols)) {
            $maybe = $this->evaluateSymbol(
                $curSym, $prev, $last,
                $rsiCross, $pullLow, $pullHigh, $failLevel,
                $minVolMult,
                $emaFastN, $emaSlowN
            );
            if ($maybe !== null) {
                $out[] = $maybe;
            }
        }

        if ($csv) {
            $this->printCsv($out);
        } else {
            $this->table(
                ['symbol', 'ts_est', 'price', 'rsi', 'ema_fast', 'ema_slow', 'vwap', 'vol', 'vol_avg', 'signals'],
                array_map(fn ($r) => [
                    $r['symbol'], $r['ts_est'], $r['price'], $r['rsi'],
                    $r['ema_fast'], $r['ema_slow'],
                    $r['vwap'], $r['vol'], $r['vol_avg'], $r['signals'],
                ], $out)
            );
        }

        $this->info('Done. Matches='.count($out)." asOf={$asOf} tf={$tf} asset_type={$assetType} EMA={$emaFastN}/{$emaSlowN}");

        return self::SUCCESS;
    }

    private function blankSnap(): array
    {
        return [
            'ts' => null, 'price' => null, 'vwap' => null,
            'vol' => null, 'volAvg' => null,
            'emaF' => null, 'emaS' => null,
            'rsi' => null, 'prevRsi' => null,
        ];
    }

    private function evaluateSymbol(
        string $symbol,
        array $prev,
        array $last,
        float $rsiCross,
        float $pullLow,
        float $pullHigh,
        float $failLevel,
        float $minVolMult,
        int $emaFastN,
        int $emaSlowN
    ): ?array {
        if ($prev['ts'] === null || $last['ts'] === null) {
            return null;
        }
        if ($prev['rsi'] === null || $last['rsi'] === null) {
            return null;
        }
        if ($prev['vwap'] === null || $last['vwap'] === null) {
            return null;
        }
        if ($prev['emaF'] === null || $last['emaF'] === null) {
            return null;
        }
        if ($prev['emaS'] === null || $last['emaS'] === null) {
            return null;
        }
        if ($prev['price'] === null || $last['price'] === null) {
            return null;
        }

        $signals = [];

        // 1) RSI crosses above threshold + VWAP reclaim + vol>avg*mult
        $rsiCrossUp = ($prev['rsi'] < $rsiCross) && ($last['rsi'] >= $rsiCross);
        $vwapReclaim = ($prev['price'] < $prev['vwap']) && ($last['price'] >= $last['vwap']);
        $volOk = ($last['volAvg'] !== null && $last['volAvg'] > 0.0)
            ? ($last['vol'] > ($last['volAvg'] * $minVolMult))
            : false;

        if ($rsiCrossUp && $vwapReclaim && $volOk) {
            $signals[] = "ENTRY_VWAP_RSI{$rsiCross}_EMA{$emaFastN}x{$emaSlowN}";
        }

        // 2) RSI pullback + EMA fast holds above slow
        $pullback = ($last['rsi'] >= $pullLow) && ($last['rsi'] <= $pullHigh);
        $emaHoldUp = ($last['emaF'] > $last['emaS']);
        if ($pullback && $emaHoldUp) {
            $signals[] = "ENTRY_PULLBACK_EMA{$emaFastN}x{$emaSlowN}";
        }

        // 3) RSI fails at level + EMA cross down
        $rsiFail = ($prev['rsi'] >= $failLevel) && ($last['rsi'] < $failLevel);
        $emaCrossDown = ($prev['emaF'] >= $prev['emaS']) && ($last['emaF'] < $last['emaS']);
        if ($rsiFail && $emaCrossDown) {
            $signals[] = "EXIT_FAIL{$failLevel}_EMA{$emaFastN}x{$emaSlowN}_CROSSDOWN";
        }

        if (! $signals) {
            return null;
        }

        return [
            'symbol' => $symbol,
            'ts_est' => (string) $last['ts'],
            'price' => number_format((float) $last['price'], 4, '.', ''),
            'rsi' => number_format((float) $last['rsi'], 2, '.', ''),
            'ema_fast' => number_format((float) $last['emaF'], 4, '.', ''),
            'ema_slow' => number_format((float) $last['emaS'], 4, '.', ''),
            'vwap' => number_format((float) $last['vwap'], 4, '.', ''),
            'vol' => (string) (int) round((float) $last['vol']),
            'vol_avg' => $last['volAvg'] === null ? '' : (string) (int) round((float) $last['volAvg']),
            'signals' => implode('|', $signals),
        ];
    }

    private function printCsv(array $rows): void
    {
        echo "symbol,ts_est,price,rsi,ema_fast,ema_slow,vwap,vol,vol_avg,signals\n";
        foreach ($rows as $r) {
            echo implode(',', [
                $r['symbol'],
                $r['ts_est'],
                $r['price'],
                $r['rsi'],
                $r['ema_fast'],
                $r['ema_slow'],
                $r['vwap'],
                $r['vol'],
                $r['vol_avg'],
                $r['signals'],
            ])."\n";
        }
    }

    private function nowEstFixedUtcMinus5(): string
    {
        return (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))
            ->modify('-5 hours')
            ->format('Y-m-d H:i:s');
    }
}
