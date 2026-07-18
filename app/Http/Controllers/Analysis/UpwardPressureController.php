<?php

declare(strict_types=1);

namespace App\Http\Controllers\Analysis;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class UpwardPressureController extends Controller
{
    /**
     * Show stocks with the strongest upward pressure based on real-time
     * 1-minute bar analysis (body strength, close location, volume ratio,
     * VWAP distance, and 5-minute momentum).
     *
     * Supports ?filter=qualified (default) to show only stocks meeting
     * all quality thresholds, or ?filter=all to show everything scored.
     */
    public function index(Request $request): Response
    {
        $limit = min((int) $request->get('limit', 100), 500);
        $filter = $request->get('filter', 'qualified');

        $rows = DB::connection('mysql')->select('
            WITH base AS (
                SELECT
                    p.symbol,
                    p.ts_est,
                    p.open,
                    p.high,
                    p.low,
                    p.price,
                    p.volume,
                    p.vwap,

                    AVG(p.volume) OVER (
                        PARTITION BY p.symbol
                        ORDER BY p.ts_est
                        ROWS BETWEEN 20 PRECEDING AND 1 PRECEDING
                    ) AS avg_volume_20,

                    LAG(p.price, 5) OVER (
                        PARTITION BY p.symbol
                        ORDER BY p.ts_est
                    ) AS close_5m_ago
                FROM one_minute_prices p
                WHERE p.ts_est >= DATE_SUB(NOW(), INTERVAL 1 DAY)
            ),

            features AS (
                SELECT
                    symbol,
                    ts_est,
                    open,
                    high,
                    low,
                    price,
                    volume,
                    vwap,

                    (price - open) / NULLIF(open, 0) AS body_pct,

                    CASE
                        WHEN high > low THEN (price - low) / NULLIF(high - low, 0)
                        ELSE 0.5
                    END AS close_location,

                    volume / NULLIF(avg_volume_20, 0) AS volume_ratio,

                    (price - vwap) / NULLIF(vwap, 0) AS vwap_dist_pct,

                    (price - close_5m_ago) / NULLIF(close_5m_ago, 0) AS momentum_5m_pct

                FROM base
                WHERE avg_volume_20 IS NOT NULL
                  AND close_5m_ago IS NOT NULL
            ),

            scored AS (
                SELECT
                    symbol,
                    ts_est,
                    price,
                    volume,
                    vwap,

                    body_pct,
                    close_location,
                    volume_ratio,
                    vwap_dist_pct,
                    momentum_5m_pct,

                    (
                        20 * GREATEST(-1, LEAST(body_pct / 0.007, 1))
                        +
                        20 * ((close_location - 0.5) * 2)
                        +
                        20 * GREATEST(-1, LEAST((volume_ratio - 1) / 2, 1))
                        +
                        20 * GREATEST(-1, LEAST(vwap_dist_pct / 0.010, 1))
                        +
                        20 * GREATEST(-1, LEAST(momentum_5m_pct / 0.015, 1))
                    ) AS raw_pressure_score
                FROM features
            ),

            final_scored AS (
                SELECT
                    symbol,
                    ts_est,
                    price,
                    volume,
                    vwap,

                    body_pct,
                    close_location,
                    volume_ratio,
                    vwap_dist_pct,
                    momentum_5m_pct,

                    ROUND(
                        GREATEST(0, LEAST(100, 50 + raw_pressure_score / 2)),
                        1
                    ) AS upward_pressure_score,

                    CASE
                        WHEN vwap_dist_pct > 0.015 THEN 15
                        WHEN vwap_dist_pct > 0.010 THEN 8
                        ELSE 0
                    END AS stretch_penalty

                FROM scored
            ),

            with_penalty AS (
                SELECT
                    *,
                    GREATEST(0, upward_pressure_score - stretch_penalty) AS adjusted_score
                FROM final_scored
            ),

            with_lag AS (
                SELECT
                    *,
                    LAG(upward_pressure_score, 1) OVER (
                        PARTITION BY symbol
                        ORDER BY ts_est
                    ) AS prev_score_1,
                    LAG(upward_pressure_score, 2) OVER (
                        PARTITION BY symbol
                        ORDER BY ts_est
                    ) AS prev_score_2
                FROM with_penalty
            )

            SELECT
                wl.symbol,
                ai.id AS asset_info_id,
                wl.ts_est,
                wl.ts_est AS display_time,
                wl.price,
                wl.volume,
                wl.vwap,

                wl.body_pct,
                wl.close_location,
                wl.volume_ratio,
                wl.vwap_dist_pct,
                wl.momentum_5m_pct,
                wl.upward_pressure_score,
                wl.stretch_penalty,
                wl.adjusted_score,
                wl.prev_score_1,
                wl.prev_score_2,

                CASE
                    WHEN wl.prev_score_1 IS NOT NULL
                         AND wl.prev_score_2 IS NOT NULL
                         AND wl.upward_pressure_score > wl.prev_score_1
                         AND wl.prev_score_1 > wl.prev_score_2
                    THEN 1
                    ELSE 0
                END AS is_rising

            FROM with_lag wl
            LEFT JOIN asset_info ai ON ai.symbol = wl.symbol AND ai.asset_type = \'stock\'
            ORDER BY wl.upward_pressure_score DESC
            LIMIT ?
        ', [$limit]);

        $stocks = array_map(function ($row) {
            return [
                'symbol' => $row->symbol,
                'asset_info_id' => $row->asset_info_id ? (int) $row->asset_info_id : null,
                'ts_est' => $row->ts_est,
                'close' => (float) $row->price,
                'volume' => (int) $row->volume,
                'vwap' => (float) $row->vwap,
                'body_pct' => (float) $row->body_pct,
                'close_location' => (float) $row->close_location,
                'volume_ratio' => (float) $row->volume_ratio,
                'vwap_dist_pct' => (float) $row->vwap_dist_pct,
                'momentum_5m_pct' => (float) $row->momentum_5m_pct,
                'upward_pressure_score' => (float) $row->upward_pressure_score,
                'stretch_penalty' => (int) $row->stretch_penalty,
                'adjusted_score' => (float) $row->adjusted_score,
                'prev_score_1' => $row->prev_score_1 !== null ? (float) $row->prev_score_1 : null,
                'prev_score_2' => $row->prev_score_2 !== null ? (float) $row->prev_score_2 : null,
                'is_rising' => (bool) $row->is_rising,
            ];
        }, $rows);

        // Apply quality filter (only for the "qualified" view)
        if ($filter === 'qualified') {
            $stocks = array_values(array_filter($stocks, fn ($s) => (
                $s['upward_pressure_score'] >= 65
                && $s['volume_ratio'] >= 1.5
                && $s['close'] > $s['vwap']
                && $s['close_location'] >= 0.65
                && $s['vwap_dist_pct'] >= 0.001
                && $s['vwap_dist_pct'] <= 0.012
            )));
        }

        // Sort by adjusted_score descending for qualified view, raw score for all
        $sortKey = $filter === 'qualified' ? 'adjusted_score' : 'upward_pressure_score';
        usort($stocks, fn (array $a, array $b) => $b[$sortKey] <=> $a[$sortKey]);

        $total = count($stocks);
        $qualified = $filter === 'qualified' ? $total : count(array_filter($stocks, fn ($s) => (
            $s['upward_pressure_score'] >= 65
            && $s['volume_ratio'] >= 1.5
            && $s['close'] > $s['vwap']
            && $s['close_location'] >= 0.65
            && $s['vwap_dist_pct'] >= 0.001
            && $s['vwap_dist_pct'] <= 0.012
        )));

        return Inertia::render('analysis/upward-pressure/index', [
            'stocks' => $stocks,
            'totalSymbols' => $total,
            'qualifiedCount' => $qualified,
            'activeFilter' => $filter,
        ]);
    }
}
