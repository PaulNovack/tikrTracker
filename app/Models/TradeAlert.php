<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TradeAlert extends Model
{
    protected $table = 'trade_alerts';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'trading_date_est' => 'date',
            'as_of_ts_est' => 'datetime',
            'signal_ts_est' => 'datetime',
            'entry_ts_est' => 'datetime',
            'exit_ts_est' => 'datetime',
            'entry' => 'decimal:2',
            'stop' => 'decimal:2',
            'exit_price' => 'decimal:2',
            'calculated_position_size' => 'decimal:2',
            'ml_win_prob' => 'decimal:3',
            'pnl_percent' => 'decimal:2',
            'pnl_dollar' => 'decimal:2',
            'analyzed' => 'boolean',
            'analyzed_at' => 'datetime',
            'ml_scored_at' => 'datetime',
            'meta' => 'array',
            'targets' => 'array',
        ];
    }
}
