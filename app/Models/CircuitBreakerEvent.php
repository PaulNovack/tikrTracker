<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CircuitBreakerEvent extends Model
{
    protected $fillable = [
        'symbol',
        'losing_stops_count',
        'window_minutes',
        'pause_minutes',
        'tripped_at',
        'pause_expires_at',
        'is_paper',
    ];

    protected function casts(): array
    {
        return [
            'tripped_at' => 'datetime',
            'pause_expires_at' => 'datetime',
            'is_paper' => 'boolean',
        ];
    }

    public function isActive(): bool
    {
        return now()->lessThan($this->pause_expires_at);
    }
}
