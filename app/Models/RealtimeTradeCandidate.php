<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RealtimeTradeCandidate extends Model
{
    protected $fillable = [
        'symbol',
        'asset_type',
        'detected_ts_est',
        'detected_price',
        'bid',
        'ask',
        'bid_qty',
        'ask_qty',
        'spread_pct',
        'partial_open',
        'partial_high',
        'partial_low',
        'partial_close',
        'partial_volume',
        'vwap',
        'vwap_dist_pct',
        'return_1m_pct',
        'return_3m_pct',
        'volume_ratio',
        'dollar_volume_1m',
        'bid_ask_imbalance',
        'atr_pct',
        'rvol',
        'move_30m_pct',
        'ema9_above_ema21',
        'early_score',
        'status',
        'stale_seconds',
        'rejection_reason',
        'trade_alert_id',
    ];

    protected $casts = [
        'detected_ts_est' => 'datetime',
        'detected_price' => 'float',
        'bid' => 'float',
        'ask' => 'float',
        'spread_pct' => 'float',
        'partial_open' => 'float',
        'partial_high' => 'float',
        'partial_low' => 'float',
        'partial_close' => 'float',
        'partial_volume' => 'integer',
        'vwap' => 'float',
        'vwap_dist_pct' => 'float',
        'return_1m_pct' => 'float',
        'return_3m_pct' => 'float',
        'volume_ratio' => 'float',
        'dollar_volume_1m' => 'float',
        'bid_ask_imbalance' => 'float',
        'atr_pct' => 'float',
        'rvol' => 'float',
        'move_30m_pct' => 'float',
        'ema9_above_ema21' => 'integer',
        'early_score' => 'float',
    ];
}
