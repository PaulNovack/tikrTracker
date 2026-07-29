<?php

namespace App\Services\Trading;

/**
 * Redis-backed version of the v101.0 one-minute entry finder.
 */
class OneMinuteEntryFinderV101_0Redis extends OneMinuteEntryFinderV101_0
{
    use UsesRedisForEntryFinding;
}
