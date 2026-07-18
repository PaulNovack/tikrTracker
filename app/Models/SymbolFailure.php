<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SymbolFailure extends Model
{
    use HasFactory;

    protected $fillable = [
        'symbol',
        'asset_type',
        'failure_type',
        'error_message',
        'consecutive_count',
        'first_failure_at',
        'last_failure_at',
        'auto_blacklisted',
        'blacklisted_at',
    ];

    protected $casts = [
        'first_failure_at' => 'datetime',
        'last_failure_at' => 'datetime',
        'blacklisted_at' => 'datetime',
        'auto_blacklisted' => 'boolean',
    ];

    public function assetInfo(): BelongsTo
    {
        return $this->belongsTo(AssetInfo::class, 'symbol', 'symbol')
            ->where('asset_type', $this->asset_type);
    }

    /**
     * Check if this symbol should be auto-blacklisted based on failure patterns
     */
    public function shouldAutoBlacklist(): bool
    {
        // Delisting detection - immediate blacklist
        if ($this->failure_type === 'delisted') {
            return true;
        }

        // Consecutive failures - blacklist after 10 failures in 7 days
        if ($this->consecutive_count >= 10 &&
            $this->first_failure_at->diffInDays(now()) <= 7) {
            return true;
        }

        return false;
    }

    /**
     * Get the appropriate blacklist reason based on failure type
     */
    public function getBlacklistReason(): string
    {
        return match ($this->failure_type) {
            'delisted' => 'Delisted - no data available',
            'consecutive_failures' => 'Consecutive failures - poor reliability',
            'no_data' => 'No data returned consistently',
            'data_quality' => 'Poor data quality - high error rate',
            default => 'Failed data collection - multiple issues',
        };
    }
}
