<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BuyWindowSignal extends Model
{
    protected $fillable = [
        'symbol',
        'asset_id',
        'signal_time',
        'asset_type',
        'score',
        'last_price',
        'range_pct',
        'pullback_pct',
        'volume_surge',
        'vwap',
        'ma10',
        'ma30',
        'reasons',
        'lookback_minutes',
        'is_optimal_time',
        'backtest_stop_price',
        'backtest_exit_price',
        'backtest_exit_type',
        'backtest_exit_time',
        'backtest_pl_dollars',
        'backtest_pl_pct',
    ];

    protected function casts(): array
    {
        return [
            'signal_time' => 'datetime',
            'score' => 'integer',
            'last_price' => 'decimal:4',
            'range_pct' => 'decimal:4',
            'pullback_pct' => 'decimal:4',
            'volume_surge' => 'decimal:2',
            'vwap' => 'decimal:4',
            'ma10' => 'decimal:4',
            'ma30' => 'decimal:4',
            'reasons' => 'array',
            'lookback_minutes' => 'integer',
            'is_optimal_time' => 'boolean',
            'backtest_stop_price' => 'decimal:4',
            'backtest_exit_price' => 'decimal:4',
            'backtest_exit_time' => 'datetime',
            'backtest_pl_dollars' => 'decimal:4',
            'backtest_pl_pct' => 'decimal:4',
        ];
    }

    public function asset(): BelongsTo
    {
        return $this->belongsTo(AssetInfo::class, 'asset_id');
    }
}
