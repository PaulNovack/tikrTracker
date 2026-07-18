<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MarketMover extends Model
{
    protected $fillable = [
        'trading_date',
        'bars_4pct_plus',
        'bars_5pct_plus',
        'bars_10pct_plus',
        'max_gain',
        'strength',
        'label',
        'movers',
    ];

    protected function casts(): array
    {
        return [
            'trading_date' => 'date',
            'movers' => 'array',
        ];
    }
}
