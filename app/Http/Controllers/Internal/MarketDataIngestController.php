<?php

namespace App\Http\Controllers\Internal;

use App\Http\Controllers\Controller;
use App\Services\Trading\Realtime\RealtimeMarketDataService;
use Illuminate\Http\Request;

class MarketDataIngestController extends Controller
{
    public function quote(Request $request, RealtimeMarketDataService $marketData)
    {
        $this->authorizeInternal($request);

        $data = $request->validate([
            'symbol' => ['required', 'string', 'max:20'],
            'bid' => ['required', 'numeric', 'min:0'],
            'ask' => ['required', 'numeric', 'min:0'],
            'bid_qty' => ['nullable', 'integer', 'min:0'],
            'ask_qty' => ['nullable', 'integer', 'min:0'],
            'ts_est' => ['required', 'date'],
        ]);

        $marketData->putQuote($data);

        return response()->json(['ok' => true]);
    }

    public function partialOneMinuteBar(Request $request, RealtimeMarketDataService $marketData)
    {
        $this->authorizeInternal($request);

        $data = $request->validate([
            'symbol' => ['required', 'string', 'max:20'],
            'start_ts_est' => ['required', 'date'],
            'updated_ts_est' => ['required', 'date'],
            'open' => ['required', 'numeric', 'min:0'],
            'high' => ['required', 'numeric', 'min:0'],
            'low' => ['required', 'numeric', 'min:0'],
            'close' => ['required', 'numeric', 'min:0'],
            'volume' => ['nullable', 'integer', 'min:0'],
            'vwap' => ['nullable', 'numeric', 'min:0'],
        ]);

        $marketData->putPartialOneMinuteBar($data);

        return response()->json(['ok' => true]);
    }

    private function authorizeInternal(Request $request): void
    {
        $expected = config('trading_realtime.internal_ingest_secret');

        abort_unless(
            $expected && hash_equals((string) $expected, (string) $request->header('X-Internal-Token')),
            403
        );
    }
}
