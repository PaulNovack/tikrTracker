<?php

namespace App\Services\Trading;

/**
 * Redis-backed version of the v2000.0 one-minute entry finder.
 */
class OneMinuteEntryFinderV2000_0Redis extends OneMinuteEntryFinderV2000_0
{
    use UsesRedisForEntryFinding;
}
