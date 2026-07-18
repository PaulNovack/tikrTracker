<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PriceAlert extends Model
{
    /** @use HasFactory<\Database\Factories\PriceAlertFactory> */
    use HasFactory;

    protected $fillable = [
        'user_id',
        'asset_info_id',
        'base_price',
        'alert_type',
        'threshold_value',
        'above_price',
        'below_price',
        'enabled',
        'above_triggered',
        'below_triggered',
        'last_triggered_at',
        'up_percentage',
        'down_percentage',
        'up_enabled',
        'down_enabled',
    ];

    protected function casts(): array
    {
        return [
            'base_price' => 'decimal:2',
            'threshold_value' => 'decimal:2',
            'above_price' => 'decimal:2',
            'below_price' => 'decimal:2',
            'up_percentage' => 'decimal:2',
            'down_percentage' => 'decimal:2',
            'enabled' => 'boolean',
            'above_triggered' => 'boolean',
            'below_triggered' => 'boolean',
            'up_enabled' => 'boolean',
            'down_enabled' => 'boolean',
            'last_triggered_at' => 'datetime',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function asset(): BelongsTo
    {
        return $this->belongsTo(AssetInfo::class, 'asset_info_id');
    }

    /**
     * Calculate trigger prices based on alert type
     */
    public function calculateTriggerPrices(): void
    {
        // Use separate up/down percentages if available, otherwise use the legacy threshold
        $upPercent = $this->up_percentage ?? $this->threshold_value;
        $downPercent = $this->down_percentage ?? $this->threshold_value;

        if ($this->alert_type === 'fixed') {
            // For fixed alerts, use the threshold values directly
            $this->above_price = $this->base_price + $upPercent;
            $this->below_price = $this->base_price - $downPercent;
        } else {
            // For percentage alerts, calculate percentage change
            $upChange = ($this->base_price * $upPercent) / 100;
            $downChange = ($this->base_price * $downPercent) / 100;
            $this->above_price = $this->base_price + $upChange;
            $this->below_price = $this->base_price - $downChange;
        }
    }
}
