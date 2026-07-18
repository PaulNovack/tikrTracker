<?php

namespace App\Http\Controllers\Market;

use App\Http\Controllers\Controller;
use App\Services\Market\MarketData;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class TechnicalAnalysisController extends Controller
{
    private int $rsiPeriod = 14;

    private float $rsiOverbought = 70;

    private float $rsiOversold = 30;

    private int $smaShort = 20;

    private int $smaLong = 50;

    private int $bollingerPeriod = 20;

    private float $bollingerStdDev = 2.0;

    private float $volumeSpikeThreshold = 2.0;

    private int $rocPeriod = 10;

    public function index(): Response
    {
        // Cache the analysis results for 15 minutes since technical analysis is compute-intensive
        // But fetch fresh timestamps and market status on each request
        $cacheKey = 'technical-analysis:results';

        $analysisData = Cache::remember($cacheKey, 900, function () {
            return $this->performAnalysis();
        });

        // Get fresh data freshness and market status (not cached)
        $latestDailyPrice = DB::table('daily_prices')
            ->orderBy('date', 'desc')
            ->first();

        $latestHourlyPrice = DB::table('hourly_prices')
            ->orderBy('ts', 'desc')
            ->first();

        // Determine market status (9:30 AM - 4:00 PM EST = 14:30-21:00 UTC, Mon-Fri)
        $now = now('UTC');
        $dayOfWeek = $now->dayOfWeek; // 0 = Sunday, 6 = Saturday
        $isWeekend = $dayOfWeek === 0 || $dayOfWeek === 6;

        // Market hours in UTC (9:30 AM - 4:00 PM EST)
        $marketOpenUTC = now('UTC')->setTime(14, 30, 0);  // 9:30 AM EST
        $marketCloseUTC = now('UTC')->setTime(21, 0, 0);  // 4:00 PM EST

        $isMarketOpen = ! $isWeekend &&
                        $now->between($marketOpenUTC, $marketCloseUTC);

        // Merge cached analysis data with fresh timestamps
        $data = array_merge($analysisData, [
            'dataFreshness' => [
                'latestDaily' => $latestDailyPrice?->date,
                'latestHourly' => $latestHourlyPrice?->ts,
                'latestDailyAgo' => $latestDailyPrice ? \Carbon\Carbon::parse($latestDailyPrice->date)->diffForHumans() : null,
                'latestHourlyAgo' => $latestHourlyPrice ? \Carbon\Carbon::parse($latestHourlyPrice->ts, 'UTC')->diffForHumans() : null,
            ],
            'marketStatus' => [
                'isOpen' => $isMarketOpen,
                'status' => $isMarketOpen ? 'Market Open' : ($isWeekend ? 'Market Closed (Weekend)' : 'Market Closed'),
            ],
        ]);

        return Inertia::render('Market/TechnicalAnalysis', $data);
    }

    private function performAnalysis(): array
    {
        $daysBack = 90;

        // Get all assets in one query using AssetInfo model to get id
        $assets = \App\Models\AssetInfo::select('id', 'symbol', 'asset_type', 'common_name')
            ->get()
            ->toArray();

        // Fetch ALL daily prices using query builder (database-agnostic)
        // Use UTC for date calculations
        $cutoffDate = now('UTC')->subDays($daysBack + 50);
        $allDailyPrices = DB::table('daily_prices')
            ->select('symbol', 'asset_type', 'date', 'price', 'volume')
            ->where('date', '>=', $cutoffDate)
            ->orderBy('symbol')
            ->orderBy('asset_type')
            ->orderByDesc('date')
            ->get();

        // Group daily prices by symbol-type
        $dailyPricesByAsset = [];
        foreach ($allDailyPrices as $row) {
            $key = $row->symbol.'|'.$row->asset_type;
            if (! isset($dailyPricesByAsset[$key])) {
                $dailyPricesByAsset[$key] = [];
            }
            $dailyPricesByAsset[$key][] = $row;
        }

        // Fetch ALL hourly prices for last 6 hours using query builder
        // Use UTC for timestamp calculations
        $cutoffTime = now('UTC')->subHours(6);
        $allHourlyPrices = DB::table('hourly_prices')
            ->select('symbol', 'asset_type', 'ts', 'price')
            ->where('ts', '>=', $cutoffTime)
            ->orderBy('symbol')
            ->orderBy('asset_type')
            ->orderByDesc('ts')
            ->get();

        // Group hourly prices by symbol-type (limit to 6 most recent per asset)
        $hourlyPricesByAsset = [];
        foreach ($allHourlyPrices as $row) {
            $key = $row->symbol.'|'.$row->asset_type;
            if (! isset($hourlyPricesByAsset[$key])) {
                $hourlyPricesByAsset[$key] = [];
            }
            if (count($hourlyPricesByAsset[$key]) < 6) {
                $hourlyPricesByAsset[$key][] = $row;
            }
        }

        $results = [];

        foreach ($assets as $asset) {
            $type = $asset['asset_type'];
            $symbol = $asset['symbol'];
            $key = $symbol.'|'.$type;

            // Get pre-fetched data
            $rows = $dailyPricesByAsset[$key] ?? [];

            if (count($rows) < 20) {
                continue;
            }

            // Reverse to chronological order (oldest first)
            $rows = array_reverse($rows);

            $prices = array_map(fn ($r) => (float) $r->price, $rows);
            $volumes = array_map(fn ($r) => (float) $r->volume, $rows);

            $latestPrice = end($prices);
            $latestVolume = end($volumes);

            // Get pre-fetched hourly data
            $hourlyRows = $hourlyPricesByAsset[$key] ?? [];

            $threeHourChange = null;
            $fiveHourChange = null;

            // Calculate changes based on time thresholds, not array indices.
            // Using timestamps ensures accuracy even when there are gaps in hourly data
            // (e.g., if market was closed or data fetching missed some hours).
            // hourlyRows is in DESC order (newest first), so we iterate from most recent.
            if (count($hourlyRows) > 0) {
                $latestPrice = (float) $hourlyRows[0]->price;
                $latestTime = \Carbon\Carbon::parse($hourlyRows[0]->ts, 'UTC');

                // Find price from ~3 hours ago
                $threeHoursAgoThreshold = $latestTime->copy()->subHours(3);
                foreach ($hourlyRows as $row) {
                    $rowTime = \Carbon\Carbon::parse($row->ts, 'UTC');
                    if ($rowTime->lte($threeHoursAgoThreshold)) {
                        $priceThreeHoursAgo = (float) $row->price;
                        if ($priceThreeHoursAgo > 0 && $latestPrice > 0) {
                            $threeHourChange = (($latestPrice - $priceThreeHoursAgo) / $priceThreeHoursAgo) * 100;
                        }
                        break;
                    }
                }

                // Find price from ~5 hours ago
                $fiveHoursAgoThreshold = $latestTime->copy()->subHours(5);
                foreach ($hourlyRows as $row) {
                    $rowTime = \Carbon\Carbon::parse($row->ts, 'UTC');
                    if ($rowTime->lte($fiveHoursAgoThreshold)) {
                        $priceFiveHoursAgo = (float) $row->price;
                        if ($priceFiveHoursAgo > 0 && $latestPrice > 0) {
                            $fiveHourChange = (($latestPrice - $priceFiveHoursAgo) / $priceFiveHoursAgo) * 100;
                        }
                        break;
                    }
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
                if ($prevSmaShort <= $prevSmaLong && $smaShortValue > $smaLongValue) {
                    $signals[] = "Golden Cross (MA{$this->smaShort} > MA{$this->smaLong})";
                    $score += 4;
                } elseif ($prevSmaShort >= $prevSmaLong && $smaShortValue < $smaLongValue) {
                    $signals[] = "Death Cross (MA{$this->smaShort} < MA{$this->smaLong})";
                    $score -= 4;
                } elseif ($latestPrice > $smaShortValue && $latestPrice > $smaLongValue) {
                    $signals[] = 'Price Above MAs';
                    $score += 2;
                } elseif ($latestPrice < $smaShortValue && $latestPrice < $smaLongValue) {
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
                'id' => $asset['id'],
                'symbol' => $symbol,
                'name' => $asset['common_name'],
                'type' => $type,
                'recommendation' => $recommendation,
                'score' => $score,
                'price' => round($latestPrice, 2),
                'rsi' => $rsi ? round($rsi, 1) : null,
                'threeHourChange' => $threeHourChange ? round($threeHourChange, 2) : null,
                'fiveHourChange' => $fiveHourChange ? round($fiveHourChange, 2) : null,
                'signals' => $signals,
            ];
        }

        // Sort by score (highest first)
        usort($results, fn ($a, $b) => $b['score'] <=> $a['score']);

        $cryptoCount = count(array_filter($results, fn ($r) => $r['type'] === 'crypto'));
        $stockCount = count(array_filter($results, fn ($r) => $r['type'] === 'stock'));

        return [
            'results' => $results,
            'summary' => [
                'totalAssets' => count($results),
                'cryptoCount' => $cryptoCount,
                'stockCount' => $stockCount,
                'daysAnalyzed' => $daysBack,
                'strongBuy' => count(array_filter($results, fn ($r) => $r['score'] >= 7)),
                'buy' => count(array_filter($results, fn ($r) => $r['score'] >= 4 && $r['score'] < 7)),
                'moderateBuy' => count(array_filter($results, fn ($r) => $r['score'] >= 2 && $r['score'] < 4)),
                'hold' => count(array_filter($results, fn ($r) => $r['score'] > -2 && $r['score'] < 2)),
                'weakSell' => count(array_filter($results, fn ($r) => $r['score'] <= -2 && $r['score'] > -4)),
                'sell' => count(array_filter($results, fn ($r) => $r['score'] <= -4 && $r['score'] > -7)),
                'strongSell' => count(array_filter($results, fn ($r) => $r['score'] <= -7)),
            ],
        ];
    }
}
