<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Request;

class DisclaimerAcceptance extends Model
{
    protected $fillable = [
        'ip_address',
        'user_agent',
        'disclaimer_accepted',
        'cookies_accepted',
        'disclaimer_accepted_at',
        'cookies_accepted_at',
        'last_access_at',
        'access_count',
        'root_page_visits',
        'first_visit_at',
        'time_threshold_triggered',
        'time_threshold_triggered_at',
    ];

    protected function casts(): array
    {
        return [
            'disclaimer_accepted' => 'boolean',
            'cookies_accepted' => 'boolean',
            'disclaimer_accepted_at' => 'datetime',
            'cookies_accepted_at' => 'datetime',
            'last_access_at' => 'datetime',
            'first_visit_at' => 'datetime',
            'time_threshold_triggered' => 'boolean',
            'time_threshold_triggered_at' => 'datetime',
        ];
    }

    /**
     * Check if IP address has accepted both disclaimer and cookies
     */
    public static function hasAcceptedAll(string $ipAddress): bool
    {
        return static::where('ip_address', $ipAddress)
            ->where('disclaimer_accepted', true)
            ->where('cookies_accepted', true)
            ->exists();
    }

    /**
     * Record disclaimer acceptance for IP address
     */
    public static function recordAcceptance(string $ipAddress, ?string $userAgent = null): void
    {
        static::updateOrCreate(
            ['ip_address' => $ipAddress],
            [
                'user_agent' => $userAgent,
                'disclaimer_accepted' => true,
                'cookies_accepted' => true,
                'disclaimer_accepted_at' => now(),
                'cookies_accepted_at' => now(),
                'last_access_at' => now(),
                'access_count' => \DB::raw('access_count + 1'),
            ]
        );
    }

    /**
     * Update last access for IP address
     */
    public static function updateLastAccess(string $ipAddress): void
    {
        static::where('ip_address', $ipAddress)
            ->update([
                'last_access_at' => now(),
                'access_count' => \DB::raw('access_count + 1'),
            ]);
    }

    /**
     * Get current user's IP address
     */
    public static function getCurrentIpAddress(): string
    {
        return Request::ip() ?? '127.0.0.1';
    }

    /**
     * Check if user should be shown disclaimer based on visit count and time threshold
     */
    public static function shouldShowDisclaimer(string $ipAddress): bool
    {
        // If already accepted, don't show
        if (static::hasAcceptedAll($ipAddress)) {
            return false;
        }

        $record = static::where('ip_address', $ipAddress)->first();

        // If no record exists, don't show disclaimer (record will be created by incrementPageVisit)
        if (! $record) {
            return false;
        }

        // If time threshold already triggered, show disclaimer
        if ($record->time_threshold_triggered) {
            return true;
        }

        // Check if 5 page visits reached
        if ($record->root_page_visits >= 5) {
            // Mark time threshold as triggered
            $record->update([
                'time_threshold_triggered' => true,
                'time_threshold_triggered_at' => now(),
            ]);

            return true;
        }

        // Check if 30 seconds have passed since first visit
        if ($record->first_visit_at && $record->first_visit_at->diffInSeconds(now()) >= 30) {
            // Mark time threshold as triggered
            $record->update([
                'time_threshold_triggered' => true,
                'time_threshold_triggered_at' => now(),
            ]);

            return true;
        }

        return false; // Don't show disclaimer yet
    }

    /**
     * Increment page visit count for any page visit
     */
    public static function incrementPageVisit(string $ipAddress): void
    {
        $record = static::where('ip_address', $ipAddress)->first();

        if (! $record) {
            // Create new record for first visit
            static::create([
                'ip_address' => $ipAddress,
                'user_agent' => Request::userAgent(),
                'first_visit_at' => now(),
                'root_page_visits' => 1,
                'last_access_at' => now(),
                'access_count' => 1,
            ]);
        } elseif (! static::hasAcceptedAll($ipAddress)) {
            // Increment for existing record if not accepted
            $record->update([
                'root_page_visits' => $record->root_page_visits + 1,
                'last_access_at' => now(),
                'access_count' => $record->access_count + 1,
            ]);
        }
    }
}
