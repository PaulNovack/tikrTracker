<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class MarketSchedule extends Model
{
    use HasFactory;

    protected $table = 'market_schedules';

    protected $fillable = [
        'date',
        'market_type',
        'status',
        'reason',
        'opens_at',
        'closes_at',
        'is_early_close',
    ];

    protected $casts = [
        'date' => 'date',
        'opens_at' => 'datetime:H:i:s',
        'closes_at' => 'datetime:H:i:s',
        'is_early_close' => 'boolean',
    ];

    /**
     * Scope to filter by market type
     */
    public function scopeByMarketType($query, string $marketType)
    {
        return $query->where('market_type', $marketType);
    }

    /**
     * Scope to filter by status
     */
    public function scopeByStatus($query, string $status)
    {
        return $query->where('status', $status);
    }

    /**
     * Scope to get open markets on a specific date
     */
    public function scopeOpenOn($query, $date = null)
    {
        $date = $date ?? now()->toDateString();

        return $query->where('date', $date)
            ->whereIn('status', ['open', 'half_day']);
    }

    /**
     * Scope to get closed markets on a specific date
     */
    public function scopeClosedOn($query, $date = null)
    {
        $date = $date ?? now()->toDateString();

        return $query->where('date', $date)
            ->where('status', 'closed');
    }

    /**
     * Scope to get markets within date range
     */
    public function scopeBetween($query, $startDate, $endDate)
    {
        return $query->whereBetween('date', [$startDate, $endDate]);
    }

    /**
     * Check if market is open
     */
    public function isOpen(): bool
    {
        return in_array($this->status, ['open', 'half_day']);
    }

    /**
     * Check if market is closed
     */
    public function isClosed(): bool
    {
        return $this->status === 'closed';
    }

    /**
     * Check if market is on holiday
     */
    public function isHoliday(): bool
    {
        return $this->status === 'holiday';
    }

    /**
     * Check if market has early close
     */
    public function isEarlyClose(): bool
    {
        return $this->is_early_close;
    }
}
