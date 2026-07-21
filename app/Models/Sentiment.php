<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Sentiment extends Model
{
    protected $fillable = [
        'symbol',
        'sentiment_text',
        'sentiment_type',
        'confidence_score',
        'sentiment_date',
    ];

    protected function casts(): array
    {
        return [
            'sentiment_date' => 'date',
            'confidence_score' => 'decimal:2',
        ];
    }

    /**
     * Get the asset info for this sentiment
     */
    public function asset(): BelongsTo
    {
        return $this->belongsTo(AssetInfo::class, 'symbol', 'symbol');
    }

    /**
     * Scope to get sentiments for a specific date
     */
    public function scopeForDate($query, Carbon $date)
    {
        return $query->where('sentiment_date', $date->toDateString());
    }

    /**
     * Scope to get sentiments for today
     */
    public function scopeToday($query)
    {
        return $query->where('sentiment_date', today());
    }

    /**
     * Scope to get sentiments by type
     */
    public function scopeOfType($query, string $type)
    {
        return $query->where('sentiment_type', $type);
    }

    /**
     * Scope to get recent sentiments
     */
    public function scopeRecent($query, int $days = 7)
    {
        return $query->where('sentiment_date', '>=', today()->subDays($days));
    }
}
