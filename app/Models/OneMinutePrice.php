<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OneMinutePrice extends Model
{
    use HasFactory;

    protected $table = 'one_minute_prices';

    protected $fillable = [
        'symbol',
        'asset_type',
        'ts',
        'price',
        'open',
        'high',
        'low',
        'volume',
        'ts_est',
        'trading_date_est',
        'trading_time_est',
    ];

    public function casts(): array
    {
        return [
            'ts' => 'datetime',
            'ts_est' => 'datetime',
            'price' => 'decimal:4',
            'open' => 'decimal:4',
            'high' => 'decimal:4',
            'low' => 'decimal:4',
            'volume' => 'integer',
            'trading_date_est' => 'date',
            'trading_time_est' => 'datetime',
        ];
    }

    public function assetInfo(): BelongsTo
    {
        return $this->belongsTo(AssetInfo::class, 'symbol', 'symbol');
    }
}
