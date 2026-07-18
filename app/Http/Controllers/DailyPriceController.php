<?php

namespace App\Http\Controllers;

use App\Models\AssetInfo;
use App\Models\DailyPrice;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Inertia\Inertia;
use Inertia\Response;

class DailyPriceController extends Controller
{
    public function index(Request $request): Response
    {
        // Default to stocks if no filter is set
        $assetType = $request->get('asset_type', 'stock');
        $symbol = $request->input('symbol');
        $page = $request->get('page', 1);

        // Create a unique cache key based on the filters and page
        $cacheKey = sprintf(
            'daily-prices:type=%s:symbol=%s:page=%d',
            $assetType,
            $symbol ?? 'all',
            $page
        );

        // Cache for 24 hours (86400 seconds) since daily prices only update once per day after market close
        // The cache is warmed by the market:warm-daily-prices-cache command
        $data = Cache::remember($cacheKey, 86400, function () use ($assetType, $symbol) {
            // Query using optimized index for sorting
            // The index idx_daily_prices_type_date_symbol helps avoid filesort
            $query = DailyPrice::query()
                ->select([
                    'daily_prices.id',
                    'daily_prices.symbol',
                    'daily_prices.asset_type',
                    'daily_prices.date',
                    'daily_prices.open',
                    'daily_prices.high',
                    'daily_prices.low',
                    'daily_prices.price',
                    'daily_prices.volume',
                    'asset_info.common_name',
                ])
                ->join('asset_info', function ($join) {
                    $join->on('daily_prices.symbol', '=', 'asset_info.symbol')
                        ->on('daily_prices.asset_type', '=', 'asset_info.asset_type');
                });

            if ($assetType && $assetType !== 'all') {
                $query->where('daily_prices.asset_type', $assetType);
            }

            if ($symbol) {
                $query->where('daily_prices.symbol', $symbol);
            }

            return $query->orderBy('daily_prices.date', 'desc')
                ->orderBy('daily_prices.symbol')
                ->paginate(25);
        });

        return Inertia::render('market-data/daily-prices/index', [
            'prices' => $data,
            'filters' => [
                'symbol' => $symbol,
                'asset_type' => $assetType,
            ],
        ]);
    }

    public function symbols(Request $request)
    {
        $search = $request->get('search', '');
        $assetType = $request->get('asset_type');

        $query = AssetInfo::query()
            ->select('symbol', 'common_name as name', 'asset_type')
            ->whereNull('deleted_at');

        if ($assetType && $assetType !== 'all') {
            $query->where('asset_type', $assetType);
        }

        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('symbol', 'like', $search.'%')
                    ->orWhere('common_name', 'like', '%'.$search.'%');
            });
        }

        $symbols = $query->orderBy('symbol')
            ->limit(20)
            ->get();

        return response()->json($symbols);
    }
}
