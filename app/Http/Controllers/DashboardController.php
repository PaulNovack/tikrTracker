<?php

namespace App\Http\Controllers;

use App\Models\AssetInfo;
use App\Models\DailyPrice;
use App\Models\FiveMinutePrice;
use App\Models\HourlyPrice;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function index(): Response
    {
        // Get the latest timestamp for each data granularity
        $latestFiveMinute = FiveMinutePrice::latest('ts')->first();
        $latestHourly = HourlyPrice::latest('ts')->first();
        $latestDaily = DailyPrice::latest('date')->first();

        // Calculate how long ago each was updated
        $dataFreshness = [
            'five_minute' => [
                'latest' => $latestFiveMinute?->ts,
                'ago' => $latestFiveMinute ? now()->diffForHumans($latestFiveMinute->ts) : null,
                'minutes_ago' => $latestFiveMinute ? now()->diffInMinutes($latestFiveMinute->ts) : null,
            ],
            'hourly' => [
                'latest' => $latestHourly?->ts,
                'ago' => $latestHourly ? now()->diffForHumans($latestHourly->ts) : null,
                'hours_ago' => $latestHourly ? now()->diffInHours($latestHourly->ts) : null,
            ],
            'daily' => [
                'latest' => $latestDaily?->date,
                'ago' => $latestDaily ? now()->diffForHumans($latestDaily->date) : null,
                'days_ago' => $latestDaily ? now()->diffInDays($latestDaily->date) : null,
            ],
        ];

        // Get actual asset counts
        $stockCount = AssetInfo::where('asset_type', 'stock')->count();
        $cryptoCount = AssetInfo::where('asset_type', 'crypto')->count();
        $totalAssets = $stockCount + $cryptoCount;

        // Count S&P 500 stocks (those with proper sector classifications)
        $sp500Sectors = [
            'Information Technology', 'Communication Services', 'Consumer Discretionary',
            'Health Care', 'Financials', 'Consumer Staples', 'Industrials',
            'Energy', 'Utilities', 'Real Estate', 'Materials',
        ];
        $sp500Count = AssetInfo::where('asset_type', 'stock')
            ->whereIn('sector', $sp500Sectors)
            ->count();

        return Inertia::render('dashboard', [
            'dataFreshness' => $dataFreshness,
            'userIsAdmin' => auth()->user()?->isAdmin(),
            'assetStats' => [
                'total' => $totalAssets,
                'stocks' => $stockCount,
                'crypto' => $cryptoCount,
                'sp500' => $sp500Count,
            ],
        ]);
    }
}
