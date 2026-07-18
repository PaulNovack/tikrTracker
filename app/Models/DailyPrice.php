<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DailyPrice extends Model
{
    use HasFactory;

    protected $fillable = [
        'symbol',
        'asset_type',
        'date',
        'price',
        'open',
        'high',
        'low',
        'volume',
    ];

    protected $appends = [
        'price_change',
        'price_change_percent',
    ];

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'price' => 'decimal:8',
            'open' => 'decimal:8',
            'high' => 'decimal:8',
            'low' => 'decimal:8',
            'volume' => 'integer',
        ];
    }

    public static function booted(): void
    {
        // Invalidate asset cache when daily price is created or updated
        static::created(function ($model) {
            AssetInfo::invalidateCachesBySymbolAndType($model->symbol, $model->asset_type);
        });

        static::updated(function ($model) {
            AssetInfo::invalidateCachesBySymbolAndType($model->symbol, $model->asset_type);
        });
    }

    public function assetInfo(): BelongsTo
    {
        return $this->belongsTo(AssetInfo::class, 'symbol', 'symbol')
            ->where('asset_type', $this->asset_type);
    }

    /**
     * Scope to filter for Monday prices only
     * NOTE: Caching now handles filtering to Monday prices, so this scope no longer applies the filter.
     * Kept for backward compatibility and reference.
     */
    public function scopeMondayOnly(Builder $query): Builder
    {
        $driver = $query->getConnection()->getDriverName();

        if ($driver === 'sqlite') {
            // SQLite: use CAST and strftime to get weekday (0=Sunday, 1=Monday, etc.)
            // return $query->whereRaw("strftime('%w', date) = '1'");
        }

        // MySQL: WEEKDAY returns 0 for Monday, 1 for Tuesday, etc.
        // return $query->whereRaw('WEEKDAY(date) = 0');

        return $query;
    }

    public function getPriceChangeAttribute(): ?float
    {
        if (! $this->open || ! $this->price) {
            return null;
        }

        return (float) ($this->price - $this->open);
    }

    public function getPriceChangePercentAttribute(): ?float
    {
        if (! $this->open || $this->open == 0) {
            return null;
        }

        return (float) ((($this->price - $this->open) / $this->open) * 100);
    }
}
