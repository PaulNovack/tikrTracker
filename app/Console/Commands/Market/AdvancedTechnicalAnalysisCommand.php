<?php

namespace App\Console\Commands\Market;

use App\Services\Market\MarketData;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class AdvancedTechnicalAnalysisCommand extends Command
{
    protected $signature = 'market:technical-analysis {daysBack=90}';

    protected $description = 'Advanced Technical Analysis Scanner with RSI, MA Crossover, Bollinger Bands, Volume, and Momentum indicators';

    private int $rsiPeriod = 14;

    private float $rsiOverbought = 70;

    private float $rsiOversold = 30;

    private int $smaShort = 20;

    private int $smaLong = 50;

    private int $bollingerPeriod = 20;

    private float $bollingerStdDev = 2.0;

    private float $volumeSpikeThreshold = 2.0;

    private int $rocPeriod = 10;

    public function handle(): int
    {
        $daysBack = (int) $this->argument('daysBack');

        $cryptoAssets = MarketData::getTrackedAssets();
        $stockAssets = MarketData::getTrackedStocks();
        $assets = array_merge($cryptoAssets, $stockAssets);

        $this->info('=======================================================');
        $this->info('ADVANCED TECHNICAL ANALYSIS SCANNER');
        $this->info('=======================================================');
        $this->info('Analyzing '.count($assets)." assets over {$daysBack} days");
        $this->info('  - Crypto: '.count($cryptoAssets).' assets');
        $this->info('  - Stocks: '.count($stockAssets).' assets');
        $this->info('Indicators: RSI, MA Crossover, Bollinger Bands, Volume, Momentum');
        $this->info('=======================================================');
        $this->newLine();

        $results = [];

        foreach ($assets as $asset) {
            $type = $asset['type'];
            $symbol = $asset['symbol'];

            $this->line("Analyzing {$symbol}...");

            // Fetch historical daily data
            $rows = DB::table('daily_prices')
                ->where('symbol', $symbol)
                ->where('asset_type', $type)
                ->orderBy('date', 'desc')
                ->limit($daysBack + 50)
                ->get(['date', 'price', 'volume'])
                ->toArray();

            if (count($rows) < 20) {
                $this->warn('  ⚠ Insufficient data (need at least 20 days)');

                continue;
            }

            // Reverse to chronological order (oldest first)
            $rows = array_reverse($rows);

            $prices = array_map(fn ($r) => (float) $r->price, $rows);
            $volumes = array_map(fn ($r) => (float) $r->volume, $rows);
            $dates = array_column($rows, 'date');

            $latestPrice = end($prices);
            $latestVolume = end($volumes);

            // Fetch last 6 hours of hourly data for short-term momentum
            $hourlyRows = DB::table('hourly_prices')
                ->where('symbol', $symbol)
                ->where('asset_type', $type)
                ->orderBy('ts', 'desc')
                ->limit(6)
                ->get(['ts', 'price'])
                ->toArray();

            $threeHourChange = null;
            $fiveHourChange = null;

            if (count($hourlyRows) >= 4) {
                $hourlyRowsChronological = array_reverse($hourlyRows);
                $priceThreeHoursAgo = (float) $hourlyRowsChronological[count($hourlyRowsChronological) - 4]->price;
                $currentHourlyPrice = (float) end($hourlyRowsChronological)->price;
                if ($priceThreeHoursAgo > 0) {
                    $threeHourChange = (($currentHourlyPrice - $priceThreeHoursAgo) / $priceThreeHoursAgo) * 100;
                }
            }

            if (count($hourlyRows) >= 6) {
                $hourlyRowsChronological = array_reverse($hourlyRows);
                $priceFiveHoursAgo = (float) $hourlyRowsChronological[0]->price;
                $currentHourlyPrice = (float) end($hourlyRowsChronological)->price;
                if ($priceFiveHoursAgo > 0) {
                    $fiveHourChange = (($currentHourlyPrice - $priceFiveHoursAgo) / $priceFiveHoursAgo) * 100;
                }
            }

            // Calculate all indicators
            $signals = [];
            $score = 0;

            // 1. RSI
            $rsi = MarketData::calculateRSI($prices, $this->rsiPeriod);
            if ($rsi !== null) {
                if ($rsi < $this->rsiOversold) {
                    $signals[] = "RSI Oversold ({$rsi})";
                    $score += 3;
                } elseif ($rsi > $this->rsiOverbought) {
                    $signals[] = "RSI Overbought ({$rsi})";
                    $score -= 2;
                } elseif ($rsi > 40 && $rsi < 60) {
                    $signals[] = "RSI Neutral ({$rsi})";
                }
            }

            // 2. Moving Average Crossover
            $smaShortValue = MarketData::calculateSMA($prices, $this->smaShort);
            $smaLongValue = MarketData::calculateSMA($prices, $this->smaLong);
            $prevSmaShort = MarketData::calculateSMA(array_slice($prices, 0, -1), $this->smaShort);
            $prevSmaLong = MarketData::calculateSMA(array_slice($prices, 0, -1), $this->smaLong);

            if ($smaShortValue && $smaLongValue && $prevSmaShort && $prevSmaLong) {
                // Golden Cross
                if ($prevSmaShort <= $prevSmaLong && $smaShortValue > $smaLongValue) {
                    $signals[] = "Golden Cross (MA{$this->smaShort} > MA{$this->smaLong})";
                    $score += 4;
                }
                // Death Cross
                elseif ($prevSmaShort >= $prevSmaLong && $smaShortValue < $smaLongValue) {
                    $signals[] = "Death Cross (MA{$this->smaShort} < MA{$this->smaLong})";
                    $score -= 4;
                }
                // Price above both MAs
                elseif ($latestPrice > $smaShortValue && $latestPrice > $smaLongValue) {
                    $signals[] = 'Price Above MAs';
                    $score += 2;
                }
                // Price below both MAs
                elseif ($latestPrice < $smaShortValue && $latestPrice < $smaLongValue) {
                    $signals[] = 'Price Below MAs';
                    $score -= 2;
                }
            }

            // 3. Bollinger Bands
            [$bbUpper, $bbMiddle, $bbLower] = MarketData::calculateBollingerBands(
                $prices,
                $this->bollingerPeriod,
                $this->bollingerStdDev
            );

            if ($bbUpper && $bbMiddle && $bbLower) {
                if ($latestPrice <= $bbLower) {
                    $signals[] = 'Price at Lower BB (oversold)';
                    $score += 3;
                } elseif ($latestPrice >= $bbUpper) {
                    $signals[] = 'Price at Upper BB (overbought)';
                    $score -= 2;
                }

                // Bollinger Squeeze
                $bbWidth = ($bbUpper - $bbLower) / $bbMiddle;
                if ($bbWidth < 0.1) {
                    $signals[] = 'Bollinger Squeeze (low volatility)';
                    $score += 1;
                }
            }

            // 4. Volume Analysis
            $avgVolume = MarketData::avg(array_slice($volumes, -30, 29));
            $volRatio = $avgVolume > 0 ? ($latestVolume / $avgVolume) : 0;
            if ($volRatio >= $this->volumeSpikeThreshold) {
                $priceDiff = count($prices) > 1 ? $latestPrice - $prices[count($prices) - 2] : 0;
                if ($priceDiff > 0) {
                    $signals[] = sprintf('Volume Spike (%.1fx) with Price UP', $volRatio);
                    $score += 2;
                } else {
                    $signals[] = sprintf('Volume Spike (%.1fx) with Price DOWN', $volRatio);
                    $score -= 1;
                }
            }

            // 5. Rate of Change (Momentum)
            $roc = MarketData::calculateROC($prices, $this->rocPeriod);
            if ($roc !== null) {
                if ($roc > 10) {
                    $signals[] = sprintf('Strong Upward Momentum (ROC: %.1f%%)', $roc);
                    $score += 2;
                } elseif ($roc < -10) {
                    $signals[] = sprintf('Strong Downward Momentum (ROC: %.1f%%)', $roc);
                    $score -= 2;
                }
            }

            // 6. Support/Resistance
            $recentPrices = array_slice($prices, -30);
            $support = min($recentPrices);
            $resistance = max($recentPrices);
            $priceRange = $resistance - $support;
            $pricePosition = $priceRange > 0 ? (($latestPrice - $support) / $priceRange) * 100 : 50;

            if ($pricePosition < 20) {
                $signals[] = 'Near Support Level';
                $score += 1;
            } elseif ($pricePosition > 80) {
                $signals[] = 'Near Resistance Level';
                $score -= 1;
            }

            // 7. Price Volatility
            $volatility = MarketData::stddev(array_slice($prices, -30));
            $volatilityPct = ($volatility / $latestPrice) * 100;
            if ($volatilityPct > 15) {
                $signals[] = sprintf('High Volatility (%.1f%%)', $volatilityPct);
            }

            // Determine overall recommendation
            $recommendation = 'HOLD';
            if ($score >= 7) {
                $recommendation = 'STRONG_BUY';
            } elseif ($score >= 4) {
                $recommendation = 'BUY';
            } elseif ($score >= 2) {
                $recommendation = 'MODERATE_BUY';
            } elseif ($score <= -7) {
                $recommendation = 'STRONG_SELL';
            } elseif ($score <= -4) {
                $recommendation = 'SELL';
            } elseif ($score <= -2) {
                $recommendation = 'WEAK_SELL';
            }

            $results[] = [
                'symbol' => "{$type}:{$symbol}",
                'recommendation' => $recommendation,
                'score' => $score,
                'price' => $latestPrice,
                'rsi' => $rsi,
                'ma_short' => $smaShortValue,
                'ma_long' => $smaLongValue,
                'vol_ratio' => $volRatio,
                '3h_change' => $threeHourChange,
                '5h_change' => $fiveHourChange,
                'signals' => $signals,
            ];

            $this->line("  Score: {$score} | {$recommendation}");
        }

        // Sort by score (highest first)
        usort($results, fn ($a, $b) => $b['score'] <=> $a['score']);

        $this->newLine();
        $this->info('=======================================================');
        $this->info('TECHNICAL ANALYSIS RESULTS (Sorted by Score)');
        $this->info('=======================================================');
        $this->newLine();

        // Print Strong Buy signals
        $this->info('🟢 STRONG BUY / BUY SIGNALS:');
        $this->line(str_repeat('-', 105));
        $hasBuySignals = false;
        foreach ($results as $r) {
            if ($r['score'] >= 4) {
                $hasBuySignals = true;
                $threeHrStr = $r['3h_change'] !== null ? sprintf('%+.2f%%', $r['3h_change']) : 'n/a';
                $fiveHrStr = $r['5h_change'] !== null ? sprintf('%+.2f%%', $r['5h_change']) : 'n/a';
                $this->line(sprintf(
                    '%-24s %-15s Score: %+3d  Price: %10.4f  RSI: %5.1f  3h: %8s  5h: %8s',
                    $r['symbol'],
                    $r['recommendation'],
                    $r['score'],
                    $r['price'],
                    $r['rsi'] ?? 0,
                    $threeHrStr,
                    $fiveHrStr
                ));
                foreach ($r['signals'] as $sig) {
                    $this->line("  • {$sig}");
                }
                $this->newLine();
            }
        }
        if (! $hasBuySignals) {
            $this->line('  No strong buy signals at this time.');
            $this->newLine();
        }

        // Print Moderate Buy signals
        $this->info('🟡 MODERATE BUY / HOLD SIGNALS:');
        $this->line(str_repeat('-', 105));
        $hasModerateSignals = false;
        foreach ($results as $r) {
            if ($r['score'] >= 0 && $r['score'] < 4) {
                $hasModerateSignals = true;
                $threeHrStr = $r['3h_change'] !== null ? sprintf('%+.2f%%', $r['3h_change']) : 'n/a';
                $fiveHrStr = $r['5h_change'] !== null ? sprintf('%+.2f%%', $r['5h_change']) : 'n/a';
                $this->line(sprintf(
                    '%-24s %-15s Score: %+3d  Price: %10.4f  RSI: %5.1f  3h: %8s  5h: %8s',
                    $r['symbol'],
                    $r['recommendation'],
                    $r['score'],
                    $r['price'],
                    $r['rsi'] ?? 0,
                    $threeHrStr,
                    $fiveHrStr
                ));
                foreach (array_slice($r['signals'], 0, 3) as $sig) {
                    $this->line("  • {$sig}");
                }
                $this->newLine();
            }
        }
        if (! $hasModerateSignals) {
            $this->line('  No moderate signals.');
            $this->newLine();
        }

        // Print Sell signals
        $this->info('🔴 SELL / WEAK SELL SIGNALS:');
        $this->line(str_repeat('-', 105));
        $hasSellSignals = false;
        foreach ($results as $r) {
            if ($r['score'] < 0) {
                $hasSellSignals = true;
                $threeHrStr = $r['3h_change'] !== null ? sprintf('%+.2f%%', $r['3h_change']) : 'n/a';
                $fiveHrStr = $r['5h_change'] !== null ? sprintf('%+.2f%%', $r['5h_change']) : 'n/a';
                $this->line(sprintf(
                    '%-24s %-15s Score: %+3d  Price: %10.4f  RSI: %5.1f  3h: %8s  5h: %8s',
                    $r['symbol'],
                    $r['recommendation'],
                    $r['score'],
                    $r['price'],
                    $r['rsi'] ?? 0,
                    $threeHrStr,
                    $fiveHrStr
                ));
                foreach (array_slice($r['signals'], 0, 3) as $sig) {
                    $this->line("  • {$sig}");
                }
                $this->newLine();
            }
        }
        if (! $hasSellSignals) {
            $this->line('  No sell signals at this time.');
            $this->newLine();
        }

        $this->info('=======================================================');
        $this->info('Legend:');
        $this->info('  Score >= 7  : STRONG_BUY (multiple bullish indicators)');
        $this->info('  Score 4-6   : BUY (bullish trend)');
        $this->info('  Score 2-3   : MODERATE_BUY (mild bullish signals)');
        $this->info('  Score -1 to 1: HOLD (neutral/mixed signals)');
        $this->info('  Score -2 to -3: WEAK_SELL (mild bearish signals)');
        $this->info('  Score -4 to -6: SELL (bearish trend)');
        $this->info('  Score <= -7 : STRONG_SELL (multiple bearish indicators)');
        $this->info('=======================================================');

        return self::SUCCESS;
    }
}
