<?php

namespace App\Http\Controllers;

use App\Models\AlpacaOrder;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class AlpacaCapitalInvestedController extends Controller
{
    public function index(): Response
    {
        $startDate = request('start_date', today('America/New_York')->subDays(30)->toDateString());
        $endDate = request('end_date', today('America/New_York')->toDateString());

        // Daily aggregate stats
        $dailyCapital = DB::select('
            WITH buy_events AS (
                SELECT
                    DATE(CONVERT_TZ(filled_at, \'+00:00\', \'America/New_York\')) AS trading_day,
                    CONVERT_TZ(filled_at, \'+00:00\', \'America/New_York\')       AS event_time,
                    (filled_qty * filled_avg_price)                               AS capital,
                    1                                                             AS position_delta
                FROM alpaca_orders
                WHERE side = \'buy\'
                    AND status = \'filled\'

                    AND filled_qty > 0
                    AND filled_avg_price > 0
                    AND DATE(CONVERT_TZ(filled_at, \'+00:00\', \'America/New_York\')) BETWEEN ? AND ?
            ),
            sell_events AS (
                SELECT
                    DATE(CONVERT_TZ(sell.filled_at, \'+00:00\', \'America/New_York\')) AS trading_day,
                    CONVERT_TZ(sell.filled_at, \'+00:00\', \'America/New_York\')       AS event_time,
                    -(buy.filled_qty * buy.filled_avg_price)                           AS capital,
                    -1                                                                 AS position_delta
                FROM alpaca_orders sell
                JOIN alpaca_orders buy ON buy.alpaca_order_id = sell.parent_alpaca_order_id
                WHERE sell.side = \'sell\'
                    AND sell.status = \'filled\'

                    AND sell.filled_qty > 0
                    AND sell.filled_avg_price > 0
                    AND DATE(CONVERT_TZ(sell.filled_at, \'+00:00\', \'America/New_York\')) BETWEEN ? AND ?
            ),
            all_events AS (
                SELECT * FROM buy_events
                UNION ALL
                SELECT * FROM sell_events
            )
            SELECT
                trading_day,
                ROUND(MAX(running_capital), 2)                         AS peak_capital_needed,
                MAX(running_positions)                                 AS max_concurrent_positions,
                SUM(CASE WHEN position_delta > 0 THEN 1 ELSE 0 END)   AS trades_opened
            FROM (
                SELECT
                    trading_day,
                    event_time,
                    capital,
                    position_delta,
                    SUM(capital)         OVER (PARTITION BY trading_day ORDER BY event_time) AS running_capital,
                    SUM(position_delta)  OVER (PARTITION BY trading_day ORDER BY event_time) AS running_positions
                FROM all_events
            ) capital_flow
            GROUP BY trading_day
            ORDER BY trading_day DESC
        ', [$startDate, $endDate, $startDate, $endDate]);

        // Time-of-day timeline: get ALL events with running capital for charting
        $timeline = DB::select('
            WITH buy_events AS (
                SELECT
                    DATE(CONVERT_TZ(filled_at, \'+00:00\', \'America/New_York\')) AS trading_day,
                    CONVERT_TZ(filled_at, \'+00:00\', \'America/New_York\')       AS event_time,
                    (filled_qty * filled_avg_price)                               AS capital,
                    1                                                             AS position_delta,
                    symbol,
                    \'buy\'                                                       AS event_type
                FROM alpaca_orders
                WHERE side = \'buy\'
                    AND status = \'filled\'
                    AND filled_qty > 0
                    AND filled_avg_price > 0
                    AND DATE(CONVERT_TZ(filled_at, \'+00:00\', \'America/New_York\')) BETWEEN ? AND ?
            ),
            sell_events AS (
                SELECT
                    DATE(CONVERT_TZ(sell.filled_at, \'+00:00\', \'America/New_York\')) AS trading_day,
                    CONVERT_TZ(sell.filled_at, \'+00:00\', \'America/New_York\')       AS event_time,
                    -(buy.filled_qty * buy.filled_avg_price)                           AS capital,
                    -1                                                                 AS position_delta,
                    buy.symbol                                                         AS symbol,
                    \'sell\'                                                           AS event_type
                FROM alpaca_orders sell
                JOIN alpaca_orders buy ON buy.alpaca_order_id = sell.parent_alpaca_order_id
                WHERE sell.side = \'sell\'
                    AND sell.status = \'filled\'
                    AND sell.filled_qty > 0
                    AND sell.filled_avg_price > 0
                    AND DATE(CONVERT_TZ(sell.filled_at, \'+00:00\', \'America/New_York\')) BETWEEN ? AND ?
            ),
            all_events AS (
                SELECT * FROM buy_events
                UNION ALL
                SELECT * FROM sell_events
            )
            SELECT
                trading_day,
                DATE_FORMAT(CONVERT_TZ(event_time, \'+00:00\', \'America/New_York\'), \'%H:%i\') AS time_of_day,
                event_type,
                symbol,
                ROUND(capital, 2)                          AS capital,
                position_delta,
                ROUND(SUM(capital) OVER (PARTITION BY trading_day ORDER BY event_time), 2)           AS running_capital,
                SUM(position_delta) OVER (PARTITION BY trading_day ORDER BY event_time)              AS running_positions
            FROM all_events
            ORDER BY trading_day, event_time
        ', [$startDate, $endDate, $startDate, $endDate]);

        // Group timeline by trading day
        $timelineByDay = [];
        foreach ($timeline as $event) {
            $day = $event->trading_day;
            $timelineByDay[$day][] = [
                'time_of_day' => $event->time_of_day,
                'event_type' => $event->event_type,
                'symbol' => $event->symbol,
                'capital' => (float) $event->capital,
                'position_delta' => (int) $event->position_delta,
                'running_capital' => (float) $event->running_capital,
                'running_positions' => (int) $event->running_positions,
            ];
        }

        $statistics = [
            'max_peak_capital' => collect($dailyCapital)->max('peak_capital_needed'),
            'avg_peak_capital' => round(collect($dailyCapital)->avg('peak_capital_needed'), 2),
            'max_concurrent_positions' => collect($dailyCapital)->max('max_concurrent_positions'),
            'total_trading_days' => count($dailyCapital),
        ];

        return Inertia::render('alpaca-capital-invested/index', [
            'dailyCapital' => $dailyCapital,
            'timelineByDay' => $timelineByDay,
            'statistics' => $statistics,
            'filters' => [
                'start_date' => $startDate,
                'end_date' => $endDate,
            ],
        ]);
    }

    public function tradesForDay(string $date): JsonResponse
    {
        // Get all buy + sell events for the day, ordered chronologically by filled_at.
        // Each row is one event (buy creates capital, sell releases it).
        $eventsQuery = "
            SELECT
                'buy'  AS event_type,
                bo.id,
                bo.symbol,
                CONVERT_TZ(bo.filled_at, '+00:00', 'America/New_York') AS event_time,
                bo.filled_qty,
                bo.filled_avg_price,
                (bo.filled_qty * bo.filled_avg_price) AS capital,
                bo.alpaca_order_id,
                NULL AS exit_reason,
                NULL AS sell_price,
                NULL AS buy_avg_price,  -- placeholder for UNION ALL alignment with sell
                NULL AS parent_buy_order_id,  -- placeholder for UNION ALL alignment
                ta.ml_win_prob,
                ta.signal_type,
                ta.entry_type,
                ta.version
            FROM alpaca_orders bo
            LEFT JOIN trade_alerts ta ON ta.id = bo.trade_alert_id
            WHERE bo.side = 'buy'
                AND bo.status = 'filled'
                AND bo.filled_qty > 0
                AND bo.filled_avg_price > 0
                AND DATE(CONVERT_TZ(bo.filled_at, '+00:00', 'America/New_York')) = ?

            UNION ALL

            SELECT
                'sell' AS event_type,
                so.id,
                so.symbol,
                CONVERT_TZ(so.filled_at, '+00:00', 'America/New_York') AS event_time,
                so.filled_qty,
                so.filled_avg_price,
                (so.filled_qty * bo.filled_avg_price) AS capital,
                so.id AS alpaca_order_id,
                so.notes AS exit_reason,
                so.filled_avg_price AS sell_price,
                bo.filled_avg_price AS buy_avg_price,
                so.parent_alpaca_order_id AS parent_buy_order_id,
                NULL AS ml_win_prob,
                NULL AS signal_type,
                NULL AS entry_type,
                NULL AS version
            FROM alpaca_orders so
            LEFT JOIN alpaca_orders bo ON bo.alpaca_order_id = so.parent_alpaca_order_id
            WHERE so.side = 'sell'
                AND so.status = 'filled'
                AND so.filled_qty > 0
                AND so.filled_avg_price > 0
                AND DATE(CONVERT_TZ(so.filled_at, '+00:00', 'America/New_York')) = ?

            ORDER BY event_time, id
        ";

        $events = DB::select($eventsQuery, [$date, $date]);

        // Calculate running balance using FIFO: sells unwind the earliest buys first.
        // Build a FIFO queue of (buy_capital, remaining_qty) per symbol.
        $capitalQueue = []; // symbol => [ ['capital_per_share' => float, 'remaining' => float], ... ]
        $runningBalance = 0;
        $posCount = 0;
        $result = [];

        foreach ($events as $event) {
            $eventTime = \Carbon\Carbon::parse($event->event_time);

            if ($event->event_type === 'buy') {
                $qty = (float) $event->filled_qty;
                $buyPrice = (float) $event->filled_avg_price;
                $capitalDeployed = $qty * $buyPrice;

                // Push into FIFO queue
                $capitalQueue[$event->symbol][] = [
                    'alpaca_order_id' => $event->alpaca_order_id,
                    'capital_per_share' => $buyPrice,
                    'remaining' => $qty,
                ];

                $runningBalance += $capitalDeployed;
                $posCount++;

                $result[] = [
                    'time' => $eventTime->format('h:i A'),
                    'event_type' => 'buy',
                    'symbol' => $event->symbol,
                    'qty' => $qty,
                    'price' => $buyPrice,
                    'capital' => round($capitalDeployed, 2),
                    'total_invested' => round($runningBalance, 2),
                    'running_positions' => $posCount,
                    'ml_win_prob' => $event->ml_win_prob ? (float) $event->ml_win_prob : null,
                    'signal_type' => $event->signal_type,
                    'entry_type' => $event->entry_type,
                    'version' => $event->version,
                    'exit_reason' => null,
                ];
            } else { // sell
                $sellQty = (float) $event->filled_qty;
                $remainingToClose = $sellQty;
                $totalReleased = 0;
                $symbol = $event->symbol;
                $sellPrice = (float) $event->sell_price;

                // Match sell to exact parent buy (same-symbol multi-trade handling).
                // When parent_buy_order_id exists, find and consume that specific buy first.
                if (isset($event->parent_buy_order_id) && $event->parent_buy_order_id && ! empty($capitalQueue[$symbol])) {
                    foreach ($capitalQueue[$symbol] as $idx => &$entry) {
                        if ($entry['alpaca_order_id'] === $event->parent_buy_order_id) {
                            $consumeQty = min($remainingToClose, $entry['remaining']);
                            $released = $consumeQty * $entry['capital_per_share'];
                            $totalReleased += $released;
                            $remainingToClose -= $consumeQty;
                            $entry['remaining'] -= $consumeQty;

                            if ($entry['remaining'] <= 0) {
                                unset($capitalQueue[$symbol][$idx]);
                                $capitalQueue[$symbol] = array_values($capitalQueue[$symbol]);
                            }
                            break;
                        }
                    }
                }

                // Fallback: consume from FIFO queue (earliest buys first)
                while ($remainingToClose > 0 && ! empty($capitalQueue[$symbol])) {
                    $head = &$capitalQueue[$symbol][0];
                    $consumeQty = min($remainingToClose, $head['remaining']);
                    $released = $consumeQty * $head['capital_per_share'];
                    $totalReleased += $released;
                    $remainingToClose -= $consumeQty;
                    $head['remaining'] -= $consumeQty;

                    if ($head['remaining'] <= 0) {
                        array_shift($capitalQueue[$symbol]); // remove fully consumed buy
                    }
                }

                // Cross-day fallback: sell closes a position opened before this day.
                // Use the parent buy price from the SQL join when FIFO queue is empty.
                if ($remainingToClose > 0 && isset($event->buy_avg_price)) {
                    $released = $remainingToClose * (float) $event->buy_avg_price;
                    $totalReleased += $released;
                }

                $runningBalance -= $totalReleased;
                $posCount = max(0, $posCount - 1);

                $result[] = [
                    'time' => $eventTime->format('h:i A'),
                    'event_type' => 'sell',
                    'symbol' => $symbol,
                    'qty' => $sellQty,
                    'price' => $sellPrice,
                    'capital' => round($totalReleased, 2),
                    'total_invested' => round($runningBalance, 2),
                    'running_positions' => $posCount,
                    'ml_win_prob' => null,
                    'signal_type' => null,
                    'entry_type' => null,
                    'version' => null,
                    'exit_reason' => $event->exit_reason,
                ];
            }
        }

        // Calculate P&L per position (pair buys with sells)
        $positionPnls = $this->calculatePositionPnlsForDay($date);

        // Add P&L to sell events
        foreach ($result as &$row) {
            $buyId = $row['symbol'].'|'.($row['price'] ?? '');
            if ($row['event_type'] === 'sell' && isset($positionPnls[$row['symbol']])) {
                $row['pnl_dollar'] = $positionPnls[$row['symbol']]['pnl_dollar'] ?? null;
                $row['pnl_percent'] = $positionPnls[$row['symbol']]['pnl_percent'] ?? null;
            } elseif ($row['event_type'] === 'sell') {
                $row['pnl_dollar'] = null;
                $row['pnl_percent'] = null;
            } else {
                $row['pnl_dollar'] = null;
                $row['pnl_percent'] = null;
            }
        }
        unset($row);

        $totalPL = collect($result)->sum('pnl_dollar') ?? 0;

        return response()->json([
            'events' => $result,
            'total_pl' => round($totalPL, 2),
        ]);
    }

    /**
     * Calculate P&L per symbol for the day.
     *
     * @return array<string, array{pnl_dollar: float, pnl_percent: float}>
     */
    private function calculatePositionPnlsForDay(string $date): array
    {
        $buys = AlpacaOrder::with('tradeAlert:id,ml_win_prob,version,signal_type,entry_type')
            ->where('side', 'buy')
            ->where('status', 'filled')
            // is_paper filter removed
            ->where('filled_qty', '>', 0)
            ->where('filled_avg_price', '>', 0)
            ->whereRaw("DATE(CONVERT_TZ(filled_at, '+00:00', 'America/New_York')) = ?", [$date])
            ->orderBy('filled_at')
            ->get();

        $parentIds = $buys->pluck('alpaca_order_id')->filter()->values();

        $sellsByParentId = AlpacaOrder::where('side', 'sell')
            ->where('status', 'filled')
            // is_paper filter removed
            ->whereIn('parent_alpaca_order_id', $parentIds)
            ->get()
            ->keyBy('parent_alpaca_order_id');

        $pnls = [];
        foreach ($buys as $buy) {
            $sell = $sellsByParentId->get($buy->alpaca_order_id);
            if ($sell) {
                $qty = (float) $buy->filled_qty;
                $buyPrice = (float) $buy->filled_avg_price;
                $sellPrice = (float) $sell->filled_avg_price;
                $pnlDollar = round(($sellPrice - $buyPrice) * $qty, 2);
                $pnlPercent = $buyPrice > 0 ? round(($sellPrice - $buyPrice) / $buyPrice * 100, 2) : 0;

                $key = $buy->symbol;
                if (! isset($pnls[$key])) {
                    $pnls[$key] = ['pnl_dollar' => 0, 'pnl_percent' => 0, 'total_cost' => 0];
                }
                $pnls[$key]['pnl_dollar'] += $pnlDollar;
                $pnls[$key]['total_cost'] += $qty * $buyPrice;
            }
        }

        return collect($pnls)->mapWithKeys(fn ($p, $symbol) => [
            $symbol => [
                'pnl_dollar' => $p['pnl_dollar'],
                'pnl_percent' => $p['total_cost'] > 0 ? round($p['pnl_dollar'] / $p['total_cost'] * 100, 2) : 0,
            ],
        ])->all();
    }
}
