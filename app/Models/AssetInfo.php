<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Cache;

class AssetInfo extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'asset_info';

    protected $fillable = [
        'symbol',
        'asset_type',
        'common_name',
        'description',
        'sector',
        'reason_for_delete',
    ];

    public function dailyPrices(): HasMany
    {
        return $this->hasMany(DailyPrice::class, 'symbol', 'symbol')
            ->where('asset_type', $this->asset_type);
    }

    public function hourlyPrices(): HasMany
    {
        return $this->hasMany(HourlyPrice::class, 'symbol', 'symbol')
            ->where('asset_type', $this->asset_type);
    }

    public function fiveMinutePrices(): HasMany
    {
        return $this->hasMany(FiveMinutePrice::class, 'symbol', 'symbol')
            ->where('asset_type', $this->asset_type);
    }

    public function oneMinutePrices(): HasMany
    {
        return $this->hasMany(OneMinutePrice::class, 'symbol', 'symbol')
            ->where('asset_type', $this->asset_type);
    }

    public function isStock(): bool
    {
        return $this->asset_type === 'stock';
    }

    public function isCrypto(): bool
    {
        return $this->asset_type === 'crypto';
    }

    /**
     * Invalidate all caches for this asset
     */
    public function invalidateAssetCaches(): void
    {
        $cacheKeys = [
            "asset-latest-price:{$this->id}:{$this->symbol}:{$this->asset_type}",
            "asset-chart-data:{$this->id}:{$this->symbol}:{$this->asset_type}",
            "asset-daily-prices:{$this->id}:{$this->symbol}:{$this->asset_type}",
        ];

        foreach ($cacheKeys as $key) {
            Cache::forget($key);
        }
    }

    /**
     * Invalidate caches for an asset by symbol and type
     */
    public static function invalidateCachesBySymbolAndType(string $symbol, string $assetType): void
    {
        // Find the asset to get its ID for proper cache key
        $asset = static::where('symbol', $symbol)
            ->where('asset_type', $assetType)
            ->first();

        if ($asset) {
            $asset->invalidateAssetCaches();
        }
    }
}
