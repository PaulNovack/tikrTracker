<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AlpacaOrder extends Model
{
    use HasFactory;

    protected $connection = 'mysql';

    protected $fillable = [
        'trade_alert_id',
        'alpaca_order_id',
        'client_order_id',
        'account_id',
        'paper',
        'is_paper',
        'symbol',
        'asset_class',
        'order_class',
        'order_type',
        'side',
        'time_in_force',
        'qty',
        'notional',
        'filled_qty',
        'filled_avg_price',
        'limit_price',
        'stop_price',
        'trail_price',
        'trail_percent',
        'status',
        'submitted_at',
        'created_at',
        'updated_at',
        'filled_at',
        'canceled_at',
        'expired_at',
        'replaces_alpaca_order_id',
        'replaced_by_alpaca_order_id',
        'parent_alpaca_order_id',
        'position_intent',
        'extended_hours',
        'raw_json',
        'notes',
        'inserted_at',
        'last_seen_at',
        'atr',
        'atr_pct',
    ];

    protected function casts(): array
    {
        return [
            'qty' => 'decimal:6',
            'notional' => 'decimal:6',
            'filled_qty' => 'decimal:6',
            'filled_avg_price' => 'decimal:6',
            'limit_price' => 'decimal:6',
            'stop_price' => 'decimal:6',
            'trail_price' => 'decimal:6',
            'trail_percent' => 'decimal:4',
            'atr' => 'decimal:6',
            'atr_pct' => 'decimal:6',
            'paper' => 'boolean',
            'is_paper' => 'boolean',
            'extended_hours' => 'boolean',
            'raw_json' => 'array',
            'submitted_at' => 'datetime',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
            'filled_at' => 'datetime',
            'canceled_at' => 'datetime',
            'expired_at' => 'datetime',
            'inserted_at' => 'datetime',
            'last_seen_at' => 'datetime',
        ];
    }

    /**
     * Get the stop loss order for this buy order.
     *
     * A stop loss order has this order's alpaca_order_id as its parent_alpaca_order_id.
     */
    public function stopLossOrder()
    {
        return $this->hasOne(AlpacaOrder::class, 'parent_alpaca_order_id', 'alpaca_order_id')
            ->where('side', 'sell')
            ->whereIn('order_type', ['stop', 'stop_limit']);
    }

    /**
     * Get the parent order (for stop loss orders).
     */
    public function parentOrder()
    {
        return $this->belongsTo(AlpacaOrder::class, 'parent_alpaca_order_id', 'alpaca_order_id');
    }

    /**
     * Get the trade alert that triggered this order.
     */
    public function tradeAlert()
    {
        return $this->belongsTo(TradeAlert::class, 'trade_alert_id');
    }
}
