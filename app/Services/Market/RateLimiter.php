<?php

namespace App\Services\Market;

class RateLimiter
{
    private int $limitPerMinute;

    private ?float $windowStart = null;

    private int $callsInWindow = 0;

    private ?float $lastCallTime = null;

    private float $minInterval;

    public function __construct(int $limitPerMinute)
    {
        $this->limitPerMinute = max(1, $limitPerMinute);
        $this->minInterval = 60.0 / $this->limitPerMinute;
    }

    public function beforeCall(): void
    {
        $now = microtime(true);

        // Smooth spacing between calls
        if ($this->lastCallTime !== null) {
            $sinceLast = $now - $this->lastCallTime;
            if ($sinceLast < $this->minInterval) {
                $sleepSeconds = $this->minInterval - $sinceLast;
                echo 'Throttling: sleeping '.round($sleepSeconds, 2)."s before next call...\n";
                usleep((int) ($sleepSeconds * 1_000_000));
                $now = microtime(true);
            }
        }

        // 60-second rolling window
        if ($this->windowStart === null) {
            $this->windowStart = $now;
            $this->callsInWindow = 0;
        }

        $elapsed = $now - $this->windowStart;

        if ($elapsed >= 60.0) {
            $this->windowStart = $now;
            $this->callsInWindow = 0;
        }

        if ($this->callsInWindow >= $this->limitPerMinute) {
            $sleepSeconds = 60.0 - $elapsed + 0.2;
            if ($sleepSeconds > 0) {
                echo 'Rate limit window full, sleeping for '.round($sleepSeconds, 2)."s...\n";
                usleep((int) ($sleepSeconds * 1_000_000));
            }
            $this->windowStart = microtime(true);
            $this->callsInWindow = 0;
        }

        $this->callsInWindow++;
        $this->lastCallTime = microtime(true);
    }
}
