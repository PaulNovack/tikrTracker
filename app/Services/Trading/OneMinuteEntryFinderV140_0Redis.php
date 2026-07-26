<?php

namespace App\Services\Trading;

/**
 * Redis-backed version of the v140.0 one-minute entry finder.
 */
class OneMinuteEntryFinderV140_0Redis extends OneMinuteEntryFinderV140_0
{
    use UsesRedisForEntryFinding;
}
