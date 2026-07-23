<?php

declare(strict_types=1);

namespace App\Http\Controllers\Analysis;

use App\Http\Controllers\Controller;
use App\Models\MarketSchedule;
use App\Services\TradingSettingService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class RisingSinceCloseController extends Controller
{
    /**
     * Show stocks that have risen most since the last market close.
     * Uses 1-minute price data from 4:00 PM of the prior open day forward,
     * so premarket and after-hours trading is included.
     *
     * The "Open" column is blank if the market hasn't opened yet today.
     */
    public function index(Request $request): Response
    {
        $tz = 'America/New_York';
        $now = Carbon::now($tz);
        $todayDate = $now->toDateString();
        $limit = min((int) $request->get('limit', 200), 500);

        // Find the last close date (walk back up to 14 days)
        $lastCloseDate = $this->resolveLastCloseDate($now);

        if (! $lastCloseDate) {
            return Inertia::render('analysis/rising-since-close/index', [
                'stocks' => [],
                'lastCloseDate' => '—',
                'totalSymbols' => 0,
            ]);
        }

        // Determine if the market has opened yet today
        $marketOpenET = Carbon::today($tz)->setTime(9, 30, 0);
        $isMarketOpen = $now->gte($marketOpenET);

        // Check if today is a trading day
        $todaySchedule = MarketSchedule::byMarketType('stock')
            ->where('date', $todayDate)
            ->first();
        $isTradingDay = ! ($todaySchedule && $todaySchedule->isClosed())
            && ! in_array((int) $now->format('N'), [6, 7], true);

        $shouldFetchOpen = $isMarketOpen && $isTradingDay;

        // Latest 1-min price for each symbol (from 4 PM last close day forward)
        // one_minute_prices includes premarket and after-hours bars
        $lastCloseDateTime = Carbon::parse($lastCloseDate, $tz)->setTime(16, 0, 0)->utc();

        $latestRows = DB::connection('mysql')
            ->select("
                SELECT omp.symbol, omp.price, omp.ts_est
                FROM one_minute_prices omp
                INNER JOIN (
                    SELECT symbol, MAX(ts) AS max_ts
                    FROM one_minute_prices
                    WHERE asset_type = 'stock' AND ts >= ?
                    GROUP BY symbol
                ) latest ON latest.symbol = omp.symbol AND latest.max_ts = omp.ts
            ", [$lastCloseDateTime]);

        $latestBySymbol = [];
        foreach ($latestRows as $row) {
            $latestBySymbol[$row->symbol] = $row;
        }

        if (empty($latestBySymbol)) {
            return Inertia::render('analysis/rising-since-close/index', [
                'stocks' => [],
                'lastCloseDate' => $lastCloseDate,
                'totalSymbols' => 0,
            ]);
        }

        // Fetch open prices (first 1-min bar at/after 9:30 AM today) only if market is open
        $openBySymbol = [];
        if ($shouldFetchOpen) {
            $marketOpenUTC = $marketOpenET->clone()->utc();
            $openRows = DB::connection('mysql')
                ->select("
                    SELECT omp.symbol, omp.price AS open_price
                    FROM one_minute_prices omp
                    INNER JOIN (
                        SELECT symbol, MIN(ts) AS min_ts
                        FROM one_minute_prices
                        WHERE asset_type = 'stock' AND ts >= ?
                        GROUP BY symbol
                    ) first_bar ON first_bar.symbol = omp.symbol AND first_bar.min_ts = omp.ts
                ", [$marketOpenUTC]);

            foreach ($openRows as $row) {
                $openBySymbol[$row->symbol] = (float) $row->open_price;
            }
        }

        // Fetch close prices for the last close date (only symbols with current data)
        $closeRows = DB::connection('mysql')
            ->table('daily_prices')
            ->where('date', $lastCloseDate)
            ->where('asset_type', 'stock')
            ->whereIn('symbol', array_keys($latestBySymbol))
            ->where('price', '>', 0)
            ->get(['symbol', 'price']);

        // Map asset_info_id for each symbol (for chart links)
        $assetInfoIds = DB::connection('mysql')
            ->table('asset_info')
            ->whereIn('symbol', array_keys($latestBySymbol))
            ->get(['symbol', 'id'])
            ->keyBy('symbol')
            ->map(fn ($row) => (int) $row->id)
            ->all();

        // Compute percent change, filter to risers, sort
        $stocks = [];
        foreach ($closeRows as $dp) {
            $latest = $latestBySymbol[$dp->symbol] ?? null;
            if (! $latest) {
                continue;
            }

            $closePrice = (float) $dp->price;
            $currentPrice = (float) $latest->price;
            $openPrice = $openBySymbol[$dp->symbol] ?? null;

            if ($currentPrice <= $closePrice) {
                continue;
            }

            $pctChange = round((($currentPrice - $closePrice) / $closePrice) * 100, 2);
            $sinceOpenPct = ($openPrice && $openPrice > 0)
                ? round((($currentPrice - $openPrice) / $openPrice) * 100, 2)
                : null;

            $stocks[] = [
                'symbol' => $dp->symbol,
                'asset_info_id' => $assetInfoIds[$dp->symbol] ?? null,
                'close_price' => $closePrice,
                'open_price' => $openPrice,
                'current_price' => $currentPrice,
                'pct_change' => $pctChange,
                'since_open_pct' => $sinceOpenPct,
                'price_timestamp' => $latest->ts_est,
            ];
        }

        // Sort by percent change descending
        usort($stocks, fn (array $a, array $b) => $b['pct_change'] <=> $a['pct_change']);

        // Limit
        $stocks = array_slice($stocks, 0, $limit);

        return Inertia::render('analysis/rising-since-close/index', [
            'stocks' => $stocks,
            'lastCloseDate' => $lastCloseDate,
            'totalSymbols' => count($stocks),
            'newsLink' => TradingSettingService::get('trading.news_link', 'https://finance.yahoo.com/quote/<SYMBOL>/news/'),
        ]);
    }

    /**
     * Walk backwards from today to find the most recent date with valid close data.
     */
    private function resolveLastCloseDate(Carbon $now): ?string
    {
        for ($i = 1; $i <= 14; $i++) {
            $candidate = $now->copy()->subDays($i);
            $candidateDate = $candidate->toDateString();

            $schedule = MarketSchedule::byMarketType('stock')
                ->where('date', $candidateDate)
                ->first();

            if ($schedule && $schedule->isClosed()) {
                continue;
            }

            $dayOfWeek = (int) $candidate->format('N');
            if (! $schedule && ($dayOfWeek >= 6)) {
                continue;
            }

            $hasData = DB::connection('mysql')
                ->table('daily_prices')
                ->where('date', $candidateDate)
                ->where('asset_type', 'stock')
                ->whereNotNull('price')
                ->exists();

            if ($hasData) {
                return $candidateDate;
            }
        }

        return null;
    }

    /**
     * Get the most recent weekday (Mon-Fri) before or on the given date.
     */
    private function lastWeekday(Carbon $date): Carbon
    {
        $dayOfWeek = (int) $date->format('N'); // 1=Mon ... 7=Sun

        return match (true) {
            $dayOfWeek === 6 => $date->copy()->subDays(1),  // Saturday → Friday
            $dayOfWeek === 7 => $date->copy()->subDays(2),  // Sunday → Friday
            default => $date->copy(),
        };
    }
}
