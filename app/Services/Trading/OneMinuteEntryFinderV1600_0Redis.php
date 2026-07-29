<?php

namespace App\Services\Trading;

/**
 * Redis-backed version of the v1600.0 one-minute entry finder.
 */
class OneMinuteEntryFinderV1600_0Redis extends OneMinuteEntryFinderV1600_0
{
    use UsesRedisForEntryFinding;
}
