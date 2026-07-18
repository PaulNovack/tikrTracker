<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class HourlyPrice extends Model
{
    use HasFactory;

    protected $fillable = [
        'symbol',
        'asset_type',
        'ts',
        'price',
        'volume',
    ];

    protected function casts(): array
    {
        return [
            'ts' => 'datetime',
            'price' => 'decimal:8',
            'volume' => 'integer',
        ];
    }

    public function assetInfo(): BelongsTo
    {
        return $this->belongsTo(AssetInfo::class, 'symbol', 'symbol')
            ->where('asset_type', $this->asset_type);
    }

    public static function booted(): void
    {
        // Invalidate asset cache when hourly price is created or updated
        static::created(function ($model) {
            AssetInfo::invalidateCachesBySymbolAndType($model->symbol, $model->asset_type);
        });

        static::updated(function ($model) {
            AssetInfo::invalidateCachesBySymbolAndType($model->symbol, $model->asset_type);
        });
    }
}
