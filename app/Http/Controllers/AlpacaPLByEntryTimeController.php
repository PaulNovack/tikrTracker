<?php

namespace App\Http\Controllers;

use App\Models\AlpacaOrder;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class AlpacaPLByEntryTimeController extends Controller
{
    /**
     * Market open in Eastern time.
     */
    private const MARKET_OPEN_HOUR = 9;

    private const MARKET_OPEN_MINUTE = 30;

    /**
     * Number of minutes per bucket.
     */
    private const BUCKET_MINUTES = 15;

    /**
     * Total trading minutes in a day (9:30 → 16:00 = 390 min).
     */
    private const TRADING_MINUTES = 390;

    public function index(Request $request): Response
    {
        $startDate = $request->input('start_date') ?? now()->subDays(30)->format('Y-m-d');
        $endDate = $request->input('end_date') ?? now()->format('Y-m-d');
        $mode = $request->input('mode', 'all');

        // Step 1: fetch all filled BUY orders whose entry (filled_at) falls in the date range.
        // We bucket by entry time, so the date filter is applied to the buy side only.
        $buyOrders = AlpacaOrder::query()
            ->where('side', 'buy')
            ->where(function ($q) {
                $q->where('status', 'filled')
                    ->orWhere(function ($q2) {
                        $q2->where('status', 'partially_filled')
                            ->where('filled_qty', '>', 0);
                    });
            })
            ->whereNotNull('filled_at')
            ->whereNotNull('alpaca_order_id')
            ->where('filled_at', '>=', $startDate.' 00:00:00')
            ->where('filled_at', '<=', $endDate.' 23:59:59')
            ->when($mode === 'live', fn ($q) => $q->where('is_paper', false))
            ->when($mode === 'paper', fn ($q) => $q->where('is_paper', true))
            ->orderBy('filled_at')
            ->get();

        if ($buyOrders->isEmpty()) {
            return Inertia::render('alpaca-pl-by-entry-time/index', [
                'buckets' => $this->emptyBuckets(),
                'filters' => ['start_date' => $startDate, 'end_date' => $endDate, 'mode' => $mode],
            ]);
        }

        // Step 2: fetch all SELL orders linked to those buys via parent_alpaca_order_id.
        // This correctly handles cross-day trades (buy day 1, stop fires day 2).
        $buyOrderIds = $buyOrders->pluck('alpaca_order_id')->filter()->unique()->values();

        $sellOrders = AlpacaOrder::query()
            ->where('side', 'sell')
            ->where(function ($q) {
                $q->where('status', 'filled')
                    ->orWhere(function ($q2) {
                        $q2->where('status', 'partially_filled')
                            ->where('filled_qty', '>', 0);
                    });
            })
            ->whereNotNull('filled_at')
            ->whereIn('parent_alpaca_order_id', $buyOrderIds)
            ->get()
            ->keyBy('parent_alpaca_order_id');

        $buckets = $this->buildBuckets($buyOrders, $sellOrders);

        return Inertia::render('alpaca-pl-by-entry-time/index', [
            'buckets' => $buckets,
            'filters' => [
                'start_date' => $startDate,
                'end_date' => $endDate,
                'mode' => $mode,
            ],
        ]);
    }

    /**
     * Build empty bucket list (used when there are no buy orders).
     *
     * @return array<int, mixed>
     */
    private function emptyBuckets(): array
    {
        return array_values($this->initBucketMap());
    }

    /**
     * Match each buy order to its sell via parent_alpaca_order_id, compute realized P&L,
     * and group results into 30-minute entry-time buckets.
     *
     * @param  \Illuminate\Database\Eloquent\Collection<int, AlpacaOrder>  $buyOrders
     * @param  \Illuminate\Database\Eloquent\Collection<int, AlpacaOrder>  $sellOrders  keyed by parent_alpaca_order_id
     * @return array<int, array{bucket_label: string, bucket_minutes: int, total_pl: float, total_wins: float, total_losses: float, trade_count: int, win_count: int, loss_count: int, win_rate: float|null}>
     */
    private function buildBuckets(
        \Illuminate\Database\Eloquent\Collection $buyOrders,
        \Illuminate\Database\Eloquent\Collection $sellOrders
    ): array {
        $bucketMap = $this->initBucketMap();

        foreach ($buyOrders as $buyOrder) {
            $sellOrder = $sellOrders->get($buyOrder->alpaca_order_id);

            // Only count fully closed trades (buy has a matching filled sell).
            if (! $sellOrder) {
                continue;
            }

            $entryTime = $buyOrder->filled_at->setTimezone('America/New_York');
            $offsetMinutes = $this->getOffsetMinutes($entryTime);

            // Skip pre/post market entries.
            if ($offsetMinutes === null) {
                continue;
            }

            $bucketKey = (int) (floor($offsetMinutes / self::BUCKET_MINUTES) * self::BUCKET_MINUTES);

            if (! array_key_exists($bucketKey, $bucketMap)) {
                $bucketKey = max(array_keys($bucketMap));
            }

            $buyQty = (float) $buyOrder->filled_qty;
            $sellQty = (float) $sellOrder->filled_qty;
            $matchedQty = min($buyQty, $sellQty);

            if ($matchedQty <= 0) {
                continue;
            }

            $avgBuyPrice = (float) $buyOrder->filled_avg_price;
            $avgSellPrice = (float) $sellOrder->filled_avg_price;
            $pl = ($avgSellPrice - $avgBuyPrice) * $matchedQty;

            $bucketMap[$bucketKey]['total_pl'] += $pl;
            $bucketMap[$bucketKey]['trade_count']++;

            if ($pl > 0) {
                $bucketMap[$bucketKey]['win_count']++;
                $bucketMap[$bucketKey]['total_wins'] += $pl;
            } elseif ($pl < 0) {
                $bucketMap[$bucketKey]['loss_count']++;
                $bucketMap[$bucketKey]['total_losses'] += $pl;
            }
        }

        return array_values(array_map(function (array $bucket) {
            $total = $bucket['win_count'] + $bucket['loss_count'];
            $bucket['total_pl'] = round($bucket['total_pl'], 2);
            $bucket['total_wins'] = round($bucket['total_wins'], 2);
            $bucket['total_losses'] = round($bucket['total_losses'], 2);
            $bucket['win_rate'] = $total > 0 ? round(($bucket['win_count'] / $total) * 100, 1) : null;

            return $bucket;
        }, $bucketMap));
    }

    /**
     * Build the initial empty bucket map keyed by minutes-since-open.
     *
     * @return array<int, array{bucket_label: string, bucket_minutes: int, total_pl: float, total_wins: float, total_losses: float, trade_count: int, win_count: int, loss_count: int}>
     */
    private function initBucketMap(): array
    {
        $bucketCount = (int) ceil(self::TRADING_MINUTES / self::BUCKET_MINUTES);
        $bucketMap = [];

        for ($i = 0; $i < $bucketCount; $i++) {
            $offsetMinutes = $i * self::BUCKET_MINUTES;
            $totalMinutes = self::MARKET_OPEN_HOUR * 60 + self::MARKET_OPEN_MINUTE + $offsetMinutes;
            $hour = intdiv($totalMinutes, 60);
            $minute = $totalMinutes % 60;
            $label = sprintf(
                '%d:%02d %s',
                $hour > 12 ? $hour - 12 : ($hour === 0 ? 12 : $hour),
                $minute,
                $hour >= 12 ? 'PM' : 'AM'
            );

            $bucketMap[$offsetMinutes] = [
                'bucket_label' => $label,
                'bucket_minutes' => $offsetMinutes,
                'total_pl' => 0.0,
                'total_wins' => 0.0,
                'total_losses' => 0.0,
                'trade_count' => 0,
                'win_count' => 0,
                'loss_count' => 0,
            ];
        }

        return $bucketMap;
    }

    /**
     * Returns the number of minutes since market open (9:30 ET), or null if outside trading hours.
     */
    private function getOffsetMinutes(\Carbon\Carbon $time): ?int
    {
        $hours = $time->hour;
        $minutes = $time->minute;
        $totalMinutes = $hours * 60 + $minutes;
        $openMinutes = self::MARKET_OPEN_HOUR * 60 + self::MARKET_OPEN_MINUTE;
        $closeMinutes = 16 * 60; // 16:00

        if ($totalMinutes < $openMinutes || $totalMinutes >= $closeMinutes) {
            return null;
        }

        return $totalMinutes - $openMinutes;
    }
}
