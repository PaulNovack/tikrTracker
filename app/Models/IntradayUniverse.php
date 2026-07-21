<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property string $symbol
 * @property string $asset_type
 * @property float|null $universe_score
 * @property int $days_seen
 * @property float|null $total_1m_bars
 * @property float|null $avg_bars_per_day
 * @property float|null $avg_price
 * @property float|null $avg_daily_dollar_volume
 * @property float|null $avg_dollar_volume_1m
 * @property float|null $max_dollar_volume_1m
 * @property float|null $avg_volume_1m
 * @property float|null $avg_atr_pct
 * @property float|null $avg_range_1m_pct
 * @property float|null $max_range_1m_pct
 * @property float|null $avg_liquid_minutes_25k_per_day
 * @property float|null $avg_liquid_minutes_50k_per_day
 * @property float|null $avg_liquid_minutes_100k_per_day
 * @property float|null $days_avg_1m_dollar_vol_over_25k
 * @property float|null $days_avg_1m_dollar_vol_over_50k
 * @property float|null $avg_above_vwap_ratio
 * @property float|null $avg_ema_bull_ratio
 */
class IntradayUniverse extends Model
{
    protected $table = 'intraday_universe';

    public $timestamps = false;

    public $incrementing = false;

    protected $primaryKey = null;

    protected $fillable = [
        'symbol',
        'asset_type',
        'universe_score',
        'days_seen',
        'total_1m_bars',
        'avg_bars_per_day',
        'avg_price',
        'avg_daily_dollar_volume',
        'avg_dollar_volume_1m',
        'max_dollar_volume_1m',
        'avg_volume_1m',
        'avg_atr_pct',
        'avg_range_1m_pct',
        'max_range_1m_pct',
        'avg_liquid_minutes_25k_per_day',
        'avg_liquid_minutes_50k_per_day',
        'avg_liquid_minutes_100k_per_day',
        'days_avg_1m_dollar_vol_over_25k',
        'days_avg_1m_dollar_vol_over_50k',
        'avg_above_vwap_ratio',
        'avg_ema_bull_ratio',
    ];
}
