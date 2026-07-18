<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WebullToken extends Model
{
    protected $fillable = [
        'environment',
        'token',
        'status',
        'expires_at',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
    ];

    /**
     * Check if the token is expired
     */
    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }

    /**
     * Check if the token needs refresh (within 24 hours of expiration)
     */
    public function needsRefresh(): bool
    {
        return $this->expires_at->subDay()->isPast();
    }

    /**
     * Get the active token for the current environment
     */
    public static function getActiveToken(): ?self
    {
        $mode = strtoupper(config('webull.mode', 'DEV'));

        return static::where('environment', $mode)
            ->where('status', 'NORMAL')
            ->where('expires_at', '>', now())
            ->first();
    }
}
