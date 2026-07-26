<?php

namespace App\Services\Trading;

/**
 * Redis-backed version of the v400.0 one-minute entry finder.
 */
class OneMinuteEntryFinderV400_0Redis extends OneMinuteEntryFinderV400_0
{
    use UsesRedisForEntryFinding;
}
