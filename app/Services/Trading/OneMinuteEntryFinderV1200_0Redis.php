<?php

namespace App\Services\Trading;

/**
 * Redis-backed version of the v1200.0 one-minute entry finder.
 */
class OneMinuteEntryFinderV1200_0Redis extends OneMinuteEntryFinderV1200_0
{
    use UsesRedisForEntryFinding;
}
