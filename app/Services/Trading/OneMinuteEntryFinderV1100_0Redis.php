<?php

namespace App\Services\Trading;

/**
 * Redis-backed version of the v1100.0 one-minute entry finder.
 */
class OneMinuteEntryFinderV1100_0Redis extends OneMinuteEntryFinderV1100_0
{
    use UsesRedisForEntryFinding;
}
