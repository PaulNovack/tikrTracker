<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class PriceDataController extends Controller
{
    public function oneMinute(Request $request): Response
    {
        $perPage = $request->input('per_page', 50);

        $data = DB::table('one_minute_prices')
            ->select('ts_est', 'trading_date_est', 'symbol', 'price', 'open', 'high', 'low', 'volume')
            ->where('asset_type', 'stock')
            ->orderBy('ts_est', 'desc')
            ->limit(500)
            ->simplePaginate($perPage);

        return Inertia::render('price-data/one-minute', [
            'data' => $data,
        ]);
    }

    public function fiveMinute(Request $request): Response
    {
        $perPage = (int) $request->input('per_page', 50);
        $oneDayAgo = now('America/New_York')->subDays(7)->format('Y-m-d');

        $subquery = DB::table('five_minute_prices')
            ->where('asset_type', 'stock')
            ->where('trading_date_est', '>=', $oneDayAgo)
            ->orderBy('ts_est', 'desc')
            ->orderBy('symbol', 'asc')
            ->limit(500);

        $data = DB::table(DB::raw("({$subquery->toSql()}) as sub"))
            ->mergeBindings($subquery)
            ->paginate($perPage);

        return Inertia::render('price-data/five-minute', [
            'data' => $data,
        ]);
    }

    public function daily(Request $request): Response
    {
        $perPage = $request->input('per_page', 50);
        $fiveDaysAgo = now('America/New_York')->subDays(14)->format('Y-m-d');

        $data = DB::table('daily_prices')
            ->where('asset_type', 'stock')
            ->where('date', '>=', $fiveDaysAgo)
            ->orderBy('date', 'desc')
            ->orderBy('symbol', 'asc')
            ->limit(500)
            ->paginate($perPage);

        return Inertia::render('price-data/daily', [
            'data' => $data,
        ]);
    }

    /**
     * Latest Stock Quotes — real-time Alpaca SIP quotes from the streaming daemon.
     */
    public function latestQuotes(Request $request): Response
    {
        $perPage = (int) $request->input('per_page', 50);
        $symbol = $request->input('symbol');

        $query = DB::table('latest_stock_quotes')
            ->orderBy('symbol', 'asc');

        if ($symbol) {
            $query->where('symbol', 'like', strtoupper($symbol).'%');
        }

        $data = $query->paginate($perPage)->appends($request->only('symbol'));

        return Inertia::render('price-data/latest-quotes', [
            'data' => $data,
            'filters' => [
                'symbol' => $symbol ?? '',
            ],
        ]);
    }
}
