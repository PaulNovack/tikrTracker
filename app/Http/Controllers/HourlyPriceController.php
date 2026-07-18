<?php

namespace App\Http\Controllers;

use App\Models\AssetInfo;
use App\Models\HourlyPrice;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Inertia\Inertia;
use Inertia\Response;

class HourlyPriceController extends Controller
{
    public function index(Request $request): Response
    {
        // Default to stocks if no filter is set
        $assetType = $request->get('asset_type', 'stock');
        $symbol = $request->input('symbol');
        $page = $request->get('page', 1);

        // Create a unique cache key based on the filters and page
        $cacheKey = sprintf(
            'hourly-prices:type=%s:symbol=%s:page=%d',
            $assetType,
            $symbol ?? 'all',
            $page
        );

        // Cache for 1 hour (3600 seconds) since hourly prices update every hour
        // The cache is warmed by the market:warm-hourly-prices-cache command
        $data = Cache::remember($cacheKey, 3600, function () use ($assetType, $symbol) {
            // Query using optimized index for sorting
            // The index idx_hourly_prices_type_ts_symbol helps avoid filesort
            $query = HourlyPrice::query()
                ->select([
                    'hourly_prices.id',
                    'hourly_prices.symbol',
                    'hourly_prices.asset_type',
                    'hourly_prices.ts',
                    'hourly_prices.price',
                    'asset_info.common_name',
                ])
                ->join('asset_info', function ($join) {
                    $join->on('hourly_prices.symbol', '=', 'asset_info.symbol')
                        ->on('hourly_prices.asset_type', '=', 'asset_info.asset_type');
                });

            if ($assetType && $assetType !== 'all') {
                $query->where('hourly_prices.asset_type', $assetType);
            }

            if ($symbol) {
                $query->where('hourly_prices.symbol', $symbol);
            }

            return $query->orderBy('hourly_prices.ts', 'desc')
                ->orderBy('hourly_prices.symbol')
                ->paginate(25);
        });

        return Inertia::render('market-data/hourly-prices/index', [
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
