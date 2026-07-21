<?php

namespace App\Http\Controllers;

use App\Models\RealtimeTradeCandidate;
use Inertia\Inertia;
use Inertia\Response;

class RealtimeAlertController extends Controller
{
    public function index(): Response
    {
        $symbol = request('symbol');
        $status = request('status');

        $candidates = RealtimeTradeCandidate::query()
            ->when($symbol, fn ($query) => $query->where('symbol', 'like', '%'.$symbol.'%'))
            ->when($status && $status !== 'all', fn ($query) => $query->where('status', $status))
            ->latest('id')
            ->limit(500)
            ->get()
            ->map(fn (RealtimeTradeCandidate $c) => [
                'id' => $c->id,
                'symbol' => $c->symbol,
                'asset_type' => $c->asset_type,
                'detected_ts_est' => $c->getRawOriginal('detected_ts_est')
                    ? \Carbon\Carbon::parse($c->getRawOriginal('detected_ts_est'), 'America/New_York')->toIso8601String()
                    : null,
                'detected_price' => $c->detected_price,
                'bid' => $c->bid,
                'ask' => $c->ask,
                'bid_qty' => $c->bid_qty,
                'ask_qty' => $c->ask_qty,
                'spread_pct' => $c->spread_pct,
                'partial_open' => $c->partial_open,
                'partial_high' => $c->partial_high,
                'partial_low' => $c->partial_low,
                'partial_close' => $c->partial_close,
                'partial_volume' => $c->partial_volume,
                'vwap' => $c->vwap,
                'vwap_dist_pct' => $c->vwap_dist_pct,
                'return_1m_pct' => $c->return_1m_pct,
                'return_3m_pct' => $c->return_3m_pct,
                'volume_ratio' => $c->volume_ratio,
                'dollar_volume_1m' => $c->dollar_volume_1m,
                'bid_ask_imbalance' => $c->bid_ask_imbalance,
                'early_score' => $c->early_score,
                'status' => $c->status,
                'stale_seconds' => $c->stale_seconds,
                'rejection_reason' => $c->rejection_reason,
                'last_gate_fail_reason' => $c->last_gate_fail_reason,
                'trade_alert_id' => $c->trade_alert_id,
            ]);

        return Inertia::render('RealtimeAlerts/index', [
            'candidates' => $candidates,
        ]);
    }
}
